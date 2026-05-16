<?php
/**
 * MenuRegistrar — registers the HOF admin page and enqueues the SPA bundle.
 *
 * Bridges the Vite-built React app into wp-admin:
 *   1. Adds a top-level menu page.
 *   2. Reads assets/dist/.vite/manifest.json to resolve hashed JS/CSS files.
 *   3. Injects window.hofAdmin (REST URL, nonce, initial facets, CSS tokens)
 *      as an inline script that runs before the deferred ES module loads.
 *
 * Local dev: define HOF_VITE_DEV in wp-config.php (true or a URL like
 * 'http://localhost:5173') to bypass the manifest and load directly from the
 * Vite dev server with HMR.
 *
 * @package HookedOnFacets
 */

declare(strict_types=1);

namespace HookedOnFacets\Admin;

use HookedOnFacets\Contracts\Bootable;
use HookedOnFacets\Indexer;

defined( 'ABSPATH' ) || exit;

final class MenuRegistrar implements Bootable {

    public const PAGE_SLUG = 'hooked-on-facets';

    /** Vite entry path — matches the input key in vite.config.js. */
    private const ENTRY = 'admin/src/main.jsx';

    /** Script handles we tag as type="module" via script_loader_tag. */
    private const MODULE_HANDLES = [ 'hof-admin-main', 'hof-vite-client' ];

    public function register_hooks(): void {
        add_action( 'admin_menu',            [ $this, 'register_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
        add_filter( 'script_loader_tag',     [ $this, 'script_as_module' ], 10, 3 );
    }

    public function register_menu(): void {
        // Brand mark — three-plane isometric hexagon with a coral spark.
        // See BRAND.md (brand kit repo) for the canonical SVG.
        $icon_svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 72 72">'
            . '<path d="M36 6L62 21L36 36L10 21Z" fill="#7F77DD"/>'
            . '<path d="M10 21L10 51L36 66L36 36Z" fill="#3C3489"/>'
            . '<path d="M62 21L62 51L36 66L36 36Z" fill="#534AB7"/>'
            . '<circle cx="36" cy="6" r="3.5" fill="#D85A30"/>'
            . '</svg>';

        add_menu_page(
            __( 'Hooked on Facets', 'hooked-on-facets' ),
            __( 'Facets', 'hooked-on-facets' ),
            'manage_options',
            self::PAGE_SLUG,
            [ $this, 'render_page' ],
            'data:image/svg+xml;base64,' . base64_encode( $icon_svg ),
            58
        );
    }

    public function render_page(): void {
        // React owns everything inside. We only print the mount point.
        echo '<div id="hof-admin-root" class="hof-admin"></div>';
    }

    public function enqueue_assets( string $hook ): void {
        if ( $hook !== 'toplevel_page_' . self::PAGE_SLUG ) {
            return;
        }

        $tokens = $this->css_tokens();
        $inline = sprintf(
            'window.hofAdmin = %s;',
            wp_json_encode( [
                'restUrl' => esc_url_raw( rest_url( 'hof/v1/' ) ),
                'nonce'   => wp_create_nonce( 'wp_rest' ),
                'facets'  => array_values( (array) get_option( Indexer::OPTION_FACETS, [] ) ),
                'tokens'  => $tokens,
            ] )
        );

        if ( $this->is_vite_dev() ) {
            $this->enqueue_dev( $inline );
        } else {
            if ( ! $this->enqueue_prod( $inline ) ) {
                return;
            }
        }

        // Tokens live as inline CSS scoped to the admin shell — no rebuild needed
        // when the hof_admin_css_tokens filter changes them at runtime.
        wp_add_inline_style( 'wp-admin', $this->token_css_block( $tokens ) );
    }

    public function script_as_module( string $tag, string $handle, string $src ): string {
        if ( ! in_array( $handle, self::MODULE_HANDLES, true ) ) {
            return $tag;
        }
        return '<script type="module" src="' . esc_url( $src ) . '"></script>';
    }

    // ── Asset enqueue paths ─────────────────────────────────────────────────

    private function enqueue_dev( string $inline ): void {
        $base = $this->vite_dev_url();

        wp_enqueue_script( 'hof-vite-client', $base . '/@vite/client', [], null, true );

        wp_register_script(
            'hof-admin-main',
            $base . '/' . self::ENTRY,
            [ 'hof-vite-client' ],
            null,
            true
        );
        wp_add_inline_script( 'hof-admin-main', $inline, 'before' );
        wp_enqueue_script( 'hof-admin-main' );
    }

    private function enqueue_prod( string $inline ): bool {
        $manifest = $this->read_manifest();
        if ( ! $manifest || ! isset( $manifest[ self::ENTRY ] ) ) {
            add_action( 'admin_notices', static function (): void {
                printf(
                    '<div class="notice notice-error"><p>%s</p></div>',
                    esc_html__( 'Hooked on Facets: admin assets are missing. Run `npm install && npm run build` in the plugin directory.', 'hooked-on-facets' )
                );
            } );
            return false;
        }

        $entry = $manifest[ self::ENTRY ];

        wp_register_script(
            'hof-admin-main',
            HOF_PLUGIN_URL . 'assets/dist/' . $entry['file'],
            [],
            HOF_VERSION,
            true
        );
        wp_add_inline_script( 'hof-admin-main', $inline, 'before' );
        wp_enqueue_script( 'hof-admin-main' );

        $this->enqueue_manifest_css( $manifest, $entry );
        return true;
    }

    private function enqueue_manifest_css( array $manifest, array $entry ): void {
        $seen = [];

        $enqueue = static function ( string $css_path ) use ( &$seen ) {
            if ( isset( $seen[ $css_path ] ) ) {
                return;
            }
            $seen[ $css_path ] = true;
            wp_enqueue_style(
                'hof-admin-' . md5( $css_path ),
                HOF_PLUGIN_URL . 'assets/dist/' . $css_path,
                [],
                HOF_VERSION
            );
        };

        foreach ( $entry['css'] ?? [] as $css ) {
            $enqueue( $css );
        }
        // Pick up CSS from imported chunks too.
        foreach ( $entry['imports'] ?? [] as $import ) {
            if ( ! isset( $manifest[ $import ] ) ) {
                continue;
            }
            foreach ( $manifest[ $import ]['css'] ?? [] as $css ) {
                $enqueue( $css );
            }
        }
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

    /**
     * Default CSS custom-property tokens for the HOF admin and public surfaces.
     *
     * @return array<string, string>
     */
    private function css_tokens(): array {
        /**
         * Filter the HOF admin CSS variable tokens. Same shape as the public
         * runtime will read; the two stay in sync because the option lookup
         * happens server-side.
         *
         * @param array<string, string> $tokens
         */
        return apply_filters( 'hof_admin_css_tokens', [
            '--hof-primary'    => '#5b6cff',
            '--hof-on-primary' => '#ffffff',
            '--hof-surface'    => '#ffffff',
            '--hof-bg'         => '#f4f5fb',
            '--hof-border'     => '#e3e4ec',
            '--hof-text'       => '#1a1c2c',
            '--hof-muted'      => '#6b6e7f',
            '--hof-danger'     => '#e0364f',
            '--hof-radius-ui'  => '8px',
            '--hof-space'      => '8px',
            '--hof-font'       => '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif',
        ] );
    }

    /**
     * @param array<string, string> $tokens
     */
    private function token_css_block( array $tokens ): string {
        $lines = [];
        foreach ( $tokens as $key => $value ) {
            // Tokens are filter-controlled; defensively strip anything that could break out.
            $safe_key   = preg_replace( '/[^a-zA-Z0-9_-]/', '', $key );
            $safe_value = str_replace( [ '<', '>', '{', '}' ], '', (string) $value );
            if ( $safe_key === '' || $safe_value === '' ) {
                continue;
            }
            $lines[] = sprintf( '%s: %s;', $safe_key, $safe_value );
        }
        return '.hof-admin, #hof-admin-root { ' . implode( ' ', $lines ) . ' }';
    }
}
