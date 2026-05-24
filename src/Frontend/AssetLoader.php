<?php
/**
 * AssetLoader — enqueues the public runtime bundle on the frontend.
 *
 * Mirrors MenuRegistrar's pattern: reads assets/dist/.vite/manifest.json,
 * loads CSS + ES module via the same script_loader_tag rewrite. A
 * `HOF_VITE_DEV` define swaps to the Vite dev server for HMR.
 *
 * Inline-injects window.hofPublic (REST URL, nonce, applied filter state)
 * before the module loads.
 *
 * @package HookedOnFacets
 */

declare(strict_types=1);

namespace HookedOnFacets\Frontend;

use HookedOnFacets\Contracts\Bootable;
use HookedOnFacets\Filter\Resolver;

defined( 'ABSPATH' ) || exit;

final class AssetLoader implements Bootable {

    private const ENTRY = 'public/src/main.js';

    private const MODULE_HANDLES = [ 'hof-public-main', 'hof-vite-public-client' ];

    public function register_hooks(): void {
        add_action( 'wp_enqueue_scripts',  [ $this, 'enqueue' ] );
        add_filter( 'script_loader_tag',   [ $this, 'script_as_module' ], 10, 3 );
        // Priority 20 — must run AFTER wp_print_styles (default 8) so the
        // inline <style> block follows the bundled stylesheet in source
        // order. CSS cascade: equal specificity, later wins → the filter's
        // user-provided values override the bundle's defaults. At p5 (the
        // previous value) the bundle was overwriting filter values, making
        // hof_public_css_tokens effectively dead for any token the bundle
        // also defined.
        add_action( 'wp_head',             [ $this, 'print_token_block' ], 20 );
    }

    public function enqueue(): void {
        $inline = sprintf(
            'window.hofPublic = %s;',
            wp_json_encode( [
                'restUrl' => esc_url_raw( rest_url( 'hof/v1/' ) ),
                'nonce'   => wp_create_nonce( 'wp_rest' ),
                'state'   => Resolver::parse_request_filters(),
            ] )
        );

        if ( $this->is_vite_dev() ) {
            $base = $this->vite_dev_url();

            wp_enqueue_script( 'hof-vite-public-client', $base . '/@vite/client', [], null, true );

            wp_register_script(
                'hof-public-main',
                $base . '/' . self::ENTRY,
                [ 'hof-vite-public-client' ],
                null,
                true
            );
            wp_add_inline_script( 'hof-public-main', $inline, 'before' );
            wp_enqueue_script( 'hof-public-main' );
            return;
        }

        $manifest = $this->read_manifest();
        if ( ! $manifest || ! isset( $manifest[ self::ENTRY ] ) ) {
            // Frontend should never spam admin notices — fail silently.
            return;
        }

        $entry = $manifest[ self::ENTRY ];

        wp_register_script(
            'hof-public-main',
            HOF_PLUGIN_URL . 'assets/dist/' . $entry['file'],
            [],
            HOF_VERSION,
            true
        );
        wp_add_inline_script( 'hof-public-main', $inline, 'before' );
        wp_enqueue_script( 'hof-public-main' );

        $seen = [];
        $css_enqueue = static function ( string $css_path ) use ( &$seen ) {
            if ( isset( $seen[ $css_path ] ) ) return;
            $seen[ $css_path ] = true;
            wp_enqueue_style(
                'hof-public-' . md5( $css_path ),
                HOF_PLUGIN_URL . 'assets/dist/' . $css_path,
                [],
                HOF_VERSION
            );
        };
        foreach ( $entry['css'] ?? [] as $css ) {
            $css_enqueue( $css );
        }
        foreach ( $entry['imports'] ?? [] as $import ) {
            if ( ! isset( $manifest[ $import ] ) ) continue;
            foreach ( $manifest[ $import ]['css'] ?? [] as $css ) {
                $css_enqueue( $css );
            }
        }
    }

    public function script_as_module( string $tag, string $handle, string $src ): string {
        if ( ! in_array( $handle, self::MODULE_HANDLES, true ) ) {
            return $tag;
        }
        // WP concatenates [wp_add_inline_script 'before'] + [main src tag] +
        // ['after'] into $tag. Replace ONLY the src tag so any inline-before
        // bootstrap (window.hofPublic = …) survives. Regex tolerates
        // attribute reordering across WP versions (6.x had src first; 7.x
        // puts id first). Same shape as MenuRegistrar::script_as_module.
        $module   = '<script type="module" src="' . esc_url( $src ) . '"></script>';
        $pattern  = '#<script\b[^>]*\bsrc=(["\'])' . preg_quote( $src, '#' ) . '\1[^>]*></script>#';
        $replaced = preg_replace( $pattern, $module, $tag, 1 );
        return $replaced ?? $tag;
    }

    /**
     * Print CSS tokens scoped to .hof-facet / .hof-results on the frontend.
     *
     * Same tokens as the admin (single source of truth via filter), but
     * scoped to HOF surfaces so we don't leak vars into the theme.
     */
    public function print_token_block(): void {
        /**
         * Mirrors `hof_admin_css_tokens` — same shape, separate filter so
         * frontend can diverge from the dashboard if desired.
         *
         * @param array<string, string> $tokens
         */
        // The token contract is documented in SHEPDESIGN.md's "Theming" section.
        // What's shipped here is the curated set people typically want to
        // override (brand colors, radii, focus ring, label/count/input chrome).
        // The CSS bundle defines ~50 more tokens with sensible defaults — any
        // of them can be added to this filter from theme code without
        // touching the bundle.
        $tokens = (array) apply_filters( 'hof_public_css_tokens', apply_filters( 'hof_admin_css_tokens', [
            // Brand
            '--hof-primary'    => '#5b6cff',
            '--hof-on-primary' => '#ffffff',
            '--hof-accent'     => '#e0364f',
            '--hof-surface'    => '#ffffff',
            '--hof-bg'         => '#f4f5fb',
            '--hof-border'     => '#e3e4ec',
            '--hof-text'       => '#1a1c2c',
            '--hof-muted'      => '#6b6e7f',
            '--hof-danger'     => 'var(--hof-accent)',

            // Spacing + radius scale
            '--hof-space'        => '8px',
            '--hof-radius-xs'    => '4px',
            '--hof-radius-sm'    => '6px',
            '--hof-radius-md'    => '8px',
            '--hof-radius-lg'    => '10px',
            '--hof-radius-xl'    => '16px',
            '--hof-radius-pill'  => '999px',
            '--hof-radius-ui'    => 'var(--hof-radius-md)',

            // Typography
            '--hof-font-body'         => 'inherit',
            '--hof-font-size-body'    => '0.9375rem',
            '--hof-font-size-sm'      => '0.875rem',
            '--hof-font-size-xs'      => '0.8125rem',
            '--hof-font-size-eyebrow' => '0.6875rem',

            // Eyebrow label
            '--hof-label-color'           => 'var(--hof-muted)',
            '--hof-label-font-size'       => 'var(--hof-font-size-eyebrow)',
            '--hof-label-font-weight'     => '600',
            '--hof-label-letter-spacing'  => '0.08em',
            '--hof-label-transform'       => 'uppercase',

            // Count badge
            '--hof-count-color'       => 'var(--hof-muted)',
            '--hof-count-font-size'   => 'var(--hof-font-size-sm)',
            '--hof-count-font-weight' => '400',

            // Input chrome (range, search, date, dropdown, ask, visual-dna)
            '--hof-input-bg'       => 'var(--hof-surface)',
            '--hof-input-border'   => 'var(--hof-border)',
            '--hof-input-border-w' => '0.5px',
            '--hof-input-radius'   => 'var(--hof-radius-md)',
            '--hof-input-color'    => 'var(--hof-text)',
            '--hof-input-pad-y'    => '8px',
            '--hof-input-pad-x'    => '12px',

            // Focus ring
            '--hof-focus-ring'            => 'var(--hof-primary)',
            '--hof-focus-ring-w'          => '2px',
            '--hof-focus-ring-offset'     => '1px',
            '--hof-focus-ring-soft-w'     => '3px',
            '--hof-focus-ring-soft-alpha' => '18%',

            // Option row (checkbox / radio)
            '--hof-option-gap'    => 'var(--hof-space)',
            '--hof-option-pad-y'  => '4px',
            '--hof-option-accent' => 'var(--hof-primary)',
        ] ) );

        if ( empty( $tokens ) ) {
            return;
        }

        $lines = [];
        foreach ( $tokens as $key => $value ) {
            $safe_key   = preg_replace( '/[^a-zA-Z0-9_-]/', '', (string) $key );
            $safe_value = str_replace( [ '<', '>', '{', '}' ], '', (string) $value );
            if ( $safe_key === '' || $safe_value === '' ) continue;
            $lines[] = sprintf( '%s: %s;', $safe_key, $safe_value );
        }

        printf(
            '<style id="hof-tokens">.hof-facet, .hof-results { %s }</style>',
            implode( ' ', $lines ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized above.
        );
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    private function is_vite_dev(): bool {
        return defined( 'HOF_VITE_DEV' ) && HOF_VITE_DEV;
    }

    private function vite_dev_url(): string {
        if ( defined( 'HOF_VITE_DEV' ) && is_string( HOF_VITE_DEV ) ) {
            return rtrim( HOF_VITE_DEV, '/' );
        }
        return 'http://localhost:5173';
    }

    private function read_manifest(): ?array {
        static $cache = null;
        if ( $cache !== null ) {
            return $cache ?: null;
        }

        $path = HOF_PLUGIN_DIR . 'assets/dist/.vite/manifest.json';
        if ( ! is_readable( $path ) ) {
            $cache = false;
            return null;
        }

        $raw     = file_get_contents( $path );
        $decoded = $raw !== false ? json_decode( $raw, true ) : null;
        $cache   = is_array( $decoded ) ? $decoded : false;

        return is_array( $decoded ) ? $decoded : null;
    }
}
