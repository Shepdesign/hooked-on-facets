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
 * AND-across-facets (HAVING), OR-within-facet (IN). A facet can opt into
 * AND-within-facet (settings.match = 'all') — it then contributes one
 * single-value INTERSECT leg per selected value instead of one IN leg, so the
 * object must carry every value. Drill-down counts reuse the same subquery as
 * an INNER JOIN — one table sweep per facet count.
 *
 * @package HookedOnFacets
 */

declare(strict_types=1);

namespace HookedOnFacets\Filter;

use HookedOnFacets\Activator;
use HookedOnFacets\Indexer;
use HookedOnFacets\Telemetry\Recorder;

defined( 'ABSPATH' ) || exit;

final class Resolver implements IdResolver {

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
        // Cache the full IDs+counts result. This is the expensive path — the
        // drill-down counts run 1 + N grouped queries — so it's where caching
        // pays off most for the AJAX /filter endpoint. Same version-keyed,
        // reserved-key-skipping policy as resolve_ids().
        $cacheable = $this->is_cacheable( $filter_state );
        if ( $cacheable ) {
            $key    = $this->cache_key( 'resolve', $filter_state );
            $cached = $this->cache_get( $key );
            if ( is_array( $cached ) && isset( $cached['ids'] ) && isset( $cached['counts'] ) ) {
                return $cached;
            }
        }

        // resolve_ids handles _visual_ids internally — but counts shouldn't
        // see it (drill-down counts treat each non-self filter as a facet
        // restriction, and _visual_ids would just be silently skipped, but
        // stripping it is clearer).
        $counts_state = $filter_state;
        unset( $counts_state['_visual_ids'], $counts_state['_bin_ids'] );

        $ids    = $this->resolve_ids( $filter_state );
        $counts = $this->resolve_counts( $counts_state );

        $result = [
            'ids'    => $ids,
            'counts' => $counts,
        ];

        if ( $cacheable ) {
            $this->cache_set( $key, $result );
        }
        return $result;
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
        // Cache the resolved ID set for plain-facet filters. Skipped when a
        // per-user reserved key (_visual_ids / _bin_ids) is present — those
        // have near-zero reuse and would bloat the cache. Stored in an
        // envelope so a genuine null (no recognized filters) is distinct from
        // a cache miss.
        $cacheable = $this->is_cacheable( $filter_state );
        if ( $cacheable ) {
            $key    = $this->cache_key( 'ids', $filter_state );
            $cached = $this->cache_get( $key );
            if ( is_array( $cached ) && array_key_exists( 'v', $cached ) ) {
                return $cached['v'];
            }
        }

        $ids = $this->compute_ids( $filter_state );

        if ( $cacheable ) {
            $this->cache_set( $key, [ 'v' => $ids ] );
        }
        return $ids;
    }

    /**
     * Uncached ID resolution — the original resolve_ids body.
     *
     * @param array<string, mixed> $filter_state
     * @return int[]|null
     */
    private function compute_ids( array $filter_state ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . Activator::TABLE;

        // Reserved keys aren't configured facets — pull them out before
        // building the facet subquery. Visual DNA v2 applies an intersection
        // + ordering at the end; the saved bin applies a plain intersection.
        $visual_ids = $this->pull_id_list( $filter_state, '_visual_ids' );
        $bin_ids    = $this->pull_id_list( $filter_state, '_bin_ids' );

        $parts = $this->build_filter_subquery( $filter_state, $table );

        $ids = null;
        if ( $parts !== null ) {
            $started = microtime( true );
            $rows    = $wpdb->get_col( $wpdb->prepare( $parts['sql'], $parts['params'] ) );
            $this->recorder?->record_resolver_ms( ( microtime( true ) - $started ) * 1000 );
            $ids = array_map( 'intval', $rows );
        }

        // Saved-bin restriction — intersect the bin's IDs with the facet
        // result (or stand alone when no facets are active).
        if ( ! empty( $bin_ids ) ) {
            if ( $ids === null ) {
                $ids = array_values( $bin_ids );
            } else {
                $match = array_fill_keys( $ids, true );
                $ids   = array_values( array_filter( $bin_ids, static fn( $id ) => isset( $match[ $id ] ) ) );
            }
        }

        if ( ! empty( $visual_ids ) ) {
            if ( $ids === null ) {
                return $visual_ids;
            }
            $match_set = array_fill_keys( $ids, true );
            $ordered   = [];
            foreach ( $visual_ids as $id ) {
                if ( isset( $match_set[ $id ] ) ) $ordered[] = $id;
            }
            return $ordered;
        }

        return $ids;
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

            // Reserved keys — internal ID restrictions, not real facet names
            // (the leading underscore marks them). `_visual_ids` is Visual DNA
            // v2's ordered restriction; `_bin_ids` is the saved comparison bin.
            if ( $key === '_visual_ids' || $key === '_bin_ids' ) {
                $ids = [];
                if ( is_array( $value ) ) {
                    foreach ( $value as $v ) {
                        $id = (int) $v;
                        if ( $id > 0 ) $ids[] = $id;
                    }
                } elseif ( is_string( $value ) && $value !== '' ) {
                    // CSV form for URL-driven state — `?hof[_bin_ids]=12,34,56`
                    foreach ( explode( ',', $value ) as $v ) {
                        $id = (int) $v;
                        if ( $id > 0 ) $ids[] = $id;
                    }
                }
                if ( ! empty( $ids ) ) {
                    $clean[ $key ] = $ids;
                }
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
     * Extract and remove a reserved positive-integer ID list from the state.
     *
     * @param array<string, mixed> $filter_state
     * @return int[]
     */
    private function pull_id_list( array &$filter_state, string $key ): array {
        if ( ! isset( $filter_state[ $key ] ) || ! is_array( $filter_state[ $key ] ) ) {
            return [];
        }
        $ids = $filter_state[ $key ];
        unset( $filter_state[ $key ] );
        return array_values( array_filter( array_map( 'intval', $ids ), static fn( $id ) => $id > 0 ) );
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

            foreach ( $this->build_facet_legs( $facet_name, $value, $defs[ $facet_name ] ) as $clause ) {
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
     * Build the INTERSECT leg(s) one facet contributes to the subquery.
     *
     * Most facets contribute a single leg (range, search, or an OR-within
     * IN-list). A multi-value facet set to **match = all** (AND-within-facet)
     * contributes one single-value leg per selected value instead: INTERSECT
     * already gives AND across legs, so N legs means "object has all N values".
     * Each leg is a covering-index equality scan with no DISTINCT (a single
     * (facet_name, facet_value) pair matches an object at most once), so AND
     * mode is, if anything, cheaper than the OR IN-list.
     *
     * @param array<string, mixed> $facet_def
     * @return list<array{sql: string, params: list<mixed>, index: string, needs_distinct?: bool}>
     */
    private function build_facet_legs( string $facet_name, mixed $value, array $facet_def ): array {
        $display   = $facet_def['display'] ?? 'checkbox';
        $is_range  = is_array( $value ) && ( isset( $value['min'] ) || isset( $value['max'] ) );
        $settings  = is_array( $facet_def['settings'] ?? null ) ? $facet_def['settings'] : [];
        // The matrix display is an intersection visualization, so it defaults
        // to AND-within-facet; every other multi-value display defaults to OR.
        $default_match = $display === 'matrix' ? 'all' : 'any';
        $match_all = ! $is_range && $display !== 'search' && ( $settings['match'] ?? $default_match ) === 'all';

        if ( $match_all ) {
            $legs = [];
            foreach ( $this->normalize_multi_values( $value ) as $v ) {
                $legs[] = [
                    'sql'    => 'facet_name = %s AND facet_value = %s',
                    'params' => [ $facet_name, $v ],
                    'index'  => 'facet_lookup',
                ];
            }
            return $legs;
        }

        $clause = $this->build_facet_clause( $facet_name, $value, $facet_def );
        return $clause === null ? [] : [ $clause ];
    }

    /**
     * Flatten a multi-value filter value into a deduped list of non-empty
     * strings — shared by the OR IN-list and the AND per-value legs.
     *
     * @return list<string>
     */
    private function normalize_multi_values( mixed $value ): array {
        $values = is_array( $value ) ? $value : [ $value ];
        return array_values( array_unique( array_filter(
            array_map( static fn( $v ) => is_scalar( $v ) ? (string) $v : null, $values ),
            static fn( $v ) => $v !== null && $v !== ''
        ) ) );
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

        // Multi-value (checkbox / radio / swatch) — OR-within via IN-list.
        $values = $this->normalize_multi_values( $value );
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
        //
        // GROUP BY facet_value only — NOT (facet_value, facet_display). The
        // value→display mapping is 1:1 (slug→name, ID→title, or value===display
        // for meta), so MIN(facet_display) picks that single display, and
        // grouping by the lone covering-index column instead of two 191-char
        // VARCHARs shrinks the aggregation temp table dramatically: measured
        // 454ms → 60ms (7.5×) on 100k rows. COUNT(DISTINCT object_id) is kept
        // (not COUNT(*)) so a multi-row-per-object meta facet still counts each
        // object once.
        if ( $subquery === null ) {
            $sql = $wpdb->prepare(
                "SELECT facet_value AS value, MIN(facet_display) AS display, COUNT(DISTINCT object_id) AS cnt
                 FROM {$table}
                 WHERE facet_name = %s
                 GROUP BY facet_value
                 ORDER BY cnt DESC, display ASC",
                $name
            );
        } else {
            $sql = $wpdb->prepare(
                "SELECT idx.facet_value AS value, MIN(idx.facet_display) AS display, COUNT(DISTINCT idx.object_id) AS cnt
                 FROM {$table} idx
                 INNER JOIN ({$subquery['sql']}) AS filtered ON filtered.object_id = idx.object_id
                 WHERE idx.facet_name = %s
                 GROUP BY idx.facet_value
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

    // ── Result-set cache ─────────────────────────────────────────────────────
    //
    // Keyed by (index version, filter state), stored in the object cache. The
    // version (option `hof_index_version`, bumped by the Indexer on every
    // write) makes invalidation O(1): a bump orphans every old key, which the
    // backend evicts on its own. Most effective with a persistent object cache
    // (Redis / Memcached) — common on production WooCommerce — where identical
    // filters across requests and users hit cache. Without one it degrades to
    // a harmless per-request memo. A short TTL is a safety net in case a write
    // ever slips past the version bump.

    public const CACHE_GROUP   = 'hof_resolve';
    public const VERSION_OPTION = 'hof_index_version';

    /**
     * Cacheable only when there's something to cache and no per-user reserved
     * key is in play. Filterable kill switch: `hof_resolver_cache_enabled`.
     *
     * @param array<string, mixed> $filter_state
     */
    private function is_cacheable( array $filter_state ): bool {
        if ( ! function_exists( 'wp_cache_get' ) || ! function_exists( 'wp_cache_set' ) ) {
            return false;
        }
        if ( empty( $filter_state ) ) {
            return false;
        }
        if ( isset( $filter_state['_visual_ids'] ) || isset( $filter_state['_bin_ids'] ) ) {
            return false;
        }
        return (bool) apply_filters( 'hof_resolver_cache_enabled', true );
    }

    /**
     * @param array<string, mixed> $filter_state
     */
    private function cache_key( string $kind, array $filter_state ): string {
        ksort( $filter_state );
        $version = (int) get_option( self::VERSION_OPTION, 0 );
        return sprintf( '%s:v%d:%s', $kind, $version, md5( (string) wp_json_encode( $filter_state ) ) );
    }

    private function cache_get( string $key ): mixed {
        if ( ! function_exists( 'wp_cache_get' ) ) {
            return false;
        }
        return wp_cache_get( $key, self::CACHE_GROUP );
    }

    private function cache_set( string $key, mixed $value ): void {
        if ( ! function_exists( 'wp_cache_set' ) ) {
            return;
        }
        $ttl = (int) apply_filters( 'hof_resolver_cache_ttl', 300 );
        wp_cache_set( $key, $value, self::CACHE_GROUP, $ttl );
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
