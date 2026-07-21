<?php
/**
 * SEO manager for faceted (`?hof[*]`) views.
 *
 * Faceted URLs are an SEO liability: every filter combination is a distinct
 * crawlable URL, which bloats the crawl budget and spawns near-duplicate
 * pages. General SEO plugins (Yoast, Rank Math, AIOSEO) don't understand the
 * `?hof[*]` query shape, so HOF owns the faceted-specific signals:
 *
 *   1. **Canonical** — a filtered view points its canonical at the clean,
 *      filter-stripped URL, consolidating ranking signals onto the base
 *      archive. (Deferred to an active SEO plugin to avoid a double tag.)
 *   2. **Robots noindex** — once a configurable number of facets are active
 *      (default 2), the combo is `noindex,follow`: crawlers stop indexing the
 *      combinatorial long tail but still follow links to discover products.
 *      Emitted through the core `wp_robots` filter so it composes with other
 *      plugins rather than fighting them.
 *   3. **Title suffix** — the active filters are appended to the document
 *      title ("Shop · Brand: Acme, Color: Red") so the SERP snippet of an
 *      indexed single-facet page reads meaningfully.
 *   4. **301 redirects** — on pretty-URL-enabled surfaces (the shop archive,
 *      product taxonomies), legacy `?hof[*]` requests and non-canonical
 *      facet-segment orderings 301 to the canonical pretty path, so link
 *      equity and crawl budget consolidate onto one URL per filter
 *      combination instead of splitting across every equivalent form.
 *
 * The decision logic is pure (filter state + settings → value) and unit
 * tested; the WordPress hooks are thin glue. Settings live under `hof_seo`.
 *
 * @package HookedOnFacets
 */

declare(strict_types=1);

namespace HookedOnFacets\Seo;

use HookedOnFacets\Contracts\Bootable;
use HookedOnFacets\Filter\Resolver;
use HookedOnFacets\Indexer;
use HookedOnFacets\Routing\FilterState;
use HookedOnFacets\Routing\PrettyUrls;
use HookedOnFacets\Routing\UrlCodec;

defined( 'ABSPATH' ) || exit;

final class SeoManager implements Bootable {

    public const OPTION = 'hof_seo';

    /** @var array<string, mixed>|null Per-request settings cache. */
    private ?array $settings_cache = null;

    /** @var array<string, array<string, mixed>>|null facet defs by name. */
    private ?array $defs_cache = null;

    public function register_hooks(): void {
        // Priority 1 so the canonical lands early in <head>.
        add_action( 'wp_head', [ $this, 'print_canonical' ], 1 );
        add_filter( 'wp_robots', [ $this, 'filter_robots' ] );
        add_filter( 'document_title_parts', [ $this, 'filter_title_parts' ] );

        // Priority 1: redirect before anything renders.
        add_action( 'template_redirect', [ $this, 'maybe_redirect' ], 1 );
    }

    // ── WordPress glue ───────────────────────────────────────────────────────

    /**
     * Echo a canonical link to the filter-stripped URL on a filtered front-end
     * view. Skipped when another SEO plugin is active (it manages canonical)
     * or when disabled in settings.
     */
    public function print_canonical(): void {
        if ( is_admin() || ! $this->is_filtered_view() ) {
            return;
        }
        $settings = $this->settings();
        if ( empty( $settings['manage_canonical'] ) || $this->another_seo_plugin_active() ) {
            return;
        }

        $canonical = $this->canonical_url( $this->current_url() );
        if ( PrettyUrls::enabled() && ! FilterState::is_path_invalid() ) {
            $codec = FilterState::codec();
            $state = Resolver::parse_request_filters();
            // Pretty is only preferred on a surface RewriteManager rewrites
            // (shop-as-front-page has no root rules — the legacy clean
            // canonical is correct there), and only when the state actually
            // has a path-eligible facet: a tail-only state (all ranges/search)
            // has no pretty form, and the legacy clean URL is exactly right.
            if (
                $codec && ! empty( $state )
                && $this->on_pretty_surface( $this->current_base_path( $codec ) )
                && $codec->encode( $state )['path'] !== ''
            ) {
                $pretty = $this->pretty_url_for( $this->current_url(), $state, $codec );
                if ( $pretty !== '' ) {
                    $canonical = $pretty;
                }
            }
        }
        if ( $canonical !== '' ) {
            printf( "<link rel=\"canonical\" href=\"%s\" />\n", esc_url( $canonical ) );
        }
    }

    /**
     * template_redirect glue: 301 legacy/non-canonical faceted URLs to the
     * canonical pretty form. GET/HEAD only (matches core's
     * redirect_canonical()); invalid paths are the 404 guard's problem, not
     * ours. Gated to surfaces RewriteManager actually rewrites — elsewhere
     * there's no pretty form to redirect to, so redirecting would 404 a page
     * that renders fine as-is (shop-as-front-page, any shortcode/builder
     * page). The target is re-validated with wp_validate_redirect() so a
     * forged/alias Host can never fall through to wp_safe_redirect()'s
     * admin_url() fallback and send a shopper to wp-admin.
     */
    public function maybe_redirect(): void {
        if ( is_admin() || ! PrettyUrls::enabled() ) {
            return;
        }
        if ( ! in_array( strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ), [ 'GET', 'HEAD' ], true ) ) {
            return;
        }
        if ( FilterState::is_path_invalid() ) {
            return;
        }
        $codec = FilterState::codec();
        $state = Resolver::parse_request_filters();
        if ( ! $codec || empty( $state ) ) {
            return;
        }
        if ( ! $this->on_pretty_surface( $this->current_base_path( $codec ) ) ) {
            return;
        }
        $target = $this->redirect_target( $this->current_url(), $state, $codec );
        if ( $target === '' || '' === wp_validate_redirect( $target, '' ) ) {
            return;
        }
        wp_safe_redirect( $target, 301 );
        exit;
    }

    /**
     * Add noindex to the robots directives once enough facets are stacked.
     *
     * @param array<string, bool> $robots
     * @return array<string, bool>
     */
    public function filter_robots( array $robots ): array {
        if ( is_admin() || ! $this->is_filtered_view() ) {
            return $robots;
        }
        if ( $this->should_noindex( Resolver::parse_request_filters() ) ) {
            $robots['noindex'] = true;
            $robots['follow']  = true;
        }
        return $robots;
    }

    /**
     * Append an active-filters suffix to the document title.
     *
     * @param array<string, string> $parts
     * @return array<string, string>
     */
    public function filter_title_parts( array $parts ): array {
        if ( is_admin() || ! $this->is_filtered_view() ) {
            return $parts;
        }
        $suffix = $this->filters_summary( Resolver::parse_request_filters() );
        if ( $suffix !== '' && isset( $parts['title'] ) ) {
            $parts['title'] .= ' · ' . $suffix;
        }
        return $parts;
    }

    // ── Pure logic (unit tested) ─────────────────────────────────────────────

    /**
     * Strip the `hof` and `hof_path` query parameters from a URL, yielding
     * the clean canonical base. Preserves other params and the path.
     *
     * `parse_str` folds every `?hof[brand][]=…` / `?hof[price][min]=…` into
     * a single top-level `hof` array key, so an exact match on `hof` catches
     * the whole `hof[*]` shape without a prefix match — which would also eat
     * an unrelated `?hoffman=1`. `hof_path` is a public query var (WP
     * populates it from `?hof_path=…` too, and `$_GET` wins over
     * rule-derived vars), so it's stripped alongside `hof`.
     */
    public function canonical_url( string $url ): string {
        if ( $url === '' ) {
            return '';
        }
        $parts = wp_parse_url( $url );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
            return '';
        }

        $query = [];
        if ( ! empty( $parts['query'] ) ) {
            parse_str( (string) $parts['query'], $query );
            foreach ( array_keys( $query ) as $key ) {
                if ( in_array( (string) $key, [ 'hof', 'hof_path' ], true ) ) {
                    unset( $query[ $key ] );
                }
            }
        }

        $scheme = $parts['scheme'] ?? 'https';
        $base   = $scheme . '://' . $parts['host']
            . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' )
            . ( $parts['path'] ?? '' );

        $qs = http_build_query( $query );
        return $qs !== '' ? $base . '?' . $qs : $base;
    }

    /**
     * The canonical pretty URL for a filter state on the current surface:
     * archive base path (pretty/hof cruft stripped, /page/N/ preserved) +
     * canonical-ordered /base/facet/slug segments + the non-path tail as
     * ?hof[*] alongside preserved non-hof params. Empty string when the URL
     * can't be parsed.
     *
     * The query strip removes hof and hof_path (see canonical_url()'s
     * docblock for why an exact match, not a prefix match) — hof_path is a
     * public query var (WP populates it from ?hof_path=… too, and $_GET wins
     * over rule-derived vars), so the target must never re-emit it.
     *
     * @param array<string, mixed> $state
     */
    public function pretty_url_for( string $current_url, array $state, UrlCodec $codec ): string {
        $parts = wp_parse_url( $current_url );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
            return '';
        }

        [ $path, $page_suffix ] = $this->peel_pagination( (string) ( $parts['path'] ?? '/' ) );

        $base_path = $codec->strip_base_path( $path );
        $encoded   = $codec->encode( $state );

        $new_path = rtrim( $base_path, '/' ) . ( $encoded['path'] !== '' ? $encoded['path'] : '/' );
        $new_path = user_trailingslashit( rtrim( $new_path . ltrim( $page_suffix, '/' ), '/' ) );

        // Non-hof query params survive; the tail re-encodes as hof[*].
        $query = [];
        if ( ! empty( $parts['query'] ) ) {
            parse_str( (string) $parts['query'], $query );
            foreach ( array_keys( $query ) as $key ) {
                if ( in_array( (string) $key, [ 'hof', 'hof_path' ], true ) ) {
                    unset( $query[ $key ] );
                }
            }
        }
        if ( ! empty( $encoded['tail'] ) ) {
            $query['hof'] = $encoded['tail'];
        }

        $scheme = $parts['scheme'] ?? 'https';
        $url    = $scheme . '://' . $parts['host']
            . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' )
            . $new_path;

        $qs = http_build_query( $query );
        return $qs !== '' ? $url . '?' . $qs : $url;
    }

    /**
     * Where the current request should 301 to — '' for "don't redirect".
     * Covers legacy ?hof[*] → pretty and non-canonical segment order →
     * canonical order. Loop-guarded by comparing against the current URL.
     *
     * @param array<string, mixed> $state
     */
    public function redirect_target( string $current_url, array $state, UrlCodec $codec ): string {
        $encoded = $codec->encode( $state );
        if ( $encoded['path'] === '' ) {
            return ''; // Nothing path-eligible — no pretty form exists.
        }

        $target = $this->pretty_url_for( $current_url, $state, $codec );
        if ( $target === '' ) {
            return '';
        }

        $normalize = static fn( string $u ): string => rtrim( rawurldecode( $u ), '/' );
        return $normalize( $target ) === $normalize( $current_url ) ? '' : $target;
    }

    /**
     * Whether a filter state should be noindexed: more distinct active facets
     * than the configured threshold (default 2 → single-facet pages stay
     * indexable, multi-facet combos don't). Reserved internal keys
     * (`_visual_ids`, `_bin_ids`) don't count as facets.
     *
     * @param array<string, mixed> $filter_state
     */
    public function should_noindex( array $filter_state ): bool {
        $settings = $this->settings();
        if ( empty( $settings['noindex_combos'] ) ) {
            return false;
        }
        $threshold = max( 1, (int) $settings['noindex_threshold'] );
        return $this->active_facet_count( $filter_state ) >= $threshold;
    }

    /**
     * Human-readable summary of the active filters, e.g. "Brand: Acme,
     * Color: Red". Uses each facet's configured label; falls back to its name.
     * Ranges render as "Label: min–max". Reserved keys are skipped.
     *
     * @param array<string, mixed> $filter_state
     */
    public function filters_summary( array $filter_state ): string {
        $defs  = $this->facet_defs();
        $bits  = [];
        foreach ( $filter_state as $name => $value ) {
            if ( $this->is_reserved_key( (string) $name ) ) {
                continue;
            }
            $label = isset( $defs[ $name ]['label'] ) && (string) $defs[ $name ]['label'] !== ''
                ? (string) $defs[ $name ]['label']
                : (string) $name;

            if ( is_array( $value ) && ( isset( $value['min'] ) || isset( $value['max'] ) ) ) {
                $min  = isset( $value['min'] ) ? (string) $value['min'] : '';
                $max  = isset( $value['max'] ) ? (string) $value['max'] : '';
                $body = $min !== '' && $max !== '' ? "{$min}–{$max}" : ( $min !== '' ? "≥{$min}" : "≤{$max}" );
            } elseif ( is_array( $value ) ) {
                $body = implode( ', ', array_map( 'strval', $value ) );
            } else {
                $body = (string) $value;
            }

            if ( $body !== '' ) {
                $bits[] = "{$label}: {$body}";
            }
        }
        return implode( ', ', $bits );
    }

    /**
     * Count distinct active facets, excluding reserved internal keys.
     *
     * @param array<string, mixed> $filter_state
     */
    public function active_facet_count( array $filter_state ): int {
        $n = 0;
        foreach ( array_keys( $filter_state ) as $key ) {
            if ( ! $this->is_reserved_key( (string) $key ) ) {
                $n++;
            }
        }
        return $n;
    }

    // ── Settings ─────────────────────────────────────────────────────────────

    /**
     * Settings merged over defaults.
     *
     * @return array<string, mixed>
     */
    public function settings(): array {
        if ( $this->settings_cache !== null ) {
            return $this->settings_cache;
        }
        $stored = get_option( self::OPTION, [] );
        $stored = is_array( $stored ) ? $stored : [];
        return $this->settings_cache = array_merge( self::defaults(), $stored );
    }

    /**
     * Merge a partial settings array over what's stored and persist it. Only
     * the keys present in defaults() are kept (the REST layer already coerced
     * types); the per-request cache is invalidated.
     *
     * @param array<string, mixed> $partial
     */
    public function update_settings( array $partial ): void {
        $allowed = array_intersect_key( $partial, self::defaults() );
        $merged  = array_merge( $this->settings(), $allowed );
        update_option( self::OPTION, $merged, false );
        $this->settings_cache = $merged;
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array {
        return [
            'manage_canonical'  => true,
            'noindex_combos'    => true,
            'noindex_threshold' => 2,
            'title_suffix'      => true,
        ];
    }

    // ── Internals ──────────────────────────────────────────────────────────

    private function is_reserved_key( string $key ): bool {
        return str_starts_with( $key, '_' );
    }

    private function is_filtered_view(): bool {
        return ! empty( Resolver::parse_request_filters() );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function facet_defs(): array {
        if ( $this->defs_cache !== null ) {
            return $this->defs_cache;
        }
        $raw = (array) get_option( Indexer::OPTION_FACETS, [] );
        $by  = [];
        foreach ( $raw as $def ) {
            if ( is_array( $def ) && isset( $def['name'] ) ) {
                $by[ (string) $def['name'] ] = $def;
            }
        }
        return $this->defs_cache = $by;
    }

    /**
     * REQUEST_URI is deliberately raw — no sanitize_text_field(). That
     * function strips `%xx` octets, which decanonicalizes the URL: a pretty
     * path with a query tail (`%5Bprice%5D%5Bmin%5D=10`) or a non-ASCII slug
     * (`%d0%bf`) would come back with the encoding mangled, so
     * redirect_target()'s loop-guard string comparison would never see the
     * fixed point it just redirected to — a permanent 301 loop. This matches
     * core's redirect_canonical(), which also reads REQUEST_URI raw; safety
     * is downstream (esc_url() on the printed canonical, and
     * wp_sanitize_redirect() inside wp_safe_redirect()).
     */
    private function current_url(): string {
        $host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw on purpose, see docblock above.
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
        if ( $host === '' || $uri === '' ) {
            return '';
        }
        $scheme = is_ssl() ? 'https' : 'http';
        return $scheme . '://' . $host . $uri;
    }

    /**
     * Peel a trailing `/page/N/` pagination segment off a path.
     *
     * @return array{0: string, 1: string} [stripped path, page suffix ('' when none)].
     */
    private function peel_pagination( string $path ): array {
        if ( preg_match( '#/page/([0-9]+)/?$#', $path, $m ) ) {
            return [ (string) preg_replace( '#/page/[0-9]+/?$#', '/', $path ), '/page/' . $m[1] . '/' ];
        }
        return [ $path, '' ];
    }

    /**
     * The archive base path for the current request: pagination peeled,
     * then hof-cruft stripped via the codec. Shared by the pretty-canonical
     * preference (print_canonical()) and the redirect surface gate
     * (maybe_redirect()) so the peel+strip logic lives in one place.
     */
    private function current_base_path( UrlCodec $codec ): string {
        $parts = wp_parse_url( $this->current_url() );
        $path  = is_array( $parts ) ? (string) ( $parts['path'] ?? '/' ) : '/';
        [ $path, ] = $this->peel_pagination( $path );
        return $codec->strip_base_path( $path );
    }

    /**
     * Whether the current request is a surface RewriteManager registers
     * pretty rules on — the shop archive or a product taxonomy — with a
     * non-root base path (shop-as-front-page has no root rules). Everywhere
     * else the legacy clean canonical still applies and we must never 301.
     *
     * Surfaces a third party adds via the `hof_pretty_urls_bases` filter are
     * deliberately excluded here too: this gate only recognizes the shop
     * archive and product taxonomies, so those extra surfaces get neither a
     * 301 nor a pretty canonical from SeoManager — conservative by design,
     * since we can't verify a third-party surface actually has rewrite rules
     * registered for it.
     */
    private function on_pretty_surface( string $base_path ): bool {
        if ( '/' === $base_path || '' === $base_path ) {
            return false;
        }
        $is_shop = function_exists( 'is_shop' ) && is_shop();
        $is_tax  = function_exists( 'is_product_taxonomy' ) && is_product_taxonomy();
        return $is_shop || $is_tax;
    }

    /**
     * Whether a known general-purpose SEO plugin is active and presumably
     * managing the canonical tag — so HOF defers to avoid a duplicate.
     */
    private function another_seo_plugin_active(): bool {
        return defined( 'WPSEO_VERSION' )          // Yoast SEO
            || defined( 'RANK_MATH_VERSION' )      // Rank Math
            || defined( 'AIOSEO_VERSION' )         // All in One SEO
            || defined( 'SEOPRESS_VERSION' );      // SEOPress
    }
}
