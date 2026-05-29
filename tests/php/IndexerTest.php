<?php
/**
 * @package HookedOnFacets\Tests
 */

declare(strict_types=1);

namespace HookedOnFacets\Tests;

use HookedOnFacets\Indexer;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Covers normalize_meta_values() — the serialized-array adapter that both
 * meta gatherers funnel through — and resolve_targets(), the ID → label
 * lookup behind the 'post' / 'user' / 'term' resolve kinds. The former is
 * pure logic; the latter is exercised with a mocked $wpdb.
 */
final class IndexerTest extends TestCase {
    use MockeryPHPUnitIntegration;

    private function values( mixed $decoded, bool $is_date = false ): array {
        return ( new Indexer() )->normalize_meta_values( $decoded, $is_date );
    }

    public function test_scalar_string_yields_one_tuple(): void {
        $out = $this->values( 'red' );

        self::assertSame( [ [ 'value' => 'red', 'numeric' => null ] ], $out );
    }

    public function test_array_explodes_into_one_tuple_per_scalar_element(): void {
        $out = $this->values( [ 'red', 'green', 'blue' ] );

        self::assertSame(
            [
                [ 'value' => 'red',   'numeric' => null ],
                [ 'value' => 'green', 'numeric' => null ],
                [ 'value' => 'blue',  'numeric' => null ],
            ],
            $out,
            'A multi-value (ACF checkbox / multi-select) array must become one row per element.'
        );
    }

    public function test_empty_strings_and_non_scalar_elements_are_skipped(): void {
        $out = $this->values( [ 'keep', '', [ 'nested' ], (object) [ 'a' => 1 ], 'also' ] );

        self::assertSame(
            [
                [ 'value' => 'keep', 'numeric' => null ],
                [ 'value' => 'also', 'numeric' => null ],
            ],
            $out,
            'Empty strings and non-scalar (nested array / object) elements must be dropped.'
        );
    }

    public function test_numeric_strings_resolve_to_facet_numeric(): void {
        $out = $this->values( [ '10', '20.5', 'na' ] );

        self::assertSame( 10.0,  $out[0]['numeric'] );
        self::assertSame( 20.5,  $out[1]['numeric'] );
        self::assertNull( $out[2]['numeric'], 'Non-numeric values keep a null numeric.' );
    }

    // ── date normalization (ACF compact Ymd) ──────────────────────────────

    public function test_acf_compact_ymd_normalizes_to_epoch_when_date(): void {
        $epoch = ( new \DateTime( '2026-05-28T00:00:00+00:00' ) )->getTimestamp();

        $out = $this->values( '20260528', true );

        self::assertSame(
            (float) $epoch,
            $out[0]['numeric'],
            'A date_range facet must read ACF date_picker Ymd as a UTC-midnight epoch, not a raw integer.'
        );
    }

    public function test_compact_ymd_stays_a_raw_number_when_not_a_date(): void {
        $out = $this->values( '20260528', false );

        self::assertSame( 20260528.0, $out[0]['numeric'],
            'Outside a date facet, an 8-digit value is just a number.' );
    }

    public function test_invalid_compact_date_falls_through_to_raw_number(): void {
        // 2026-13-45 is not a real date; round-trip validation rejects it, so
        // it falls through to the numeric passthrough rather than rolling over.
        $out = $this->values( '20261345', true );

        self::assertSame( 20261345.0, $out[0]['numeric'] );
    }

    public function test_real_epoch_passes_through_unchanged_when_date(): void {
        // A genuine ~10-digit unix timestamp is past the 8-digit Ymd gate.
        $out = $this->values( '1700000000', true );

        self::assertSame( 1700000000.0, $out[0]['numeric'] );
    }

    public function test_empty_array_yields_nothing(): void {
        self::assertSame( [], $this->values( [] ) );
    }

    public function test_empty_scalar_yields_nothing(): void {
        self::assertSame( [], $this->values( '' ) );
    }

    public function test_long_values_are_truncated_to_191_chars(): void {
        $out = $this->values( str_repeat( 'x', 300 ) );

        self::assertSame( 191, strlen( $out[0]['value'] ) );
    }

    // ── extract_ids (ID-resolution adapter) ───────────────────────────────

    private function ids( mixed $decoded ): array {
        return ( new Indexer() )->extract_ids( $decoded );
    }

    public function test_extract_ids_from_scalar_and_array(): void {
        self::assertSame( [ 12 ], $this->ids( '12' ) );
        self::assertSame( [ 12, 34 ], $this->ids( [ '12', '34' ] ),
            'ACF relationship stores a serialized array of post-ID strings.' );
    }

    public function test_extract_ids_drops_non_positive_non_numeric_and_dedupes(): void {
        self::assertSame( [ 12, 34 ], $this->ids( [ '12', '', 'abc', '0', '-5', '34', '12' ] ) );
    }

    public function test_extract_ids_splits_comma_separated_strings(): void {
        // Meta Box taxonomy_advanced stores its term IDs as one CSV string.
        self::assertSame( [ 12, 34, 56 ], $this->ids( '12,34,56' ) );
        self::assertSame( [ 12, 34, 56 ], $this->ids( [ '12,34', '56' ] ),
            'A CSV string nested in an array is split element-wise too.' );
        self::assertSame( [ 12, 34 ], $this->ids( ' 12 , , 34 ' ),
            'Whitespace and empty segments are trimmed and dropped.' );
    }

    public function test_extract_ids_skips_non_scalar_elements(): void {
        self::assertSame( [ 12 ], $this->ids( [ [ 'nested' ], '12', (object) [ 'a' => 1 ] ] ) );
    }

    public function test_extract_ids_empty(): void {
        self::assertSame( [], $this->ids( [] ) );
        self::assertSame( [], $this->ids( '' ) );
    }

    // ── resolve_targets (ID → label lookup) ───────────────────────────────

    public function test_resolve_targets_user_maps_id_to_display_name(): void {
        $sql = $this->mockWpdbRecords( [
            [ 'id' => '7', 'label' => 'Jane Doe' ],
            [ 'id' => '9', 'label' => '' ],
        ] );

        $map = $this->resolveTargets( [ 7, 9 ], 'user' );

        self::assertStringContainsString( 'wp_users', $sql() );
        self::assertSame( [ 'value' => '7', 'display' => 'Jane Doe', 'term_id' => null ], $map[7] );
        self::assertSame( '9', $map[9]['display'], 'An empty label falls back to the id string.' );
        self::assertNull( $map[9]['term_id'], 'User resolution carries no term_id.' );
    }

    public function test_resolve_targets_term_carries_term_id(): void {
        $sql = $this->mockWpdbRecords( [
            [ 'id' => '12', 'label' => 'Fiction' ],
        ] );

        $map = $this->resolveTargets( [ 12 ], 'term' );

        self::assertStringContainsString( 'wp_terms', $sql() );
        self::assertSame(
            [ 'value' => '12', 'display' => 'Fiction', 'term_id' => 12 ],
            $map[12],
            'A term-resolved row records the source term_id.'
        );
    }

    public function test_resolve_targets_post_still_maps_to_title(): void {
        $sql = $this->mockWpdbRecords( [
            [ 'id' => '3', 'label' => 'Hello World' ],
        ] );

        $map = $this->resolveTargets( [ 3 ], 'post' );

        self::assertStringContainsString( 'wp_posts', $sql() );
        self::assertSame( 'Hello World', $map[3]['display'] );
        self::assertNull( $map[3]['term_id'] );
    }

    public function test_resolve_targets_unknown_kind_returns_empty(): void {
        // Returns before touching $wpdb, so no mock is needed.
        self::assertSame( [], $this->resolveTargets( [ 1, 2 ], 'bogus' ) );
    }

    public function test_resolve_targets_empty_ids_returns_empty(): void {
        self::assertSame( [], $this->resolveTargets( [], 'user' ) );
    }

    public function test_resolve_targets_drops_ids_that_no_longer_exist(): void {
        // Only id 5 comes back from the lookup; 6 was deleted.
        $this->mockWpdbRecords( [
            [ 'id' => '5', 'label' => 'Alice' ],
        ] );

        $map = $this->resolveTargets( [ 5, 6 ], 'user' );

        self::assertArrayHasKey( 5, $map );
        self::assertArrayNotHasKey( 6, $map, 'IDs that no longer resolve are absent from the map.' );
    }

    /**
     * Invoke the private resolve_targets() without changing its production
     * visibility — same approach the repo would use for any DB-bound internal.
     *
     * @param int[] $ids
     * @return array<int, array{value: string, display: string, term_id: ?int}>
     */
    private function resolveTargets( array $ids, string $resolve ): array {
        $indexer = new Indexer();
        $method  = new \ReflectionMethod( $indexer, 'resolve_targets' );
        $method->setAccessible( true );
        return $method->invoke( $indexer, $ids, $resolve );
    }

    /**
     * Mock $wpdb so prepare() is a pass-through (capturing the SQL) and
     * get_results() returns the given records. Returns a closure that yields
     * the last SQL string passed to prepare(), so a test can assert the table.
     *
     * @param array<int, array<string, string>> $records
     * @return callable(): string
     */
    private function mockWpdbRecords( array $records ): callable {
        $captured = '';
        $wpdb = Mockery::mock();
        $wpdb->posts = 'wp_posts';
        $wpdb->users = 'wp_users';
        $wpdb->terms = 'wp_terms';
        $wpdb->shouldReceive( 'prepare' )->andReturnUsing(
            function ( $query, ...$args ) use ( &$captured ) {
                $captured = (string) $query;
                return $query;
            }
        );
        $wpdb->shouldReceive( 'get_results' )->andReturn( $records );
        $GLOBALS['wpdb'] = $wpdb;

        return static function () use ( &$captured ): string {
            return $captured;
        };
    }
}
