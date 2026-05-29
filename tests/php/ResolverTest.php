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

    // ── helpers ────────────────────────────────────────────────────────────

    /**
     * @param array<int, array<string, mixed>> $facets
     */
    private function withFacets( array $facets ): void {
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
        $wpdb->shouldReceive( 'get_col' )->andReturn( [] );
        $wpdb->shouldReceive( 'esc_like' )->andReturnUsing( static fn( $s ) => (string) $s );
        $GLOBALS['wpdb'] = $wpdb;
    }
}
