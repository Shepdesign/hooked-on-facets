<?php
/**
 * @package HookedOnFacets\Tests
 */

declare(strict_types=1);

namespace HookedOnFacets\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HookedOnFacets\Telemetry\Recorder;
use PHPUnit\Framework\TestCase;

/**
 * Covers the filter-usage analytics the Recorder buffers and surfaces:
 * per-facet/value usage, zero-result combos, and resolver percentiles.
 * get_option/update_option are backed by an in-memory store so flush() →
 * snapshot() round-trips without a database.
 */
final class RecorderTest extends TestCase {

    /** @var array<string, mixed> */
    private array $store;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->store = [];

        Functions\when( 'get_option' )->alias( fn( $name, $default = false ) => $this->store[ $name ] ?? $default );
        Functions\when( 'update_option' )->alias( function ( $name, $value ) {
            $this->store[ $name ] = $value;
            return true;
        } );
        Functions\when( 'delete_option' )->alias( function ( $name ) {
            unset( $this->store[ $name ] );
            return true;
        } );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_facet_and_value_usage_accumulates(): void {
        $rec = new Recorder();
        $rec->record_filter_usage( [ 'brand' => [ 'acme', 'brio' ] ], 5 );
        $rec->record_filter_usage( [ 'brand' => [ 'acme' ], 'color' => [ 'red' ] ], 3 );
        $rec->flush();

        $facets = ( new Recorder() )->snapshot()['facets'];

        $usage = [];
        foreach ( $facets['usage'] as $row ) {
            $usage[ $row['facet'] ] = $row['count'];
        }
        self::assertSame( 2, $usage['brand'], 'brand applied in both events' );
        self::assertSame( 1, $usage['color'] );

        // brand=acme counted twice, brio + red once each.
        $brandValues = [];
        foreach ( $facets['top_values']['brand'] as $row ) {
            $brandValues[ $row['value'] ] = $row['count'];
        }
        self::assertSame( 2, $brandValues['acme'] );
        self::assertSame( 1, $brandValues['brio'] );
        // total = sum of facet usage: brand(2) + color(1) = 3.
        self::assertSame( 3, $facets['total'], 'total facet applications across events' );
    }

    public function test_reserved_keys_and_empty_states_are_ignored(): void {
        $rec = new Recorder();
        $rec->record_filter_usage( [ '_visual_ids' => [ 1, 2 ], '_bin_ids' => [ 3 ] ], 10 );
        $rec->record_filter_usage( [], 0 );
        $rec->flush();

        // Nothing real was recorded → flush wrote no facet usage.
        self::assertSame( [], ( new Recorder() )->snapshot()['facets']['usage'] );
    }

    public function test_zero_result_combos_are_captured_and_ranked(): void {
        $rec = new Recorder();
        // Same combo twice (order-independent), plus a different one once.
        $rec->record_filter_usage( [ 'brand' => [ 'acme' ], 'color' => [ 'red' ] ], 0 );
        $rec->record_filter_usage( [ 'color' => [ 'red' ], 'brand' => [ 'acme' ] ], 0 );
        $rec->record_filter_usage( [ 'size' => [ 'xl' ] ], 0 );
        // A non-zero result must NOT land in zero_results.
        $rec->record_filter_usage( [ 'brand' => [ 'brio' ] ], 7 );
        $rec->flush();

        $zero = ( new Recorder() )->snapshot()['facets']['zero_results'];

        self::assertCount( 2, $zero, 'two distinct zero-result signatures' );
        self::assertSame( 'brand=acme&color=red', $zero[0]['signature'], 'order-independent signature, ranked by count' );
        self::assertSame( 2, $zero[0]['count'] );
    }

    public function test_range_values_count_facet_usage_but_no_discrete_value(): void {
        $rec = new Recorder();
        $rec->record_filter_usage( [ 'price' => [ 'min' => '10', 'max' => '50' ] ], 4 );
        $rec->flush();

        $facets = ( new Recorder() )->snapshot()['facets'];

        self::assertSame( 'price', $facets['usage'][0]['facet'] );
        self::assertSame( 1, $facets['usage'][0]['count'] );
        // A range has no discrete value bucket.
        self::assertArrayNotHasKey( 'price', $facets['top_values'] );
    }

    public function test_resolver_percentiles_present(): void {
        $rec = new Recorder();
        foreach ( range( 1, 100 ) as $ms ) {
            $rec->record_resolver_ms( (float) $ms );
        }
        $rec->flush();

        $resolver = ( new Recorder() )->snapshot()['resolver'];

        self::assertNotNull( $resolver['p50_ms'] );
        self::assertNotNull( $resolver['p95_ms'] );
        self::assertNotNull( $resolver['p99_ms'] );
        // Monotonic: p50 <= p95 <= p99.
        self::assertLessThanOrEqual( $resolver['p95_ms'], $resolver['p50_ms'] );
        self::assertLessThanOrEqual( $resolver['p99_ms'], $resolver['p95_ms'] );
    }

    public function test_reset_clears_everything(): void {
        $rec = new Recorder();
        $rec->record_filter_usage( [ 'brand' => [ 'acme' ] ], 0 );
        $rec->flush();
        $rec->reset();

        $facets = ( new Recorder() )->snapshot()['facets'];
        self::assertSame( [], $facets['usage'] );
        self::assertSame( [], $facets['zero_results'] );
    }
}
