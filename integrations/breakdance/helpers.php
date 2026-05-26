<?php
/**
 * Breakdance template-tag helper.
 *
 * Loaded unconditionally by the Breakdance bridge's register_hooks(). The
 * real logic lives in \HookedOnFacets\Integrations\Breakdance::query_args();
 * this is the thin global surface a Breakdance user pastes into a Post Loop
 * Builder's Array Query.
 *
 * @package HookedOnFacets\Integrations\Breakdance
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'hof_breakdance_query_args' ) ) {
    /**
     * Hooked on Facets — Breakdance Array Query helper.
     *
     * In a Post Loop Builder element, set the Query type to "Array Query"
     * and return this, passing your loop's own WP_Query args:
     *
     *     return hof_breakdance_query_args( [
     *         'post_type'      => 'product',
     *         'posts_per_page' => 12,
     *     ] );
     *
     * When the request carries `?hof[*]` filter params, the returned args
     * gain a `post__in` of the matching IDs (or `[0]` when nothing matches,
     * so the loop shows "no results" rather than everything). With no HOF
     * filters active, your base args are returned unchanged.
     *
     * @param array<string, mixed> $base_args Your loop's WP_Query args.
     * @return array<string, mixed>
     */
    function hof_breakdance_query_args( array $base_args = [] ): array {
        return \HookedOnFacets\Plugin::instance()
            ->make( \HookedOnFacets\Integrations\Breakdance::class )
            ->query_args( $base_args );
    }
}
