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

use HookedOnFacets\Ai\NlFilter;
use HookedOnFacets\Ai\Settings as AiSettings;
use HookedOnFacets\Contracts\Bootable;
use HookedOnFacets\Filter\Resolver;
use HookedOnFacets\Indexer;
use HookedOnFacets\Integrations\Acf;
use HookedOnFacets\Integrations\MetaBox;
use HookedOnFacets\Integrations\Pods;
use HookedOnFacets\Integrations\WooCommerce;
use HookedOnFacets\Licensing\LicenseManager;
use HookedOnFacets\Seo\SeoManager;
use HookedOnFacets\Telemetry\Recorder;

defined( 'ABSPATH' ) || exit;

final class RestController implements Bootable {

    public const NAMESPACE_V1 = 'hof/v1';

    public function __construct(
        private readonly Resolver $resolver,
        private readonly Indexer $indexer,
        private readonly ?Recorder $recorder = null,
        private readonly ?NlFilter $nl_filter = null,
        private readonly ?AiSettings $ai_settings = null,
        private readonly ?LicenseManager $license = null,
        private readonly ?WooCommerce $woocommerce = null,
        private readonly ?Acf $acf = null,
        private readonly ?MetaBox $metabox = null,
        private readonly ?Pods $pods = null,
        private readonly ?SeoManager $seo = null,
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
            'args'                => [
                'mode' => [
                    'type'     => 'string',
                    'enum'     => [ 'sync', 'background' ],
                    'required' => false,
                ],
            ],
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

        register_rest_route( self::NAMESPACE_V1, '/ask', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'ask' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'query' => [
                    'type'     => 'string',
                    'required' => true,
                ],
                'prior_state' => [
                    'type'     => 'object',
                    'required' => false,
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE_V1, '/visual-dna', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'visual_dna' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'hex' => [
                    'type'     => 'string',
                    'required' => false,
                ],
                'hexes' => [
                    'type'     => 'array',
                    'items'    => [ 'type' => 'string' ],
                    'required' => false,
                ],
                'limit' => [
                    'type'     => 'integer',
                    'required' => false,
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE_V1, '/ai-settings', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_ai_settings' ],
                'permission_callback' => static fn() => current_user_can( 'manage_options' ),
            ],
            [
                'methods'             => \WP_REST_Server::EDITABLE,
                'callback'            => [ $this, 'save_ai_settings' ],
                'permission_callback' => static fn() => current_user_can( 'manage_options' ),
                'args'                => [
                    'api_key' => [
                        'type'     => 'string',
                        'required' => false,
                    ],
                ],
            ],
        ] );

        register_rest_route( self::NAMESPACE_V1, '/seo-settings', [
            [
                'methods'             => \WP_REST_Server::READABLE,
                'callback'            => [ $this, 'get_seo_settings' ],
                'permission_callback' => static fn() => current_user_can( 'manage_options' ),
            ],
            [
                'methods'             => \WP_REST_Server::EDITABLE,
                'callback'            => [ $this, 'save_seo_settings' ],
                'permission_callback' => static fn() => current_user_can( 'manage_options' ),
            ],
        ] );

        register_rest_route( self::NAMESPACE_V1, '/license', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_license' ],
            'permission_callback' => static fn() => current_user_can( 'manage_options' ),
        ] );

        register_rest_route( self::NAMESPACE_V1, '/license/activate', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'activate_license' ],
            'permission_callback' => static fn() => current_user_can( 'manage_options' ),
            'args'                => [
                'key' => [ 'type' => 'string', 'required' => true ],
            ],
        ] );

        register_rest_route( self::NAMESPACE_V1, '/license/deactivate', [
            'methods'             => \WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'deactivate_license' ],
            'permission_callback' => static fn() => current_user_can( 'manage_options' ),
        ] );

        register_rest_route( self::NAMESPACE_V1, '/integrations/woocommerce/suggest', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'suggest_woocommerce_facets' ],
            'permission_callback' => static fn() => current_user_can( 'manage_options' ),
        ] );

        register_rest_route( self::NAMESPACE_V1, '/integrations/acf/suggest', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'suggest_acf_facets' ],
            'permission_callback' => static fn() => current_user_can( 'manage_options' ),
        ] );

        register_rest_route( self::NAMESPACE_V1, '/integrations/metabox/suggest', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'suggest_metabox_facets' ],
            'permission_callback' => static fn() => current_user_can( 'manage_options' ),
        ] );

        register_rest_route( self::NAMESPACE_V1, '/integrations/pods/suggest', [
            'methods'             => \WP_REST_Server::READABLE,
            'callback'            => [ $this, 'suggest_pods_facets' ],
            'permission_callback' => static fn() => current_user_can( 'manage_options' ),
        ] );
    }

    public function suggest_woocommerce_facets( \WP_REST_Request $request ): \WP_REST_Response {
        if ( ! $this->woocommerce || ! $this->woocommerce->is_active() ) {
            return new \WP_REST_Response( [
                'available' => false,
                'reason'    => 'WooCommerce not active on this site.',
                'facets'    => [],
            ], 200 );
        }
        $existing = (array) get_option( Indexer::OPTION_FACETS, [] );
        return new \WP_REST_Response( [
            'available' => true,
            'facets'    => $this->woocommerce->suggest( $existing ),
        ], 200 );
    }

    public function suggest_acf_facets( \WP_REST_Request $request ): \WP_REST_Response {
        if ( ! $this->acf || ! $this->acf->is_active() ) {
            return new \WP_REST_Response( [
                'available' => false,
                'reason'    => 'Advanced Custom Fields not active on this site.',
                'facets'    => [],
            ], 200 );
        }
        $existing = (array) get_option( Indexer::OPTION_FACETS, [] );
        return new \WP_REST_Response( [
            'available' => true,
            'facets'    => $this->acf->suggest( $existing ),
        ], 200 );
    }

    public function suggest_metabox_facets( \WP_REST_Request $request ): \WP_REST_Response {
        if ( ! $this->metabox || ! $this->metabox->is_active() ) {
            return new \WP_REST_Response( [
                'available' => false,
                'reason'    => 'Meta Box not active on this site.',
                'facets'    => [],
            ], 200 );
        }
        $existing = (array) get_option( Indexer::OPTION_FACETS, [] );
        return new \WP_REST_Response( [
            'available' => true,
            'facets'    => $this->metabox->suggest( $existing ),
        ], 200 );
    }

    public function suggest_pods_facets( \WP_REST_Request $request ): \WP_REST_Response {
        if ( ! $this->pods || ! $this->pods->is_active() ) {
            return new \WP_REST_Response( [
                'available' => false,
                'reason'    => 'Pods not active on this site.',
                'facets'    => [],
            ], 200 );
        }
        $existing = (array) get_option( Indexer::OPTION_FACETS, [] );
        return new \WP_REST_Response( [
            'available' => true,
            'facets'    => $this->pods->suggest( $existing ),
        ], 200 );
    }

    public function get_license( \WP_REST_Request $request ): \WP_REST_Response {
        if ( ! $this->license ) {
            return new \WP_REST_Response( [ 'configured' => false, 'status' => 'unavailable' ], 200 );
        }
        return new \WP_REST_Response( $this->license->state(), 200 );
    }

    public function activate_license( \WP_REST_Request $request ): \WP_REST_Response {
        if ( ! $this->license ) {
            return new \WP_REST_Response( [ 'ok' => false, 'error' => 'License manager unavailable' ], 503 );
        }
        $key    = (string) $request->get_param( 'key' );
        $result = $this->license->activate( $key );
        return new \WP_REST_Response(
            array_merge( $result, [ 'state' => $this->license->state() ] ),
            $result['ok'] ? 200 : 400
        );
    }

    public function deactivate_license( \WP_REST_Request $request ): \WP_REST_Response {
        if ( ! $this->license ) {
            return new \WP_REST_Response( [ 'ok' => false, 'error' => 'License manager unavailable' ], 503 );
        }
        $this->license->deactivate();
        return new \WP_REST_Response( [ 'ok' => true, 'state' => $this->license->state() ], 200 );
    }

    public function get_ai_settings( \WP_REST_Request $request ): \WP_REST_Response {
        if ( ! $this->ai_settings ) {
            return new \WP_REST_Response( [ 'configured' => false ], 200 );
        }
        $key = $this->ai_settings->api_key();
        return new \WP_REST_Response( [
            'configured' => $key !== '',
            'fingerprint' => $key !== '' ? substr( $key, 0, 14 ) . '…' . substr( $key, -6 ) : '',
            'model'       => apply_filters( 'hof_ai_model', NlFilter::MODEL_HAIKU ),
        ], 200 );
    }

    public function save_ai_settings( \WP_REST_Request $request ): \WP_REST_Response {
        if ( ! $this->ai_settings ) {
            return new \WP_REST_Response( [ 'ok' => false, 'error' => 'AI settings unavailable' ], 503 );
        }
        // Accept null/empty to clear. Trim whitespace from paste.
        $raw = $request->get_param( 'api_key' );
        $key = is_string( $raw ) ? trim( $raw ) : '';
        $this->ai_settings->set_api_key( $key );
        return $this->get_ai_settings( $request );
    }

    public function get_seo_settings( \WP_REST_Request $request ): \WP_REST_Response {
        if ( ! $this->seo ) {
            return new \WP_REST_Response( [ 'available' => false, 'settings' => SeoManager::defaults() ], 200 );
        }
        return new \WP_REST_Response( [
            'available' => true,
            'settings'  => $this->seo->settings(),
        ], 200 );
    }

    public function save_seo_settings( \WP_REST_Request $request ): \WP_REST_Response {
        if ( ! $this->seo ) {
            return new \WP_REST_Response( [ 'ok' => false, 'error' => 'SEO settings unavailable' ], 503 );
        }
        // Coerce only the known keys against the defaults' types — unknown keys
        // are dropped, missing keys keep their default.
        $raw   = (array) $request->get_param( 'settings' );
        $clean = [];
        foreach ( SeoManager::defaults() as $key => $default ) {
            if ( ! array_key_exists( $key, $raw ) ) {
                continue;
            }
            $clean[ $key ] = is_bool( $default )
                ? (bool) $raw[ $key ]
                : ( is_int( $default ) ? max( 1, (int) $raw[ $key ] ) : sanitize_text_field( (string) $raw[ $key ] ) );
        }
        $this->seo->update_settings( $clean );
        return $this->get_seo_settings( $request );
    }

    public function ask( \WP_REST_Request $request ): \WP_REST_Response {
        if ( ! $this->nl_filter ) {
            return new \WP_REST_Response(
                [ 'ok' => false, 'error' => 'Ask is not available', 'error_code' => 'unavailable' ],
                503
            );
        }

        $query       = (string) $request->get_param( 'query' );
        $prior_state = $request->get_param( 'prior_state' );
        $prior_state = is_array( $prior_state ) ? $prior_state : [];

        $result = $this->nl_filter->translate( $query, $prior_state );

        if ( ! $result['ok'] ) {
            $status = match ( $result['error_code'] ?? '' ) {
                'empty_query'      => 400,
                'no_api_key'       => 503,
                'no_facets'        => 503,
                'authentication_error' => 503,
                default            => 502,
            };
            return new \WP_REST_Response( $result, $status );
        }

        return new \WP_REST_Response( $result, 200 );
    }

    /**
     * Visual DNA v2 — accept a hex color, return the top-K product IDs
     * ranked by ΔE76 distance against the indexed `_visual_dna_lab` rows.
     *
     * SQL math runs the conversion against pre-extracted LAB coords — no
     * per-product image processing happens here, so 10k+ catalogs respond
     * in tens of ms.
     */
    public function visual_dna( \WP_REST_Request $request ): \WP_REST_Response {
        global $wpdb;

        // v3: accept either single `hex` (back-compat) or `hexes` array.
        // Build the query palette as a list of LAB triplets.
        $hexes_param = $request->get_param( 'hexes' );
        $single_hex  = $request->get_param( 'hex' );

        $query_hexes = [];
        if ( is_array( $hexes_param ) ) {
            foreach ( $hexes_param as $h ) {
                if ( is_scalar( $h ) ) $query_hexes[] = (string) $h;
            }
        }
        if ( $single_hex !== null && is_scalar( $single_hex ) ) {
            $query_hexes[] = (string) $single_hex;
        }

        $query_labs = [];
        foreach ( $query_hexes as $h ) {
            $lab = \HookedOnFacets\VisualDna\ColorExtractor::hex_to_lab( $h );
            if ( $lab !== null ) {
                $query_labs[] = [ 'hex' => $h, 'L' => $lab['L'], 'a' => $lab['a'], 'b' => $lab['b'] ];
            }
        }
        if ( empty( $query_labs ) ) {
            return new \WP_REST_Response(
                [ 'ok' => false, 'error' => 'no valid color in hex/hexes', 'error_code' => 'invalid_hex' ],
                400
            );
        }

        $limit = (int) $request->get_param( 'limit' );
        if ( $limit <= 0 ) $limit = 60;
        $limit = min( 500, $limit );

        $table = $wpdb->prefix . \HookedOnFacets\Activator::TABLE;

        // Per-product ΔE = MIN over the cross product (query palette × product
        // palette). We compute one ΔE expression per query color, wrap them
        // in LEAST(...) when multi, then aggregate MIN across the product's
        // palette rows via GROUP BY.
        $params   = [];
        $exprs    = [];
        foreach ( $query_labs as $q ) {
            $exprs[]  = "SQRT(POW(lab_l - %f, 2) + POW(lab_a - %f, 2) + POW(lab_b - %f, 2))";
            $params[] = $q['L'];
            $params[] = $q['a'];
            $params[] = $q['b'];
        }
        $row_de = count( $exprs ) === 1
            ? $exprs[0]
            : ( 'LEAST(' . implode( ', ', $exprs ) . ')' );

        $params[] = $limit;

        $sql = $wpdb->prepare(
            "SELECT object_id, MIN({$row_de}) AS delta_e
             FROM {$table}
             WHERE facet_name = '_visual_dna_lab'
               AND lab_l IS NOT NULL
             GROUP BY object_id
             ORDER BY delta_e ASC
             LIMIT %d",
            $params
        );
        $rows = $wpdb->get_results( $sql, ARRAY_A ) ?: [];

        $ids = [];
        foreach ( $rows as $r ) {
            $ids[] = (int) $r['object_id'];
        }

        // Indexed-count probe: number of distinct products with palette rows.
        $indexed = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT object_id) FROM {$table} WHERE facet_name = '_visual_dna_lab'"
        );

        return new \WP_REST_Response(
            [
                'ok'             => true,
                'query_palette'  => $query_labs,
                'ids'            => $ids,
                'indexed_count'  => $indexed,
                'returned_count' => count( $ids ),
            ],
            200
        );
    }

    public function telemetry( \WP_REST_Request $request ): \WP_REST_Response {
        if ( ! $this->recorder ) {
            return new \WP_REST_Response( [
                'resolver' => [ 'avg_ms' => null, 'p50_ms' => null, 'p95_ms' => null, 'p99_ms' => null, 'sample_size' => 0, 'total_calls' => 0 ],
                'loops'    => [ 'count' => 0, 'total_hits' => 0, 'top' => [], 'signatures' => [] ],
                'facets'   => [ 'usage' => [], 'top_values' => [], 'zero_results' => [], 'total' => 0 ],
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
        $allowed_kinds    = [ 'taxonomy', 'meta', 'field', 'view' ];
        $allowed_displays = [
            'checkbox', 'radio', 'dropdown', 'toggle', 'hierarchy',
            'range', 'date_range', 'search', 'swatch', 'swiper',
            'spin_the_wheel', 'matrix', 'saved_bin', 'ask', 'visual_dna', 'pagination',
        ];

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
                'settings' => $this->sanitize_settings( $def['settings'] ?? null, $display ),
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
    private function sanitize_settings( $raw, string $display = '' ): array {
        if ( ! is_array( $raw ) ) {
            return [];
        }

        // View facets carry display-specific orchestration settings, not the
        // swiper sandbox knobs. Dispatch on display so each shape gets its
        // own allowlist.
        if ( $display === 'ask' ) {
            $out = [];
            if ( isset( $raw['placeholder'] ) && is_scalar( $raw['placeholder'] ) ) {
                $out['placeholder'] = sanitize_text_field( (string) $raw['placeholder'] );
            }
            return array_filter( $out, static fn( $v ) => $v !== '' );
        }

        if ( $display === 'pagination' ) {
            $out = [];
            // null/empty per_page → fall back to WP posts_per_page at render
            // time; explicit zero is treated the same as null.
            if ( isset( $raw['per_page'] ) && is_numeric( $raw['per_page'] ) ) {
                $n = max( 0, (int) $raw['per_page'] );
                if ( $n > 0 ) $out['per_page'] = $n;
            }
            if ( isset( $raw['neighbors'] ) && is_numeric( $raw['neighbors'] ) ) {
                $out['neighbors'] = max( 0, min( 5, (int) $raw['neighbors'] ) );
            }
            if ( isset( $raw['show_first_last'] ) ) {
                $out['show_first_last'] = (bool) $raw['show_first_last'];
            }
            if ( isset( $raw['show_prev_next'] ) ) {
                $out['show_prev_next'] = (bool) $raw['show_prev_next'];
            }
            return $out;
        }

        if ( $display === 'toggle' ) {
            $out = [];
            if ( isset( $raw['true_value'] ) && is_scalar( $raw['true_value'] ) ) {
                $out['true_value'] = (string) $raw['true_value'];
            }
            if ( isset( $raw['on_label'] ) && is_scalar( $raw['on_label'] ) ) {
                $out['on_label'] = sanitize_text_field( (string) $raw['on_label'] );
            }
            if ( isset( $raw['off_label'] ) && is_scalar( $raw['off_label'] ) ) {
                $out['off_label'] = sanitize_text_field( (string) $raw['off_label'] );
            }
            return array_filter( $out, static fn( $v ) => $v !== '' );
        }

        if ( $display === 'date_range' ) {
            // 'date' = ISO yyyy-mm-dd; assumes the source meta is already
            // Unix timestamps in facet_numeric. See Facet-Type-Date-Range.md.
            $out = [];
            if ( isset( $raw['format'] ) && in_array( (string) $raw['format'], [ 'date', 'datetime' ], true ) ) {
                $out['format'] = (string) $raw['format'];
            }
            return $out;
        }

        if ( $display === 'visual_dna' ) {
            // target_facet = which color-bearing facet to filter against once
            // the input image/URL/eyedrop resolves to a hex.
            $out = [];
            if ( isset( $raw['target_facet'] ) && is_scalar( $raw['target_facet'] ) ) {
                $out['target_facet'] = sanitize_key( (string) $raw['target_facet'] );
            }
            return array_filter( $out, static fn( $v ) => $v !== '' );
        }

        // Default path — swiper sandbox knobs from the Blueprint UI.
        $allowed_variants   = [ 'Card', 'Grid', 'Swipe' ];
        $allowed_card_sizes = [ 'Small', 'Medium', 'Large' ];
        $allowed_animations = [ 'Spring', 'Linear' ];

        $variant    = isset( $raw['variant'] )   && in_array( (string) $raw['variant'],   $allowed_variants,   true ) ? (string) $raw['variant']   : null;
        $card_size  = isset( $raw['cardSize'] )  && in_array( (string) $raw['cardSize'],  $allowed_card_sizes, true ) ? (string) $raw['cardSize']  : null;
        $animation  = isset( $raw['animation'] ) && in_array( (string) $raw['animation'], $allowed_animations, true ) ? (string) $raw['animation'] : null;
        $deck_depth = isset( $raw['deckDepth'] ) ? max( 1, min( 10, (int) $raw['deckDepth'] ) ) : null;

        // match = 'all' turns a multi-value facet into AND-within-facet (an
        // object must carry every selected value); 'any' (default) is OR.
        $match = isset( $raw['match'] ) && in_array( (string) $raw['match'], [ 'any', 'all' ], true ) ? (string) $raw['match'] : null;

        return array_filter( [
            'variant'   => $variant,
            'cardSize'  => $card_size,
            'deckDepth' => $deck_depth,
            'animation' => $animation,
            'match'     => $match,
        ], static fn( $v ) => $v !== null );
    }

    public function apply_filter( \WP_REST_Request $request ): \WP_REST_Response {
        $raw_filters = $request->get_param( 'filters' );
        $filters     = Resolver::sanitize_filter_state( is_array( $raw_filters ) ? $raw_filters : [] );

        // Visual DNA v2 — optional ordered ID restriction passed as a sibling
        // param. Fold it into the filter state under the reserved key so the
        // resolver sees it the same way it would from URL state.
        $raw_visual_ids = $request->get_param( 'visual_ids' );
        if ( is_array( $raw_visual_ids ) && ! empty( $raw_visual_ids ) ) {
            $filters['_visual_ids'] = $raw_visual_ids;
            // Re-sanitize to coerce types + drop garbage.
            $filters = Resolver::sanitize_filter_state( $filters );
        }

        $page     = max( 1, (int) $request->get_param( 'page' ) );
        $per_page = max( 1, min( 100, (int) $request->get_param( 'per_page' ) ) );

        $result = $this->resolver->resolve( $filters );

        // Pagination is in-PHP for now — small enough lists for MVP. When
        // result sets get huge, push LIMIT/OFFSET into the resolver SQL.
        $total    = is_array( $result['ids'] ) ? count( $result['ids'] ) : null;
        $page_ids = is_array( $result['ids'] )
            ? array_slice( $result['ids'], ( $page - 1 ) * $per_page, $per_page )
            : null;

        // One usage signal per filter action — facet/value popularity and
        // zero-result combos for the admin analytics. Skipped when nothing is
        // filtered (record_filter_usage drops empty / reserved-only states).
        if ( $result['ids'] !== null ) {
            $this->recorder?->record_filter_usage( $filters, (int) $total );
        }

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
        // Background mode — queue a chunked job (Action Scheduler when
        // available, else wp_cron) and return immediately so the admin doesn't
        // tie up PHP-FPM for large catalogs. The Indexer owns the decision of
        // whether a background run is viable here.
        $mode = (string) ( $request->get_param( 'mode' ) ?? 'background' );
        if ( $mode === 'background' && $this->indexer->can_run_background() ) {
            $job = $this->indexer->queue_reindex_all();
            return new \WP_REST_Response( array_merge( [
                'mode' => 'background',
                'job'  => $job,
            ], $this->collect_index_stats() ), 202 );
        }

        // Synchronous fallback — original behavior. Used by CLI + when no
        // background scheduler is available or `mode=sync` is explicit.
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 0 );
        }
        ignore_user_abort( true );

        $started = microtime( true );
        $count   = $this->indexer->reindex_all();
        $elapsed = microtime( true ) - $started;

        $stats = $this->collect_index_stats();

        return new \WP_REST_Response( array_merge( [
            'mode'    => 'sync',
            'indexed' => $count,
            'elapsed' => round( $elapsed, 3 ),
        ], $stats ), 200 );
    }

    public function reindex_status( \WP_REST_Request $request ): \WP_REST_Response {
        return new \WP_REST_Response( array_merge(
            $this->collect_index_stats(),
            [ 'background' => $this->indexer->background_state() ]
        ), 200 );
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
