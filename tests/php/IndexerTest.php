<?php
/**
 * @package HookedOnFacets\Tests
 */

declare(strict_types=1);

namespace HookedOnFacets\Tests;

use HookedOnFacets\Indexer;
use PHPUnit\Framework\TestCase;

/**
 * Covers normalize_meta_values() — the serialized-array adapter that both
 * meta gatherers funnel through. Pure logic, no WordPress / DB needed.
 */
final class IndexerTest extends TestCase {

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

    public function test_extract_ids_skips_non_scalar_elements(): void {
        self::assertSame( [ 12 ], $this->ids( [ [ 'nested' ], '12', (object) [ 'a' => 1 ] ] ) );
    }

    public function test_extract_ids_empty(): void {
        self::assertSame( [], $this->ids( [] ) );
        self::assertSame( [], $this->ids( '' ) );
    }
}
