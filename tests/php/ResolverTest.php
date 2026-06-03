<?php
/**
 * @package HookedOnFacets\Tests
 */

declare(strict_types=1);

namespace HookedOnFacets\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HookedOnFacets\Filter\Resolver;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Covers the filter-subquery SQL shape — in particular OR-within-facet
 * (IN-list) vs. AND-within-facet (one INTERSECT leg per value). $wpdb is
 * mocked: prepare() captures the SQL, get_col() returns nothing.
 */
final class ResolverTest extends TestCase {
    use MockeryPHPUnitIntegration;

    /** @var array{sql: string, params: array<int, mixed>} */
    private array $captured;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->captured = [ 'sql' => '', 'params' => [] ];
        // These tests assert the generated SQL shape, not caching. Disable the
        // result-set cache so a leaked wp_cache_* definition from another test
        // (function_exists stays true process-wide) can't divert us into the
        // cached path. Cache behavior is covered by ResolverCacheTest.
        Functions\when( 'apply_filters' )->alias(
            static fn( $hook, $value = null ) => $hook === 'hof_resolver_cache_enabled' ? false : $value
        );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_or_within_facet_uses_an_in_list(): void {
        $this->withFacets( [
            [ 'name' => 'tags', 'kind' => 'taxonomy', 'display' => 'checkbox', 'settings' => [] ],
        ] );

        ( new Resolver() )->resolve_ids( [ 'tags' => [ 'a', 'b', 'c' ] ] );

        self::assertStringContainsString( 'facet_value IN (%s, %s, %s)', $this->captured['sql'] );
        self::assertStringNotContainsString( 'INTERSECT', $this->captured['sql'],
            'A single OR facet is one leg — no INTERSECT.' );
        self::assertSame( [ 'tags', 'a', 'b', 'c' ], $this->captured['params'] );
    }

    public function test_and_within_facet_emits_one_intersect_leg_per_value(): void {
        $this->withFacets( [
            [ 'name' => 'tags', 'kind' => 'taxonomy', 'display' => 'checkbox', 'settings' => [ 'match' => 'all' ] ],
        ] );

        ( new Resolver() )->resolve_ids( [ 'tags' => [ 'a', 'b', 'c' ] ] );

        // Three single-value legs joined by INTERSECT, no IN-list.
        self::assertSame( 2, substr_count( $this->captured['sql'], ' INTERSECT ' ),
            'Three values → three legs → two INTERSECT joins.' );
        self::assertStringContainsString( 'facet_value = %s', $this->captured['sql'] );
        self::assertStringNotContainsString( ' IN (', $this->captured['sql'] );
        self::assertSame( [ 'tags', 'a', 'tags', 'b', 'tags', 'c' ], $this->captured['params'] );
    }

    public function test_and_within_facet_with_single_value_is_one_leg(): void {
        $this->withFacets( [
            [ 'name' => 'tags', 'kind' => 'taxonomy', 'display' => 'checkbox', 'settings' => [ 'match' => 'all' ] ],
        ] );

        ( new Resolver() )->resolve_ids( [ 'tags' => [ 'a' ] ] );

        self::assertStringNotContainsString( 'INTERSECT', $this->captured['sql'] );
        self::assertStringContainsString( 'facet_value = %s', $this->captured['sql'] );
        self::assertSame( [ 'tags', 'a' ], $this->captured['params'] );
    }

    public function test_matrix_display_defaults_to_and(): void {
        // The matrix is an intersection visualization, so it ANDs its values
        // even with no explicit match setting.
        $this->withFacets( [
            [ 'name' => 'features', 'kind' => 'taxonomy', 'display' => 'matrix', 'settings' => [] ],
        ] );

        ( new Resolver() )->resolve_ids( [ 'features' => [ 'a', 'b' ] ] );

        self::assertSame( 1, substr_count( $this->captured['sql'], ' INTERSECT ' ),
            'Two matrix values → two AND legs → one INTERSECT.' );
        self::assertStringNotContainsString( ' IN (', $this->captured['sql'] );
        self::assertSame( [ 'features', 'a', 'features', 'b' ], $this->captured['params'] );
    }

    public function test_matrix_display_honors_explicit_any_override(): void {
        $this->withFacets( [
            [ 'name' => 'features', 'kind' => 'taxonomy', 'display' => 'matrix', 'settings' => [ 'match' => 'any' ] ],
        ] );

        ( new Resolver() )->resolve_ids( [ 'features' => [ 'a', 'b' ] ] );

        self::assertStringContainsString( 'facet_value IN (%s, %s)', $this->captured['sql'],
            'An explicit match=any overrides the matrix default.' );
    }

    public function test_and_within_one_facet_still_intersects_across_facets(): void {
        $this->withFacets( [
            [ 'name' => 'tags',  'kind' => 'taxonomy', 'display' => 'checkbox', 'settings' => [ 'match' => 'all' ] ],
            [ 'name' => 'brand', 'kind' => 'taxonomy', 'display' => 'checkbox', 'settings' => [] ],
        ] );

        ( new Resolver() )->resolve_ids( [
            'tags'  => [ 'a', 'b' ],   // AND → 2 legs
            'brand' => [ 'acme' ],     // OR  → 1 leg
        ] );

        // 2 (tags) + 1 (brand) = 3 legs → 2 INTERSECT joins.
        self::assertSame( 2, substr_count( $this->captured['sql'], ' INTERSECT ' ) );
        self::assertSame( [ 'tags', 'a', 'tags', 'b', 'brand', 'acme' ], $this->captured['params'] );
    }

    public function test_bin_ids_intersect_the_facet_result(): void {
        // Facet subquery returns 10, 20, 30; the bin holds 20, 30, 40.
        $this->withFacets(
            [ [ 'name' => 'brand', 'kind' => 'taxonomy', 'display' => 'checkbox', 'settings' => [] ] ],
            [ 10, 20, 30 ]
        );

        $ids = ( new Resolver() )->resolve_ids( [
            'brand'    => [ 'acme' ],
            '_bin_ids' => [ 20, 30, 40 ],
        ] );

        // Intersection of the facet result with the bin set.
        self::assertSame( [ 20, 30 ], $ids );
        // The bin key never reaches the SQL as a facet.
        self::assertStringNotContainsString( '_bin_ids', $this->captured['sql'] );
    }

    public function test_bin_ids_stand_alone_when_no_facets_active(): void {
        $this->withFacets( [], [] );

        $ids = ( new Resolver() )->resolve_ids( [ '_bin_ids' => [ 5, 6, 7 ] ] );

        self::assertSame( [ 5, 6, 7 ], $ids,
            'With no facet filters, the bin set is the whole restriction.' );
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /**
     * @param array<int, array<string, mixed>> $facets
     * @param array<int, int>                   $col_result IDs the facet subquery "returns".
     */
    private function withFacets( array $facets, array $col_result = [] ): void {
        Functions\when( 'get_option' )->justReturn( $facets );

        $wpdb = Mockery::mock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive( 'prepare' )->andReturnUsing(
            function ( $sql, $params = [] ) {
                $this->captured['sql']    = (string) $sql;
                $this->captured['params'] = is_array( $params ) ? $params : array_slice( func_get_args(), 1 );
                return (string) $sql;
            }
        );
        $wpdb->shouldReceive( 'get_col' )->andReturn( $col_result );
        $wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $s ) => (string) $s );
        $GLOBALS['wpdb'] = $wpdb;
    }
}
