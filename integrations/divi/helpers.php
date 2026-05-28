<?php
/**
 * Divi template-tag helper.
 *
 * Loaded unconditionally by the Divi bridge's register_hooks(). The real
 * logic lives in \HookedOnFacets\Integrations\Divi::query_args(); this is the
 * thin global surface a developer calls from a scoped query snippet to bind a
 * Divi Blog-module loop.
 *
 * @package HookedOnFacets\Integrations\Divi
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'hof_divi_query_args' ) ) {
    /**
     * Hooked on Facets — Divi loop query helper.
     *
     * Divi Theme Builder archive / index templates filter automatically (they
     * run the main query, which HOF's QueryHook already intercepts). This
     * helper is for a Blog module on a regular page — a secondary query Divi
     * gives no scoped hook for. Call it from your own `pre_get_posts` snippet,
     * scoped to the loop you want filtered:
     *
     *     add_action( 'pre_get_posts', function ( WP_Query $q ): void {
     *         if ( is_admin() || $q->is_main_query() ) {
     *             return;
     *         }
     *         $args = hof_divi_query_args( $q->query_vars );
     *         if ( isset( $args['post__in'] ) ) {
     *             $q->set( 'post__in', $args['post__in'] );
     *         }
     *     } );
     *
     * When the request carries `?hof[*]` filter params, the returned args gain
     * a `post__in` of the matching IDs (or `[0]` when nothing matches, so the
     * loop shows "no results" rather than everything). With no HOF filters
     * active, your base args are returned unchanged.
     *
     * @param array<string, mixed> $base_args Your loop's WP_Query args.
     * @return array<string, mixed>
     */
    function hof_divi_query_args( array $base_args = [] ): array {
        return \HookedOnFacets\Plugin::instance()
            ->make( \HookedOnFacets\Integrations\Divi::class )
            ->query_args( $base_args );
    }
}
