<?php
/**
 * RewriteManager — pretty-URL plumbing.
 *
 *   - Registers the hof_path public query var.
 *   - When pretty URLs are enabled, adds two rewrite rules (plain +
 *     paginated) per WooCommerce storefront base: the shop page, product
 *     category/tag archives, and every product attribute archive. Bases are
 *     read from each object's registered rewrite config, never hardcoded.
 *   - Never flushes on normal loads: PrettyUrls::update() sets a flag,
 *     consumed here on the next admin_init (activation sets it too).
 *   - 404-guards invalid paths on parse_query: an unresolvable segment is a
 *     hard 404, not a soft-404 duplicate page.
 *
 * @package HookedOnFacets
 */

declare(strict_types=1);

namespace HookedOnFacets\Routing;

use HookedOnFacets\Contracts\Bootable;

defined( 'ABSPATH' ) || exit;

final class RewriteManager implements Bootable {

    public function register_hooks(): void {
        add_filter( 'query_vars', [ $this, 'register_query_var' ] );
        // Priority 20: after WooCommerce registers its taxonomies (init 5)
        // and the shop page is resolvable.
        add_action( 'init', [ $this, 'register_rules' ], 20 );
        add_action( 'admin_init', [ $this, 'maybe_flush' ] );
        add_action( 'parse_query', [ $this, 'guard_invalid_path' ] );
    }

    /**
     * @param array<int, string> $vars
     * @return array<int, string>
     */
    public function register_query_var( array $vars ): array {
        $vars[] = 'hof_path';
        return $vars;
    }

    public function register_rules(): void {
        if ( ! PrettyUrls::enabled() ) {
            return;
        }
        foreach ( self::build_rules( $this->bases(), PrettyUrls::base() ) as $regex => $target ) {
            add_rewrite_rule( $regex, $target, 'top' );
        }
    }

    /** Deferred flush — set by PrettyUrls::update() and plugin activation. */
    public function maybe_flush(): void {
        if ( ! get_option( PrettyUrls::FLUSH_FLAG ) ) {
            return;
        }
        delete_option( PrettyUrls::FLUSH_FLAG );
        flush_rewrite_rules( false );
    }

    /** Unresolvable pretty path → hard 404 on the main query. */
    public function guard_invalid_path( \WP_Query $query ): void {
        if ( is_admin() ) {
            return;
        }
        if ( ! $query->is_main_query() ) {
            return;
        }
        $path = $query->get( 'hof_path' );
        if ( ! is_string( $path ) || $path === '' ) {
            return;
        }
        if ( ! PrettyUrls::enabled() ) {
            // Known transient window: pretty URLs just toggled off but the
            // rules aren't flushed until the next admin_init — /filter/ URLs
            // briefly serve the unfiltered archive. Deliberate trade-off
            // (never flush on frontend loads) rather than an oversight.
            return;
        }
        if ( FilterState::codec()?->decode( $path ) === null ) {
            // A pre-set 404 must ship its own headers: WP::handle_404() bails
            // as soon as it sees is_404 already true ("If we've already
            // issued a 404, bail.") on the assumption that whoever set it
            // also sent the status — without these two calls the response is
            // a soft-404 (200 + "not found" template). The no-results
            // sentinel (QueryHook's own convention) empties the query so
            // nothing downstream mistakes it for a populated archive.
            $query->set( 'post__in', [ 0 ] );
            $query->set_404();
            if ( function_exists( 'status_header' ) ) {
                status_header( 404 );
                nocache_headers();
            }
        }
    }

    /**
     * Pure rule generation. Each base contributes a paginated rule first
     * (so /page/N/ isn't swallowed by the greedy hof_path capture), then the
     * plain rule.
     *
     * Base-slug collision: a term slugged exactly like the filter base (e.g.
     * a product category literally named "filter") will have its own
     * paginated/nested URLs captured by these rules and 404, since the rule
     * can't distinguish "…/category/filter/…" (the term) from
     * "…/category/{term}/filter/…" (the filter segment). The base segment
     * setting (PrettyUrls::base()) is the escape hatch — rename it away from
     * any colliding term slug.
     *
     * @param list<array{prefix: string, query: string, captures: int}> $bases
     * @return array<string, string> regex → target
     */
    public static function build_rules( array $bases, string $filter_base ): array {
        $rules = [];
        $base  = preg_quote( $filter_base, '#' );

        foreach ( $bases as $b ) {
            $n     = (int) $b['captures'];
            $path  = $n + 1; // hof_path capture index
            $paged = $n + 2;

            $rules[ "^{$b['prefix']}/{$base}/(.+?)/page/([0-9]{1,})/?$" ] =
                "index.php?{$b['query']}&hof_path=\$matches[{$path}]&paged=\$matches[{$paged}]";
            $rules[ "^{$b['prefix']}/{$base}/(.+?)/?$" ] =
                "index.php?{$b['query']}&hof_path=\$matches[{$path}]";
        }

        return $rules;
    }

    /**
     * Storefront bases, read from live WP/WC registration. Empty when
     * WooCommerce is absent.
     *
     * @return list<array{prefix: string, query: string, captures: int}>
     */
    private function bases(): array {
        $bases = [];

        if ( function_exists( 'wc_get_page_id' ) ) {
            $shop_id = (int) wc_get_page_id( 'shop' );
            $uri     = $shop_id > 0 ? get_page_uri( $shop_id ) : '';
            if ( is_string( $uri ) && $uri !== '' ) {
                $bases[] = [
                    'prefix'   => preg_quote( trim( $uri, '/' ), '#' ),
                    'query'    => 'post_type=product',
                    'captures' => 0,
                ];
            }
        }

        foreach ( [ 'product_cat' => '(.+?)', 'product_tag' => '([^/]+)' ] as $taxonomy => $capture ) {
            $tax = get_taxonomy( $taxonomy );
            if ( $tax && ! empty( $tax->rewrite['slug'] ) ) {
                $bases[] = [
                    'prefix'   => preg_quote( trim( (string) $tax->rewrite['slug'], '/' ), '#' ) . '/' . $capture,
                    'query'    => "{$taxonomy}=\$matches[1]",
                    'captures' => 1,
                ];
            }
        }

        if ( function_exists( 'wc_get_attribute_taxonomies' ) && function_exists( 'wc_attribute_taxonomy_name' ) ) {
            foreach ( (array) wc_get_attribute_taxonomies() as $attribute ) {
                $taxonomy = wc_attribute_taxonomy_name( (string) $attribute->attribute_name );
                $tax      = get_taxonomy( $taxonomy );
                if ( $tax && ! empty( $tax->rewrite['slug'] ) ) {
                    $bases[] = [
                        'prefix'   => preg_quote( trim( (string) $tax->rewrite['slug'], '/' ), '#' ) . '/([^/]+)',
                        'query'    => "{$taxonomy}=\$matches[1]",
                        'captures' => 1,
                    ];
                }
            }
        }

        /**
         * Filter the storefront bases pretty-URL rules are registered on.
         *
         * @param list<array{prefix: string, query: string, captures: int}> $bases
         */
        return (array) apply_filters( 'hof_pretty_urls_bases', $bases );
    }
}
