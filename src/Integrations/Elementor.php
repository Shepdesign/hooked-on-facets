<?php
/**
 * Elementor bridge.
 *
 * Two-part integration:
 *
 *   1. Facet widget — placement surface for Elementor users, mirror of the
 *      hof/facet Gutenberg block and [hof_facet] shortcode. The widget
 *      class lives at integrations/elementor/widgets/facet.php and is
 *      required just-in-time from the elementor/widgets/register callback,
 *      because it extends \Elementor\Widget_Base (which only exists when
 *      Elementor itself is loaded).
 *
 *   2. Query binding — Elementor's Loop Grid / Posts / Products widgets
 *      can be tagged with a "Query ID" in the editor; we hook
 *      `elementor/query/{id}` and apply post__in directly from the
 *      URL's ?hof[*] state. Default ID is `hof`; the list is filterable
 *      via `hof_elementor_query_ids` for sites that want multiple bound
 *      loops on one page or a different convention.
 *
 *      We intentionally don't piggyback on QueryHook's pre_get_posts here.
 *      Elementor calls `$query->query($args)` AFTER its
 *      `elementor/query/{id}` action fires, and WP_Query::query() resets
 *      query_vars from $args — so a `$query->set('hof_facet_target', …)`
 *      flag would be discarded. Applying post__in directly is robust and
 *      matches Elementor's own documented mutation pattern.
 *
 * Like every Bootable, this service is always wired into the container.
 * If Elementor isn't loaded, register_hooks() returns early and the
 * service is inert — no add_action calls, no widget class load, no cost
 * beyond the boot-time presence check.
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

final class Elementor implements Bootable {

    /**
     * Cache the per-request query-ID list so a slow filter callback only
     * runs once per request even if multiple bound loops fire.
     *
     * @var string[]|null
     */
    private ?array $query_ids = null;

    public function __construct(
        private readonly IdResolver $resolver,
        private readonly ?LoopHookRecorder $recorder = null,
    ) {}

    public function register_hooks(): void {
        if ( ! $this->is_elementor_loaded() ) {
            return;
        }

        add_action( 'elementor/widgets/register', [ $this, 'register_widget' ] );

        foreach ( $this->query_ids() as $id ) {
            add_action( "elementor/query/{$id}", [ $this, 'bind_query' ] );
        }
    }

    /**
     * elementor/widgets/register handler.
     *
     * Registers the FacetWidget against the widgets_manager Elementor
     * passes us. The widget class is require_once'd just-in-time — its
     * parent (\Elementor\Widget_Base) is guaranteed to exist at this hook.
     *
     * @param object $widgets_manager Elementor\Widgets_Manager — typed
     *        loosely because the real class only exists at runtime when
     *        Elementor is active.
     */
    public function register_widget( object $widgets_manager ): void {
        require_once HOF_PLUGIN_DIR . 'integrations/elementor/widgets/facet.php';

        if ( method_exists( $widgets_manager, 'register' ) ) {
            $widgets_manager->register( new \HookedOnFacets\Integrations\Elementor\FacetWidget() );
        }
    }

    /**
     * elementor/query/{id} handler — applies HOF's URL-derived filter state
     * to a WP_Query before Elementor runs it.
     *
     * @param \WP_Query $query
     */
    public function bind_query( \WP_Query $query ): void {
        $filter_state = Resolver::parse_request_filters();
        if ( empty( $filter_state ) ) {
            return;
        }

        $ids = $this->resolver->resolve_ids( $filter_state );
        if ( $ids === null ) {
            return; // No recognized filters resolved → leave query alone.
        }

        // [0] is the canonical "no results" sentinel for post__in — matches
        // QueryHook's behavior on the same case.
        $query->set( 'post__in', ! empty( $ids ) ? $ids : [ 0 ] );
        $query->set( 'hof_applied_state', $filter_state );

        $this->recorder?->record_loop_hook( 'elementor:' . $this->guess_post_type( $query ) );
    }

    /**
     * @return string[]
     */
    private function query_ids(): array {
        if ( $this->query_ids !== null ) {
            return $this->query_ids;
        }

        /**
         * Filter the list of Elementor Query IDs the bridge binds to.
         *
         * Default is a single ID, `hof` — Elementor users set this in the
         * Loop Grid / Posts widget's "Advanced → Query ID" field, and HOF
         * filters the matching loop. Sites with multiple bound loops on
         * the same page (or a different naming convention) override here.
         *
         * @param string[] $ids
         */
        $ids = (array) apply_filters( 'hof_elementor_query_ids', [ 'hof' ] );

        $clean = [];
        foreach ( $ids as $id ) {
            $slug = sanitize_key( (string) $id );
            if ( $slug !== '' ) {
                $clean[] = $slug;
            }
        }

        return $this->query_ids = array_values( array_unique( $clean ) );
    }

    private function is_elementor_loaded(): bool {
        return did_action( 'elementor/loaded' ) > 0;
    }

    private function guess_post_type( \WP_Query $query ): string {
        $pt = $query->get( 'post_type' );
        if ( is_array( $pt ) ) {
            $pt = $pt[0] ?? '';
        }
        return is_string( $pt ) && $pt !== '' ? $pt : 'unknown';
    }
}
