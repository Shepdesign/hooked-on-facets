<?php
/**
 * Resolver — translates filter state into matching object IDs + facet counts.
 *
 * Single source of truth for the filter SQL. Used by both QueryHook (server
 * render path) and RestController (AJAX path). Designed around one core query
 * shape that hits the composite (facet_name, facet_value) index:
 *
 *   SELECT object_id FROM wp_hof_index
 *   WHERE (facet_name = 'A' AND ...)
 *      OR (facet_name = 'B' AND ...)
 *   GROUP BY object_id
 *   HAVING COUNT(DISTINCT facet_name) = N
 *
 * AND-across-facets (HAVING), OR-within-facet (IN). Drill-down counts reuse
 * the same subquery as an INNER JOIN — one table sweep per facet count.
 *
 * @package HookedOnFacets
 */

declare(strict_types=1);

namespace HookedOnFacets\Filter;

use HookedOnFacets\Activator;
use HookedOnFacets\Indexer;
use HookedOnFacets\Telemetry\Recorder;

defined( 'ABSPATH' ) || exit;

final class Resolver {

    /** Per-request cache of normalized facet definitions, keyed by name. */
    private ?array $defs_by_name_cache = null;

    public function __construct( private readonly ?Recorder $recorder = null ) {}

    // ── Public API ──────────────────────────────────────────────────────────

    /**
     * Resolve a filter state to matching IDs + drilled-down facet counts.
     *
     * @param array<string, mixed> $filter_state
     * @return array{ids: ?int[], counts: array<string, array<string, mixed>>}
     */
    public function resolve( array $filter_state ): array {
        $ids    = $this->resolve_ids( $filter_state );
        $counts = $this->resolve_counts( $filter_state );

        return [
            'ids'    => $ids,
            'counts' => $counts,
        ];
    }

    /**
     * IDs matching the filter state, or null when no filters apply.
     *
     * Return values:
     *   null   → no filters (caller should not restrict the query)
     *   []     → filters apply but match nothing (caller should return empty)
     *   [int…] → matching object IDs
     *
     * @param array<string, mixed> $filter_state
     * @return int[]|null
     */
    public function resolve_ids( array $filter_state ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . Activator::TABLE;

        $parts = $this->build_filter_subquery( $filter_state, $table );
        if ( $parts === null ) {
            return null;
        }

        $started = microtime( true );
        $rows    = $wpdb->get_col( $wpdb->prepare( $parts['sql'], $parts['params'] ) );
        $this->recorder?->record_resolver_ms( ( microtime( true ) - $started ) * 1000 );

        return array_map( 'intval', $rows );
    }

    /**
     * Sanitize raw query-string / REST input into a normalized filter state.
     *
     * @param mixed $raw
     * @return array<string, mixed>
     */
    public static function sanitize_filter_state( mixed $raw ): array {
        if ( ! is_array( $raw ) ) {
            return [];
        }

        $clean = [];
        foreach ( $raw as $facet_name => $value ) {
            if ( ! is_string( $facet_name ) ) {
                continue;
            }
            $key = sanitize_key( $facet_name );
            if ( $key === '' ) {
                continue;
            }

            if ( is_array( $value ) ) {
                // Range: { min, max }
                if ( array_key_exists( 'min', $value ) || array_key_exists( 'max', $value ) ) {
                    $range = [];
                    if ( isset( $value['min'] ) && is_numeric( $value['min'] ) ) {
                        $range['min'] = (float) $value['min'];
                    }
                    if ( isset( $value['max'] ) && is_numeric( $value['max'] ) ) {
                        $range['max'] = (float) $value['max'];
                    }
                    if ( ! empty( $range ) ) {
                        $clean[ $key ] = $range;
                    }
                    continue;
                }

                // Multi-value: ['acme', 'brio']
                $values = [];
                foreach ( $value as $v ) {
                    if ( ! is_scalar( $v ) ) {
                        continue;
                    }
                    $s = sanitize_text_field( (string) $v );
                    if ( $s !== '' ) {
                        $values[] = $s;
                    }
                }
                if ( ! empty( $values ) ) {
                    $clean[ $key ] = array_values( array_unique( $values ) );
                }
                continue;
            }

            // Scalar (single value or search string).
            if ( is_scalar( $value ) && (string) $value !== '' ) {
                $clean[ $key ] = sanitize_text_field( (string) $value );
            }
        }

        return $clean;
    }

    /**
     * Read filter state from the current request's `?hof[*]` params.
     *
     * @return array<string, mixed>
     */
    public static function parse_request_filters(): array {
        if ( ! isset( $_GET['hof'] ) || ! is_array( $_GET['hof'] ) ) {
            return [];
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, sanitized below.
        return self::sanitize_filter_state( wp_unslash( $_GET['hof'] ) );
    }

    // ── Internals ───────────────────────────────────────────────────────────

    /**
     * Count objects that have ALL of the given values for one facet.
     *
     * One INTERSECT chain of per-value covering scans — same shape as the
     * main resolver, but pinned to a single facet. Single value just runs
     * one SELECT wrapped in COUNT. Used by the Venn-matrix renderer for
     * pairwise / triple region counts (4 queries for a 3-set Venn).
     *
     * @param array<int, string> $values
     */
    public function count_intersection( string $facet_name, array $values ): int {
        if ( empty( $values ) ) {
            return 0;
        }
        global $wpdb;
        $table = $wpdb->prefix . Activator::TABLE;
        $legs   = [];
        $params = [];
        foreach ( $values as $value ) {
            $legs[]   = "SELECT object_id FROM {$table} USE INDEX (facet_lookup) WHERE facet_name = %s AND facet_value = %s";
            $params[] = $facet_name;
            $params[] = (string) $value;
        }
        $sql = 'SELECT COUNT(*) FROM ( ' . implode( ' INTERSECT ', $legs ) . ' ) AS sub';
        return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
    }

    /**
     * Build the core filter subquery: IDs matching all configured facets in
     * the state. Returns null when state is empty / unrecognized.
     *
     * Shape: INTERSECT (MySQL 8.0.31+) of per-facet SELECTs. Each leg's
     * USE INDEX hint pins facet_lookup for IN-list legs and
     * facet_numeric_range for BETWEEN legs — both are covering indexes
     * (object_id is the trailing column), so leg scans are index-only.
     *
     * Why not UNION ALL + GROUP BY/HAVING: that shape forced MySQL to
     * materialize the full union (~150k rows for non-selective filters),
     * sort by object_id, then count-distinct aggregate. ~50ms of fixed
     * overhead on top of the leg scans. INTERSECT streams set semantics
     * natively and skips the materialize/sort/aggregate.
     *
     * @param array<string, mixed> $filter_state
     * @return array{sql: string, params: list<mixed>}|null
     */
    private function build_filter_subquery( array $filter_state, string $table ): ?array {
        if ( empty( $filter_state ) ) {
            return null;
        }

        $defs   = $this->configured_facets_by_name();
        $legs   = [];
        $params = [];

        foreach ( $filter_state as $facet_name => $value ) {
            if ( ! isset( $defs[ $facet_name ] ) ) {
                continue;
            }

            $clause = $this->build_facet_clause( $facet_name, $value, $defs[ $facet_name ] );
            if ( $clause === null ) {
                continue;
            }

            $index   = $clause['index'] ?? 'facet_lookup';
            // DISTINCT only when the leg can emit the same object_id more
            // than once — multi-value taxonomy IN-lists. INTERSECT dedupes
            // across legs, but a single-facet filter is a degenerate
            // INTERSECT (one leg, no operator), so the leg has to dedupe
            // itself in that case. Single-value / range / meta legs don't
            // pay the cost so the covering-index streaming win stays intact.
            $distinct = ! empty( $clause['needs_distinct'] ) ? 'DISTINCT ' : '';
            $legs[]   = "SELECT {$distinct}object_id FROM {$table} USE INDEX ({$index}) WHERE {$clause['sql']}";
            $params   = array_merge( $params, $clause['params'] );
        }

        if ( empty( $legs ) ) {
            return null;
        }

        return [
            'sql'    => implode( ' INTERSECT ', $legs ),
            'params' => $params,
        ];
    }

    /**
     * Build one OR-clause for the filter subquery.
     *
     * Returns the index name MySQL should use for this leg. Without a hint,
     * the optimizer often picks facet_lookup for range queries because both
     * indexes start with facet_name — it can't tell facet_numeric_range is
     * far better for `facet_numeric BETWEEN ...` predicates.
     *
     * @param array<string, mixed> $facet_def
     * @return array{sql: string, params: list<mixed>, index: string}|null
     */
    private function build_facet_clause( string $facet_name, mixed $value, array $facet_def ): ?array {
        $display = $facet_def['display'] ?? 'checkbox';

        // Range: { min?, max? }
        if ( is_array( $value ) && ( isset( $value['min'] ) || isset( $value['max'] ) ) ) {
            $sql    = 'facet_name = %s';
            $params = [ $facet_name ];
            if ( isset( $value['min'] ) && is_numeric( $value['min'] ) ) {
                $sql     .= ' AND facet_numeric >= %f';
                $params[] = (float) $value['min'];
            }
            if ( isset( $value['max'] ) && is_numeric( $value['max'] ) ) {
                $sql     .= ' AND facet_numeric <= %f';
                $params[] = (float) $value['max'];
            }
            return [ 'sql' => $sql, 'params' => $params, 'index' => 'facet_numeric_range' ];
        }

        // Search: scalar string + facet typed as search.
        if ( $display === 'search' && is_scalar( $value ) && (string) $value !== '' ) {
            global $wpdb;
            return [
                'sql'    => 'facet_name = %s AND facet_display LIKE %s',
                'params' => [ $facet_name, '%' . $wpdb->esc_like( (string) $value ) . '%' ],
                'index'  => 'facet_lookup',
            ];
        }

        // Multi-value (checkbox / radio / swatch).
        $values = is_array( $value ) ? $value : [ $value ];
        $values = array_values( array_filter(
            array_map( static fn( $v ) => is_scalar( $v ) ? (string) $v : null, $values ),
            static fn( $v ) => $v !== null && $v !== ''
        ) );
        if ( empty( $values ) ) {
            return null;
        }

        $placeholders = implode( ', ', array_fill( 0, count( $values ), '%s' ) );

        // Per-leg dedup is only required when a single object can have more
        // than one matching row in this leg. That happens when the source is
        // a taxonomy (an object can carry multiple terms) AND the IN-list
        // has >1 value, so the same object_id could match multiple legs of
        // the OR. Meta and post-field facets are single-row-per-object by
        // construction; range legs are single-value. Skipping DISTINCT on
        // those preserves the covering-index streaming win.
        $kind          = $facet_def['kind'] ?? 'taxonomy';
        $needs_distinct = ( $kind === 'taxonomy' && count( $values ) > 1 );

        return [
            'sql'           => "facet_name = %s AND facet_value IN ({$placeholders})",
            'params'        => array_merge( [ $facet_name ], $values ),
            'index'         => 'facet_lookup',
            'needs_distinct' => $needs_distinct,
        ];
    }

    /**
     * Compute drill-down counts: for each configured facet, count what's
     * available given the filter state *with that facet's own filter removed*.
     *
     * @param array<string, mixed> $filter_state
     * @return array<string, array<string, mixed>>
     */
    private function resolve_counts( array $filter_state ): array {
        $defs   = $this->configured_facets_by_name();
        $counts = [];

        foreach ( $defs as $name => $facet ) {
            $partial_state = $filter_state;
            unset( $partial_state[ $name ] );

            $counts[ $name ] = $this->count_facet( $facet, $partial_state );
        }

        return $counts;
    }

    /**
     * Count one facet under a partial filter state.
     *
     * Composes build_filter_subquery as an INNER JOIN — single sweep, single
     * use of the composite index, no IN-list of resolved IDs.
     *
     * @param array<string, mixed> $facet_def
     * @param array<string, mixed> $partial_state
     * @return array<string, mixed>
     */
    private function count_facet( array $facet_def, array $partial_state ): array {
        global $wpdb;
        $table   = $wpdb->prefix . Activator::TABLE;
        $name    = $facet_def['name'];
        $display = $facet_def['display'] ?? 'checkbox';

        // Search facets don't have countable buckets.
        if ( $display === 'search' ) {
            return [ 'type' => 'search' ];
        }

        $subquery = $this->build_filter_subquery( $partial_state, $table );

        if ( $display === 'range' ) {
            if ( $subquery === null ) {
                $sql = $wpdb->prepare(
                    "SELECT MIN(facet_numeric) AS lo, MAX(facet_numeric) AS hi
                     FROM {$table}
                     WHERE facet_name = %s AND facet_numeric IS NOT NULL",
                    $name
                );
            } else {
                $sql = $wpdb->prepare(
                    "SELECT MIN(idx.facet_numeric) AS lo, MAX(idx.facet_numeric) AS hi
                     FROM {$table} idx
                     INNER JOIN ({$subquery['sql']}) AS filtered ON filtered.object_id = idx.object_id
                     WHERE idx.facet_name = %s AND idx.facet_numeric IS NOT NULL",
                    array_merge( $subquery['params'], [ $name ] )
                );
            }
            $row = $wpdb->get_row( $sql, ARRAY_A );
            return [
                'type' => 'range',
                'min'  => $row && $row['lo'] !== null ? (float) $row['lo'] : null,
                'max'  => $row && $row['hi'] !== null ? (float) $row['hi'] : null,
            ];
        }

        // Default: bucket counts (checkbox / radio / swatch / etc.).
        if ( $subquery === null ) {
            $sql = $wpdb->prepare(
                "SELECT facet_value AS value, facet_display AS display, COUNT(DISTINCT object_id) AS cnt
                 FROM {$table}
                 WHERE facet_name = %s
                 GROUP BY facet_value, facet_display
                 ORDER BY cnt DESC, display ASC",
                $name
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT idx.facet_value AS value, idx.facet_display AS display, COUNT(DISTINCT idx.object_id) AS cnt
                 FROM {$table} idx
                 INNER JOIN ({$subquery['sql']}) AS filtered ON filtered.object_id = idx.object_id
                 WHERE idx.facet_name = %s
                 GROUP BY idx.facet_value, idx.facet_display
                 ORDER BY cnt DESC, display ASC",
                array_merge( $subquery['params'], [ $name ] )
            );
        }

        $rows = $wpdb->get_results( $sql, ARRAY_A ) ?: [];
        return [
            'type'    => 'values',
            'buckets' => array_map(
                static fn( $r ) => [
                    'value'   => (string) $r['value'],
                    'display' => (string) $r['display'],
                    'count'   => (int) $r['cnt'],
                ],
                $rows
            ),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function configured_facets_by_name(): array {
        if ( $this->defs_by_name_cache !== null ) {
            return $this->defs_by_name_cache;
        }

        $raw     = (array) get_option( Indexer::OPTION_FACETS, [] );
        $by_name = [];
        foreach ( $raw as $def ) {
            if ( is_array( $def ) && isset( $def['name'] ) ) {
                $by_name[ (string) $def['name'] ] = $def;
            }
        }

        return $this->defs_by_name_cache = $by_name;
    }
}
