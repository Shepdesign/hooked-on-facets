<?php
/**
 * QueryHook — the Auto-Hook Engine.
 *
 * Intercepts WP_Query loops and applies HOF filters without per-builder
 * configuration. Two attachment paths:
 *
 *   1. Main query, indexed post type, request carries facet state (?hof[*]
 *      params or a pretty /filter/ path).
 *      → server-rendered initial state matches client state, no flash.
 *
 *   2. Any query with the `hof_facet_target` query var set true.
 *      → opt-in for Gutenberg blocks, shortcodes, custom WP_Query loops.
 *
 * Both routes funnel through Resolver — same SQL, same correctness guarantees.
 *
 * @package HookedOnFacets
 */

declare(strict_types=1);

namespace HookedOnFacets;

use HookedOnFacets\Contracts\Bootable;
use HookedOnFacets\Filter\Resolver;
use HookedOnFacets\Telemetry\Recorder;

defined( 'ABSPATH' ) || exit;

final class QueryHook implements Bootable {

    public function __construct(
        private readonly Resolver $resolver,
        private readonly ?Recorder $recorder = null,
    ) {}

    public function register_hooks(): void {
        // Priority 9 → run before WC's own pre_get_posts at 10, so our post__in
        // is already in place when WC builds its query.
        add_action( 'pre_get_posts', [ $this, 'on_pre_get_posts' ], 9, 1 );
    }

    public function on_pre_get_posts( \WP_Query $query ): void {
        if ( is_admin() ) {
            return;
        }
        if ( ! $this->should_intercept( $query ) ) {
            return;
        }

        $filter_state = $this->collect_filter_state( $query );
        if ( empty( $filter_state ) ) {
            return;
        }

        $ids = $this->resolver->resolve_ids( $filter_state );
        if ( $ids === null ) {
            return; // No recognized filters resolved → leave query alone.
        }

        // [0] is the canonical "no results" sentinel for post__in.
        $query->set( 'post__in', ! empty( $ids ) ? $ids : [ 0 ] );

        // Stash the resolved state so other layers (e.g. result-region JSON
        // payload, block render callbacks) can read what was applied.
        $query->set( 'hof_applied_state', $filter_state );

        $this->recorder?->record_loop_hook( $this->loop_signature( $query ) );
    }

    /**
     * Build a stable, low-cardinality signature for the loop we just hooked,
     * for the admin telemetry counter.
     *
     * Format: <intercept_type>:<post_type>
     *   intercept_type ∈ { archive, tax, search, home, opt_in }
     *   post_type      ∈ first detected post type, or 'unknown'
     */
    private function loop_signature( \WP_Query $query ): string {
        $post_type = (array) ( $query->get( 'post_type' ) ?: $this->guess_post_type_from_query( $query ) );
        $pt        = $post_type[0] ?? 'unknown';

        if ( $query->get( 'hof_facet_target' ) ) {
            return "opt_in:{$pt}";
        }
        if ( $query->is_post_type_archive() ) {
            return "archive:{$pt}";
        }
        if ( $query->is_tax() ) {
            return "tax:{$pt}";
        }
        if ( $query->is_search() ) {
            return "search:{$pt}";
        }
        if ( $query->is_home() ) {
            return "home:{$pt}";
        }
        return "main:{$pt}";
    }

    /**
     * Two gates: main query on an indexed archive, OR opt-in via query var.
     */
    private function should_intercept( \WP_Query $query ): bool {
        if ( $query->get( 'hof_facet_target' ) ) {
            return true;
        }

        if ( ! $query->is_main_query() ) {
            return false;
        }

        // Heuristic: a result-displaying view of an indexed post type.
        if ( ! ( $query->is_post_type_archive() || $query->is_tax() || $query->is_search() || $query->is_home() ) ) {
            return false;
        }

        $post_type    = (array) ( $query->get( 'post_type' ) ?: $this->guess_post_type_from_query( $query ) );
        $indexed_pts  = (array) apply_filters( 'hof_indexed_post_types', [ 'post', 'page', 'product' ] );
        if ( empty( array_intersect( $post_type, $indexed_pts ) ) ) {
            return false;
        }

        // Only intercept main query if the URL actually carries facet state —
        // legacy ?hof[*] params or a pretty /filter/ path.
        $hof_path = $query->get( 'hof_path' );
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return ! empty( $_GET['hof'] ?? null ) || ( is_string( $hof_path ) && '' !== $hof_path );
    }

    /**
     * Filter state precedence:
     *   1. query var `hof_filters` (programmatic / block attribute)
     *   2. URL state (pretty /filter/ path ⊕ ?hof[*], via
     *      Resolver::parse_request_filters)
     *
     * @return array<string, mixed>
     */
    private function collect_filter_state( \WP_Query $query ): array {
        $programmatic = $query->get( 'hof_filters' );
        if ( is_array( $programmatic ) && ! empty( $programmatic ) ) {
            return Resolver::sanitize_filter_state( $programmatic );
        }
        return Resolver::parse_request_filters();
    }

    /**
     * @return string[]
     */
    private function guess_post_type_from_query( \WP_Query $query ): array {
        if ( $query->is_search() ) {
            return [ 'post', 'page' ];
        }
        if ( $query->is_home() ) {
            return [ 'post' ];
        }
        return [];
    }
}
