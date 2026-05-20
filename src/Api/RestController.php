<?php
/**
 * RestController — exposes HOF over /wp-json/hof/v1/*.
 *
 * Endpoints:
 *   GET  /facets              → list configured facet definitions
 *   POST /filter              → resolve filter state to IDs + drill-down counts
 *   POST /reindex (admin)     → trigger full reindex
 *   GET  /reindex/status (admin) → current index stats (rows, objects, per-facet)
 *   GET  /telemetry (admin)   → resolver timings + hooked-loop counts
 *   DELETE /telemetry (admin) → reset all telemetry counters
 *
 * @package HookedOnFacets
 */

declare(strict_types=1);

namespace HookedOnFacets\Api;

use HookedOnFacets\Contracts\Bootable;
use HookedOnFacets\Filter\Resolver;
use HookedOnFacets\Indexer;
use HookedOnFacets\Telemetry\Recorder;

defined( 'ABSPATH' ) || exit;

final class RestController implements Bootable {

    public const NAMESPACE_V1 = 'hof/v1';

    public function __construct(
        private readonly Resolver $resolver,
        private readonly Indexer $indexer,
        private readonly ?Recorder $recorder = null,
    ) {}

    public function register_hooks(): void {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public function register_routes(): void {
        register_rest_route( self::NAMESPACE_V1, '/facets', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'list_facets' ],
            'permission_callback' => '__return_true',
        ] );

        register_rest_route( self::NAMESPACE_V1, '/facets', [
            'methods'             => \WP_REST_Server::EDITABLE,
            'callback'            => [ $this, 'save_facets' ],
            'permission_callback' => static fn() => current_user_can( 'manage_options' ),
            'args'                => [
                'facets' => [
                    'type'     => 'array',
                    'required' => true,
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE_V1, '/filter', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'apply_filter' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'filters'  => [
                    'type'     => 'object',
                    'required' => false,
                    'default'  => [],
                ],
                'page'     => [
                    'type'    => 'integer',
                    'default' => 1,
                    'minimum' => 1,
                ],
                'per_page' => [
                    'type'    => 'integer',
                    'default' => 20,
                    'minimum' => 1,
                    'maximum' => 100,
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE_V1, '/reindex', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'reindex' ],
            'permission_callback' => static fn() => current_user_can( 'manage_options' ),
        ] );

        register_rest_route( self::NAMESPACE_V1, '/reindex/status', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'reindex_status' ],
            'permission_callback' => static fn() => current_user_can( 'manage_options' ),
        ] );

        register_rest_route( self::NAMESPACE_V1, '/telemetry', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'telemetry' ],
                'permission_callback' => static fn() => current_user_can( 'manage_options' ),
            ],
            [
                'methods'             => \WP_REST_Server::DELETABLE,
                'callback'            => [ $this, 'reset_telemetry' ],
                'permission_callback' => static fn() => current_user_can( 'manage_options' ),
            ],
        ] );
    }

    public function telemetry( \WP_REST_Request $request ): \WP_REST_Response {
        if ( ! $this->recorder ) {
            return new \WP_REST_Response( [
                'resolver' => [ 'avg_ms' => null, 'p95_ms' => null, 'sample_size' => 0, 'total_calls' => 0 ],
                'loops'    => [ 'count' => 0, 'total_hits' => 0, 'top' => [], 'signatures' => [] ],
            ], 200 );
        }
        return new \WP_REST_Response( $this->recorder->snapshot(), 200 );
    }

    public function reset_telemetry( \WP_REST_Request $request ): \WP_REST_Response {
        $this->recorder?->reset();
        return new \WP_REST_Response( $this->recorder ? $this->recorder->snapshot() : [], 200 );
    }

    public function list_facets( \WP_REST_Request $request ): \WP_REST_Response {
        $facets = (array) get_option( Indexer::OPTION_FACETS, [] );

        return new \WP_REST_Response( [
            'facets' => array_values( $facets ),
        ], 200 );
    }

    public function save_facets( \WP_REST_Request $request ): \WP_REST_Response {
        $raw = $request->get_param( 'facets' );
        if ( ! is_array( $raw ) ) {
            return new \WP_REST_Response( [ 'message' => 'Invalid facets payload.' ], 400 );
        }

        $clean = $this->sanitize_facets( $raw );
        update_option( Indexer::OPTION_FACETS, $clean, true );

        return new \WP_REST_Response( [
            'facets' => $clean,
            'saved'  => true,
        ], 200 );
    }

    /**
     * @param array<int, mixed> $raw
     * @return array<int, array<string, mixed>>
     */
    private function sanitize_facets( array $raw ): array {
        $allowed_kinds    = [ 'taxonomy', 'meta', 'field' ];
        $allowed_displays = [ 'checkbox', 'range', 'search', 'swatch', 'swiper', 'venn' ];

        $clean = [];
        $seen  = [];
        foreach ( $raw as $def ) {
            if ( ! is_array( $def ) ) {
                continue;
            }

            $name = isset( $def['name'] ) ? sanitize_key( (string) $def['name'] ) : '';
            if ( $name === '' || isset( $seen[ $name ] ) ) {
                continue;
            }

            $kind = isset( $def['kind'] ) ? (string) $def['kind'] : '';
            if ( ! in_array( $kind, $allowed_kinds, true ) ) {
                continue;
            }

            $display = isset( $def['display'] ) ? (string) $def['display'] : 'checkbox';
            if ( ! in_array( $display, $allowed_displays, true ) ) {
                $display = 'checkbox';
            }

            $clean[]       = [
                'name'     => $name,
                'label'    => isset( $def['label'] ) ? sanitize_text_field( (string) $def['label'] ) : $name,
                'source'   => isset( $def['source'] ) ? sanitize_text_field( (string) $def['source'] ) : '',
                'kind'     => $kind,
                'display'  => $display,
                'settings' => $this->sanitize_settings( $def['settings'] ?? null ),
            ];
            $seen[ $name ] = true;
        }

        return $clean;
    }

    /**
     * Sanitize per-facet display settings written by the Blueprint sandbox.
     *
     * Unknown keys are dropped; missing keys default to null so the runtime
     * can fall back to its own defaults without checking for existence.
     *
     * @param mixed $raw
     * @return array<string, mixed>
     */
    private function sanitize_settings( $raw ): array {
        if ( ! is_array( $raw ) ) {
            return [];
        }

        $allowed_variants   = [ 'Card', 'Grid', 'Swipe' ];
        $allowed_card_sizes = [ 'Small', 'Medium', 'Large' ];
        $allowed_animations = [ 'Spring', 'Linear' ];

        $variant    = isset( $raw['variant'] )   && in_array( (string) $raw['variant'],   $allowed_variants,   true ) ? (string) $raw['variant']   : null;
        $card_size  = isset( $raw['cardSize'] )  && in_array( (string) $raw['cardSize'],  $allowed_card_sizes, true ) ? (string) $raw['cardSize']  : null;
        $animation  = isset( $raw['animation'] ) && in_array( (string) $raw['animation'], $allowed_animations, true ) ? (string) $raw['animation'] : null;
        $deck_depth = isset( $raw['deckDepth'] ) ? max( 1, min( 10, (int) $raw['deckDepth'] ) ) : null;

        return array_filter( [
            'variant'   => $variant,
            'cardSize'  => $card_size,
            'deckDepth' => $deck_depth,
            'animation' => $animation,
        ], static fn( $v ) => $v !== null );
    }

    public function apply_filter( \WP_REST_Request $request ): \WP_REST_Response {
        $raw_filters = $request->get_param( 'filters' );
        $filters     = Resolver::sanitize_filter_state( is_array( $raw_filters ) ? $raw_filters : [] );

        $page     = max( 1, (int) $request->get_param( 'page' ) );
        $per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );

        $result = $this->resolver->resolve( $filters );

        // Pagination is in-PHP for now — small enough lists for MVP. When
        // result sets get huge, push LIMIT/OFFSET into the resolver SQL.
        $total    = is_array( $result['ids'] ) ? count( $result['ids'] ) : null;
        $page_ids = is_array( $result['ids'] )
            ? array_slice( $result['ids'], ( $page - 1 ) * $per_page, $per_page )
            : null;

        return new \WP_REST_Response( [
            'ids'         => $page_ids,
            'total'       => $total,
            'counts'      => $result['counts'],
            'page'        => $page,
            'per_page'    => $per_page,
            'unfiltered'  => $result['ids'] === null,
        ], 200 );
    }

    public function reindex( \WP_REST_Request $request ): \WP_REST_Response {
        // Large catalogs can exceed PHP's default 30s ceiling. The indexer's
        // bulk path is fast (~20s/100k) but a 500k site would still trip the
        // limit. Disable the wall clock for this endpoint only.
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }
        ignore_user_abort( true );

        $started = microtime( true );
        $count   = $this->indexer->reindex_all();
        $elapsed = microtime( true ) - $started;

        $stats = $this->collect_index_stats();

        return new \WP_REST_Response( array_merge( [
            'indexed' => $count,
            'elapsed' => round( $elapsed, 3 ),
        ], $stats ), 200 );
    }

    public function reindex_status( \WP_REST_Request $request ): \WP_REST_Response {
        return new \WP_REST_Response( $this->collect_index_stats(), 200 );
    }

    /**
     * Snapshot of the index table for the admin Indexer view.
     *
     * @return array{rows: int, objects: int, by_facet: array<int, array{name: string, rows: int, objects: int}>}
     */
    private function collect_index_stats(): array {
        global $wpdb;
        $table = $wpdb->prefix . \HookedOnFacets\Activator::TABLE;

        $rows    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        $objects = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT object_id) FROM {$table}" );

        $by_facet_raw = $wpdb->get_results(
            "SELECT facet_name AS name,
                    COUNT(*) AS rows_n,
                    COUNT(DISTINCT object_id) AS objects_n
             FROM {$table}
             GROUP BY facet_name
             ORDER BY facet_name",
            ARRAY_A
        );

        $by_facet = [];
        foreach ( (array) $by_facet_raw as $r ) {
            $by_facet[] = [
                'name'    => (string) $r['name'],
                'rows'    => (int) $r['rows_n'],
                'objects' => (int) $r['objects_n'],
            ];
        }

        return [
            'rows'     => $rows,
            'objects'  => $objects,
            'by_facet' => $by_facet,
        ];
    }
}
