<?php
/**
 * Divi bridge.
 *
 * Divi sits with Breakdance, not Elementor/Bricks. Elementor exposes
 * `elementor/query/{id}` and Bricks exposes `bricks/posts/query_vars` —
 * scoped, documented filters that hand us a specific query loop to inject
 * post__in into. Divi core ships no equivalent: the per-loop query hooks
 * people reach for (`ctdqb_post_query_args` and friends) belong to
 * third-party Divi Query Builder plugins, not Divi itself. So, like
 * Breakdance, the binding is a recipe rather than a hook.
 *
 * Two paths cover the real Divi surfaces:
 *
 *   1. Theme Builder archive / index templates drive the **main query**, so
 *      HOF's existing QueryHook (`pre_get_posts`, main-query leg) already
 *      filters them — no Divi-specific code runs. This is the common case
 *      (building a shop / archive template in Divi) and it works out of the
 *      box.
 *
 *   2. A Blog module dropped on a regular page runs a **secondary WP_Query**
 *      with no per-module identifier exposed at query time. For that, we
 *      expose a global template-tag helper, `hof_divi_query_args()`, that a
 *      developer calls from their own scoped `pre_get_posts` snippet (or any
 *      custom query) to merge HOF's URL-derived post__in into the loop's
 *      args:
 *
 *          add_action( 'pre_get_posts', function ( WP_Query $q ): void {
 *              if ( is_admin() || $q->is_main_query() ) {
 *                  return;
 *              }
 *              // Scope to the Blog-module loop you want filtered, then:
 *              $args = hof_divi_query_args( $q->query_vars );
 *              if ( isset( $args['post__in'] ) ) {
 *                  $q->set( 'post__in', $args['post__in'] );
 *              }
 *          } );
 *
 *      register_hooks() loads the helper file; the logic lives here in
 *      query_args() so it's unit-testable with a mocked resolver.
 *
 * Placement, unlike binding, *does* get a native surface: a classic-API
 * ET_Builder_Module subclass (integrations/divi/modules/facet.php) registered
 * on `et_builder_ready`, mirroring the Elementor widget and Bricks element.
 * The live-site frontend render is a plain server render through the shared
 * Renderer; Visual Builder behavior (the module rendering inside Divi's React
 * editor via its server-render-over-AJAX path) is the part still pending
 * validation against a live Divi install. The [hof_facet] shortcode remains a
 * fallback placement surface.
 *
 * The binding helper has no presence gate (there's nothing to register
 * against for query binding, and the helper is inert until a snippet calls
 * it, so register_hooks() loads it unconditionally). Module registration does
 * gate, inside register_module(), on \ET_Builder_Module being present.
 *
 * If Divi later ships a scoped query filter, the binding should move to that
 * hook and this recipe becomes a fallback.
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

final class Divi implements Bootable {

    public function __construct(
        private readonly IdResolver $resolver,
        private readonly ?LoopHookRecorder $recorder = null,
    ) {}

    public function register_hooks(): void {
        // Load the global hof_divi_query_args() template tag. It's a bare
        // function definition (no side effects), only ever invoked from a
        // user's query snippet, so it's safe to define unconditionally.
        require_once HOF_PLUGIN_DIR . 'integrations/divi/helpers.php';

        // Native placement module registration. `et_builder_ready` is a
        // passive hook — it only fires if Divi's builder boots — so it's safe
        // to register at boot with no presence check; the gate lives in
        // register_module(). Priority 9999 per Divi's documented guidance,
        // so we register after the builder has finished setting itself up.
        add_action( 'et_builder_ready', [ $this, 'register_module' ], 9999 );
    }

    /**
     * et_builder_ready handler. Registers the Hooked Facet module once Divi's
     * builder is confirmed present; no-ops otherwise.
     *
     * Classic Divi modules self-register from their constructor, so the file
     * is require_once'd just-in-time (its parent \ET_Builder_Module only
     * exists at this point) and a single instance is created.
     */
    public function register_module(): void {
        if ( ! $this->is_divi_loaded() ) {
            return;
        }

        require_once HOF_PLUGIN_DIR . 'integrations/divi/modules/facet.php';

        new \HookedOnFacets\Integrations\Divi\FacetModule();
    }

    private function is_divi_loaded(): bool {
        return class_exists( '\ET_Builder_Module' );
    }

    /**
     * Merge HOF's URL-derived filter state into a Divi loop's WP_Query args.
     *
     * The caller passes their loop's base args (post type, ordering,
     * per-page) and we layer post__in on top — HOF wins on post__in when
     * filters are active.
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
        // QueryHook and the Elementor / Bricks / Breakdance bridges on the
        // same case.
        $base_args['post__in'] = ! empty( $ids ) ? $ids : [ 0 ];

        $this->recorder?->record_loop_hook( 'divi:' . $this->guess_post_type( $base_args ) );

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
