<?php
/**
 * Benchmark — measures HOF perf against the plan.md gates.
 *
 * What it tests:
 *   1. Setup snapshot (product count, index row count, configured facets)
 *   2. Optional: full reindex time (≤60s gate)
 *   3. Resolver::resolve_ids() p50/p95/p99 on a 5-facet intersect (≤50ms p95 gate)
 *   4. Resolver::resolve() — same plus drill-down counts (real /filter cost)
 *   5. Query count per call (no-N+1 gate: should be 1 + N facets, not more)
 *   6. EXPLAIN ANALYZE on the resolver hot path
 *
 * Assumes warm MySQL buffer pool — a `--warmup N` phase populates it before
 * measurement. To benchmark cold cache, restart MySQL between runs.
 *
 * Invoke via:
 *   wp eval "require '/path/to/benchmark.php'; hof_benchmark_run([...]);"
 *
 * @package HookedOnFacets
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'hof_benchmark_run' ) ) :

/**
 * @param array{iterations?: int, warmup?: int, reindex?: bool, explain?: bool} $opts
 */
function hof_benchmark_run( array $opts = [] ): void {
    $iterations = max( 10, (int) ( $opts['iterations'] ?? 200 ) );
    $warmup     = max( 0,  (int) ( $opts['warmup'] ?? 10 ) );
    $do_reindex = (bool) ( $opts['reindex'] ?? false );
    $do_explain = (bool) ( $opts['explain'] ?? true );

    echo "HOF Benchmark\n";
    echo str_repeat( '=', 60 ) . "\n\n";

    if ( ! class_exists( \HookedOnFacets\Plugin::class ) ) {
        fwrite( STDERR, "Error: HOF plugin not loaded.\n" );
        return;
    }

    hof_benchmark_setup();

    if ( $do_reindex ) {
        hof_benchmark_reindex();
    }

    $filter = hof_benchmark_build_filter();
    if ( empty( $filter ) ) {
        echo "\n⚠ No facets configured or index is empty. Configure facets and run reindex first:\n";
        echo "    wp option update hof_facets '<json>' --format=json\n";
        echo "    wp eval '\\HookedOnFacets\\Plugin::instance()->make(\\HookedOnFacets\\Indexer::class)->reindex_all();'\n\n";
        return;
    }

    echo "\nFilter under test:\n";
    echo '  ' . wp_json_encode( $filter ) . "\n";

    $resolver = \HookedOnFacets\Plugin::instance()->make( \HookedOnFacets\Filter\Resolver::class );

    // Warmup — populates def cache + warms MySQL buffer pool.
    for ( $i = 0; $i < $warmup; $i++ ) {
        $resolver->resolve( $filter );
    }

    hof_benchmark_time_resolve_ids( $resolver, $filter, $iterations );
    hof_benchmark_time_resolve_full( $resolver, $filter, $iterations );
    hof_benchmark_query_count( $resolver, $filter );

    if ( $do_explain ) {
        hof_benchmark_explain( $resolver, $filter );
    }

    echo "\n" . str_repeat( '=', 60 ) . "\n";
}

// ── Sections ────────────────────────────────────────────────────────────────

function hof_benchmark_setup(): void {
    global $wpdb;
    $index_table = $wpdb->prefix . \HookedOnFacets\Activator::TABLE;

    $products = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish'"
    );
    $index_rows = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$index_table}" );
    $facets     = (array) get_option( \HookedOnFacets\Indexer::OPTION_FACETS, [] );

    echo "Setup:\n";
    printf( "  Products (publish):  %s\n", number_format( $products ) );
    printf( "  Index rows:          %s\n", number_format( $index_rows ) );
    printf( "  Configured facets:   %d  (%s)\n",
        count( $facets ),
        implode( ', ', array_map( static fn( $f ) => $f['name'] ?? '?', $facets ) )
    );
}

function hof_benchmark_reindex(): void {
    $indexer = \HookedOnFacets\Plugin::instance()->make( \HookedOnFacets\Indexer::class );

    echo "\nReindex (full):\n";
    $start = microtime( true );
    $count = $indexer->reindex_all();
    $elapsed = microtime( true ) - $start;

    printf( "  Total:    %.2fs\n", $elapsed );
    printf( "  Rate:     %s objects/sec\n", number_format( $count / max( $elapsed, 0.001 ) ) );
    printf( "  GATE:     ≤60s — %s\n", $elapsed <= 60.0 ? 'PASS' : 'FAIL' );
}

/**
 * Build a realistic filter from whatever's in the configured facets + index.
 *
 * @return array<string, mixed>
 */
function hof_benchmark_build_filter(): array {
    global $wpdb;
    $table = $wpdb->prefix . \HookedOnFacets\Activator::TABLE;

    $facets = (array) get_option( \HookedOnFacets\Indexer::OPTION_FACETS, [] );
    $filter = [];

    foreach ( $facets as $facet ) {
        $name    = $facet['name'] ?? null;
        $display = $facet['display'] ?? 'checkbox';
        if ( ! $name ) continue;

        if ( $display === 'range' ) {
            $bounds = $wpdb->get_row( $wpdb->prepare(
                "SELECT MIN(facet_numeric) AS lo, MAX(facet_numeric) AS hi FROM {$table} WHERE facet_name = %s",
                $name
            ), ARRAY_A );
            if ( $bounds && $bounds['lo'] !== null && $bounds['hi'] !== null ) {
                $lo = (float) $bounds['lo'];
                $hi = (float) $bounds['hi'];
                // Middle 80% — narrow enough to actually filter, wide enough to match.
                $filter[ $name ] = [
                    'min' => $lo + ( $hi - $lo ) * 0.1,
                    'max' => $lo + ( $hi - $lo ) * 0.9,
                ];
            }
            continue;
        }

        if ( $display === 'checkbox' ) {
            // Top 2 most-common values — realistic multi-select.
            $vals = $wpdb->get_col( $wpdb->prepare(
                "SELECT facet_value FROM {$table}
                 WHERE facet_name = %s
                 GROUP BY facet_value
                 ORDER BY COUNT(*) DESC
                 LIMIT 2",
                $name
            ) );
            if ( ! empty( $vals ) ) {
                $filter[ $name ] = $vals;
            }
            continue;
        }

        // Skip search facets — LIKE-pattern timing isn't comparable.
    }

    return $filter;
}

/**
 * @param array<string, mixed> $filter
 */
function hof_benchmark_time_resolve_ids( $resolver, array $filter, int $iterations ): void {
    $times = [];
    for ( $i = 0; $i < $iterations; $i++ ) {
        $start = microtime( true );
        $resolver->resolve_ids( $filter );
        $times[] = ( microtime( true ) - $start ) * 1000;
    }

    hof_benchmark_print_stats(
        sprintf( 'Resolver::resolve_ids() — IDs only, %d-facet intersect', count( $filter ) ),
        $times,
        50.0
    );
}

/**
 * @param array<string, mixed> $filter
 */
function hof_benchmark_time_resolve_full( $resolver, array $filter, int $iterations ): void {
    $times = [];
    for ( $i = 0; $i < $iterations; $i++ ) {
        $start = microtime( true );
        $resolver->resolve( $filter );
        $times[] = ( microtime( true ) - $start ) * 1000;
    }

    hof_benchmark_print_stats(
        'Resolver::resolve() — IDs + drill-down counts (the /filter cost)',
        $times,
        null
    );
}

/**
 * @param array<string, mixed> $filter
 */
function hof_benchmark_query_count( $resolver, array $filter ): void {
    global $wpdb;

    // Per-call query counts. resolve() runs after resolve_ids() so the
    // resolver's defs cache is warm — we're measuring SQL queries only.
    $before = $wpdb->num_queries;
    $resolver->resolve_ids( $filter );
    $ids_q = $wpdb->num_queries - $before;

    $before = $wpdb->num_queries;
    $resolver->resolve( $filter );
    $full_q = $wpdb->num_queries - $before;

    $facets_count = count( (array) get_option( \HookedOnFacets\Indexer::OPTION_FACETS, [] ) );
    $expected     = 1 + $facets_count;

    echo "\nQuery counts per call:\n";
    printf( "  resolve_ids():        %d query%s\n",  $ids_q,  $ids_q  === 1 ? '' : 's' );
    printf( "  resolve() (full):     %d queries  (expected 1 ids + %d counts = %d)\n",
        $full_q, $facets_count, $expected );

    $verdict = $full_q === $expected ? 'PASS' : sprintf( 'FAIL — got %d, expected %d', $full_q, $expected );
    printf( "  GATE: no N+1          — %s\n", $verdict );
}

/**
 * Capture the actual SQL the Resolver emits, then EXPLAIN ANALYZE it.
 *
 * Needs SAVEQUERIES on. We define it on the fly — works for queries that
 * run after this point, which is exactly what we measure.
 *
 * @param array<string, mixed> $filter
 */
function hof_benchmark_explain( $resolver, array $filter ): void {
    global $wpdb;

    if ( ! defined( 'SAVEQUERIES' ) ) {
        define( 'SAVEQUERIES', true );
    }
    if ( ! SAVEQUERIES ) {
        echo "\nEXPLAIN: skipped — define('SAVEQUERIES', true) in wp-config.php to enable.\n";
        return;
    }

    // $wpdb->queries is null until SAVEQUERIES was true at the first executed
    // query. We may have just enabled it; force an array so count/slice work.
    if ( ! is_array( $wpdb->queries ) ) {
        $wpdb->queries = [];
    }

    $before = count( $wpdb->queries );
    $resolver->resolve_ids( $filter );
    $emitted = array_slice( $wpdb->queries, $before );
    if ( empty( $emitted ) ) {
        echo "\nEXPLAIN: no query captured (resolver returned early).\n";
        return;
    }

    // [0] is the SQL string, [1] is the time, [2] is the call stack.
    $sql = $emitted[0][0];

    echo "\nEXPLAIN ANALYZE — resolver hot path:\n";
    $rows = $wpdb->get_results( 'EXPLAIN ANALYZE ' . $sql, ARRAY_N );
    if ( empty( $rows ) ) {
        echo "  (no output — MySQL < 8.0.18?)\n";
        return;
    }
    foreach ( $rows as $row ) {
        // EXPLAIN ANALYZE returns a single text column with embedded newlines.
        foreach ( explode( "\n", (string) ( $row[0] ?? '' ) ) as $line ) {
            echo '  ' . $line . "\n";
        }
    }
}

/**
 * @param float[] $times Milliseconds.
 */
function hof_benchmark_print_stats( string $label, array $times, ?float $gate_p95 ): void {
    sort( $times );
    $n    = count( $times );
    $p50  = $times[ (int) ( $n * 0.50 ) ];
    $p95  = $times[ (int) ( $n * 0.95 ) ];
    $p99  = $times[ (int) min( $n - 1, $n * 0.99 ) ];
    $mean = array_sum( $times ) / $n;

    echo "\n{$label} ({$n} iterations):\n";
    printf(
        "  p50: %6.2fms   p95: %6.2fms   p99: %6.2fms   mean: %6.2fms   min: %6.2fms   max: %6.2fms\n",
        $p50, $p95, $p99, $mean, $times[0], $times[ $n - 1 ]
    );

    if ( $gate_p95 !== null ) {
        $verdict = $p95 <= $gate_p95 ? 'PASS' : 'FAIL';
        printf( "  GATE: p95 ≤ %.0fms     — %s\n", $gate_p95, $verdict );
    }
}

endif;
