<?php
/**
 * Breakdance bridge.
 *
 * Breakdance is the odd one out among the page-builder bridges. Elementor
 * exposes `elementor/query/{id}` and Bricks exposes `bricks/posts/query_vars`
 * — scoped, documented filters that let us inject post__in into a specific
 * query loop. Breakdance documents no equivalent: its Post Loop Builder is
 * customized *in the builder* (Custom / Text / Array query), and the only
 * PHP injection point is the user-authored **Array Query**, where the user
 * writes a snippet returning WP_Query args.
 *
 * So the binding is a recipe, not a hook. We expose a global template-tag
 * helper, `hof_breakdance_query_args()`, that the user pastes into a loop's
 * Array Query:
 *
 *     return hof_breakdance_query_args( [
 *         'post_type'      => 'product',
 *         'posts_per_page' => 12,
 *     ] );
 *
 * It returns those base args with HOF's URL-derived `post__in` merged in
 * when `?hof[*]` filters are active, and returns them untouched otherwise.
 * register_hooks() loads the helper file; the logic lives here in
 * query_args() so it's unit-testable with a mocked resolver.
 *
 * Two deliberate departures from the Elementor/Bricks bridges:
 *
 *   - No presence gate. There's nothing to register *against* Breakdance
 *     (no documented element/query API we use), and the helper is an inert
 *     function definition until Breakdance's Array Query calls it — so we
 *     load it unconditionally rather than guess at a presence check.
 *   - No native placement element. Breakdance elements are Element-Studio
 *     directory bundles, not a registerable PHP class; until that format is
 *     authored against a live Breakdance install, placement uses the
 *     existing [hof_facet] shortcode inside a Breakdance Shortcode/Code
 *     element.
 *
 * @package HookedOnFacets\Integrations
 */

declare(strict_types=1);

namespace HookedOnFacets\Integrations;

use HookedOnFacets\Contracts\Bootable;
use HookedOnFacets\Filter\IdResolver;
use HookedOnFacets\Filter\Resolver;
use HookedOnFacets\Telemetry\LoopHookRecorder;

defined( 'ABSPATH' ) || exit;

final class Breakdance implements Bootable {

    public function __construct(
        private readonly IdResolver $resolver,
        private readonly ?LoopHookRecorder $recorder = null,
    ) {}

    public function register_hooks(): void {
        // Load the global hof_breakdance_query_args() template tag. It's a
        // bare function definition (no side effects), only ever invoked from
        // a Breakdance Array Query, so it's safe to define unconditionally.
        require_once HOF_PLUGIN_DIR . 'integrations/breakdance/helpers.php';
    }

    /**
     * Merge HOF's URL-derived filter state into a Breakdance Array Query's
     * WP_Query args.
     *
     * The Array Query's return value *is* the full query, so the user passes
     * their base args (post type, ordering, per-page) and we layer post__in
     * on top — HOF wins on post__in when filters are active.
     *
     * @param array<string, mixed> $base_args The loop's own WP_Query args.
     * @return array<string, mixed>
     */
    public function query_args( array $base_args = [] ): array {
        $filter_state = Resolver::parse_request_filters();
        if ( empty( $filter_state ) ) {
            return $base_args; // No ?hof[*] params → loop runs its normal query.
        }

        $ids = $this->resolver->resolve_ids( $filter_state );
        if ( $ids === null ) {
            return $base_args; // No recognized facet → leave the loop alone.
        }

        // [0] is the canonical "no results" sentinel for post__in — matches
        // QueryHook and the Elementor / Bricks bridges on the same case.
        $base_args['post__in'] = ! empty( $ids ) ? $ids : [ 0 ];

        $this->recorder?->record_loop_hook( 'breakdance:' . $this->guess_post_type( $base_args ) );

        return $base_args;
    }

    /**
     * @param array<string, mixed> $args
     */
    private function guess_post_type( array $args ): string {
        $pt = $args['post_type'] ?? '';
        if ( is_array( $pt ) ) {
            $pt = $pt[0] ?? '';
        }
        return is_string( $pt ) && $pt !== '' ? $pt : 'unknown';
    }
}
