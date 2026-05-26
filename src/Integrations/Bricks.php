<?php
/**
 * Bricks Builder bridge.
 *
 * Two-part integration, mirroring the Elementor bridge:
 *
 *   1. Facet element — placement surface for Bricks users, mirror of the
 *      hof/facet Gutenberg block, the [hof_facet] shortcode, and the
 *      Elementor widget. The element class lives at
 *      integrations/bricks/elements/facet.php and is registered via
 *      \Bricks\Elements::register_element() inside an `init` callback,
 *      because it extends \Bricks\Element (which only exists when Bricks
 *      itself is loaded).
 *
 *   2. Query binding — a Bricks query-loop element (container / block /
 *      element with the Query control) is opted in by tagging it with a
 *      CSS class (default `hof`). We hook `bricks/posts/query_vars` and,
 *      when the rendering element carries a matching class, apply post__in
 *      from the URL's ?hof[*] state. The class list is filterable via
 *      `hof_bricks_query_ids` for sites with multiple bound loops or a
 *      different convention.
 *
 *      Bricks has no dedicated "Query ID" field the way Elementor does, so
 *      the binding identifier is applied as a CSS class — repeatable across
 *      loops, and it doesn't force a unique HTML id the way matching on the
 *      element's CSS ID would. The filter keeps the `_query_ids` name for
 *      symmetry with `hof_elementor_query_ids`.
 *
 * Boot timing differs from the Elementor bridge because Bricks ships as a
 * *theme*, loaded on `after_setup_theme` — AFTER our plugins_loaded:5 boot.
 * So we split the two halves:
 *
 *   - The query filter is passive: `add_filter('bricks/posts/query_vars')`
 *     costs nothing until Bricks actually fires it while building a query
 *     loop (template render, long after the theme is up). We register it at
 *     boot regardless — no presence check needed, and none would pass yet.
 *   - Element registration genuinely needs the \Bricks\Elements class, so it
 *     defers to `init` (priority 11, after Bricks registers its own
 *     elements) and gates on presence there. If Bricks isn't active, that
 *     callback returns early — no element registered, no cost.
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

final class Bricks implements Bootable {

    /**
     * Cache the per-request binding-class list so a slow filter callback
     * only runs once per request even if many loops render.
     *
     * @var string[]|null
     */
    private ?array $query_ids = null;

    public function __construct(
        private readonly IdResolver $resolver,
        private readonly ?LoopHookRecorder $recorder = null,
    ) {}

    public function register_hooks(): void {
        // Passive filter — inert unless Bricks fires it during a query loop.
        // Safe to register at boot even though the Bricks theme isn't up yet.
        add_filter( 'bricks/posts/query_vars', [ $this, 'bind_query_vars' ], 10, 3 );

        // Element registration needs \Bricks\Elements; defer to init:11 (after
        // Bricks registers its own elements) where the presence check holds.
        add_action( 'init', [ $this, 'register_element' ], 11 );
    }

    /**
     * init:11 handler. Registers the Hooked Facet placement element once
     * Bricks is confirmed present; no-ops otherwise.
     */
    public function register_element(): void {
        if ( ! $this->is_bricks_loaded() ) {
            return;
        }

        \Bricks\Elements::register_element(
            HOF_PLUGIN_DIR . 'integrations/bricks/elements/facet.php',
            'hof-facet',
            \HookedOnFacets\Integrations\Bricks\FacetElement::class,
        );
    }

    /**
     * bricks/posts/query_vars handler — applies HOF's URL-derived filter
     * state to a Bricks query loop's WP_Query args before Bricks runs it.
     *
     * Bricks passes the args by value and expects them returned, so unlike
     * the Elementor bridge (which mutates a WP_Query by reference) we read,
     * mutate, and return the array.
     *
     * @param array<string, mixed> $query_vars WP_Query args Bricks is building.
     * @param array<string, mixed> $settings   The query element's settings.
     * @param string               $element_id Bricks element id (unused).
     * @return array<string, mixed>
     */
    public function bind_query_vars( array $query_vars, array $settings, string $element_id = '' ): array {
        if ( ! $this->element_is_bound( $settings ) ) {
            return $query_vars;
        }

        $filter_state = Resolver::parse_request_filters();
        if ( empty( $filter_state ) ) {
            return $query_vars;
        }

        $ids = $this->resolver->resolve_ids( $filter_state );
        if ( $ids === null ) {
            return $query_vars; // No recognized filters resolved → leave loop alone.
        }

        // [0] is the canonical "no results" sentinel for post__in — matches
        // QueryHook's and the Elementor bridge's behavior on the same case.
        $query_vars['post__in'] = ! empty( $ids ) ? $ids : [ 0 ];

        $this->recorder?->record_loop_hook( 'bricks:' . $this->guess_post_type( $query_vars ) );

        return $query_vars;
    }

    /**
     * True when the rendering element carries one of our binding classes in
     * its Bricks CSS-classes field (`_cssClasses`, a space-separated string).
     *
     * @param array<string, mixed> $settings
     */
    private function element_is_bound( array $settings ): bool {
        $classes = $settings['_cssClasses'] ?? '';
        // Bricks stores this as a space-separated string; tolerate an array
        // too so a cast never silently collapses to the literal "Array".
        $raw = is_array( $classes ) ? implode( ' ', $classes ) : (string) $classes;
        if ( trim( $raw ) === '' ) {
            return false;
        }

        $tokens = preg_split( '/\s+/', trim( $raw ) ) ?: [];
        foreach ( $tokens as $token ) {
            $slug = sanitize_key( $token );
            if ( $slug !== '' && in_array( $slug, $this->query_ids(), true ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    private function query_ids(): array {
        if ( $this->query_ids !== null ) {
            return $this->query_ids;
        }

        /**
         * Filter the binding identifiers the Bricks bridge matches against.
         *
         * Default is a single identifier, `hof` — Bricks users add it as a
         * CSS class (Style → CSS classes) on the query-loop element they
         * want HOF to filter. Sites with multiple bound loops or a different
         * naming convention override here.
         *
         * @param string[] $ids
         */
        $ids = (array) apply_filters( 'hof_bricks_query_ids', [ 'hof' ] );

        $clean = [];
        foreach ( $ids as $id ) {
            $slug = sanitize_key( (string) $id );
            if ( $slug !== '' ) {
                $clean[] = $slug;
            }
        }

        return $this->query_ids = array_values( array_unique( $clean ) );
    }

    private function is_bricks_loaded(): bool {
        return class_exists( '\Bricks\Elements' );
    }

    /**
     * @param array<string, mixed> $query_vars
     */
    private function guess_post_type( array $query_vars ): string {
        $pt = $query_vars['post_type'] ?? '';
        if ( is_array( $pt ) ) {
            $pt = $pt[0] ?? '';
        }
        return is_string( $pt ) && $pt !== '' ? $pt : 'unknown';
    }
}
