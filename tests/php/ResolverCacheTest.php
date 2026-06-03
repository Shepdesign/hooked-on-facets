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
 * Covers the resolver's result-set cache: a second identical resolve_ids hits
 * the (stubbed) object cache instead of the DB, and an index-version bump
 * orphans the old key so the next resolve re-queries. wp_cache_* are backed by
 * an in-memory store; $wpdb->get_col is a spy that counts real queries.
 */
final class ResolverCacheTest extends TestCase {
    use MockeryPHPUnitIntegration;

    /** @var array<string, mixed> */
    private array $cache;
    private int $queryCount;
    private int $indexVersion;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->cache        = [];
        $this->queryCount   = 0;
        $this->indexVersion = 0;

        // Object cache — keyed "group:key" so groups stay isolated.
        Functions\when( 'wp_cache_get' )->alias( fn( $key, $group = '' ) => $this->cache["$group:$key"] ?? false );
        Functions\when( 'wp_cache_set' )->alias( function ( $key, $value, $group = '', $ttl = 0 ) {
            $this->cache["$group:$key"] = $value;
            return true;
        } );

        // wp_json_encode → json_encode; index version read from a test field.
        Functions\when( 'wp_json_encode' )->alias( static fn( $data ) => json_encode( $data ) );
        Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
            if ( $name === Resolver::VERSION_OPTION ) {
                return $this->indexVersion;
            }
            if ( $name === 'hof_facets' ) {
                return [ [ 'name' => 'brand', 'kind' => 'taxonomy', 'display' => 'checkbox', 'settings' => [] ] ];
            }
            return $default;
        } );

        $wpdb = Mockery::mock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive( 'prepare' )->andReturnUsing( static fn( $sql ) => $sql );
        $wpdb->shouldReceive( 'get_col' )->andReturnUsing( function () {
            $this->queryCount++;
            return [ '1', '2', '3' ];
        } );
        $GLOBALS['wpdb'] = $wpdb;
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_second_identical_resolve_hits_cache(): void {
        $resolver = new Resolver();
        $state    = [ 'brand' => [ 'acme' ] ];

        $first  = $resolver->resolve_ids( $state );
        $second = $resolver->resolve_ids( $state );

        self::assertSame( [ 1, 2, 3 ], $first );
        self::assertSame( [ 1, 2, 3 ], $second );
        self::assertSame( 1, $this->queryCount, 'The cached second call must not re-query the DB.' );
    }

    public function test_version_bump_busts_the_cache(): void {
        $resolver = new Resolver();
        $state    = [ 'brand' => [ 'acme' ] ];

        $resolver->resolve_ids( $state );           // query #1, cached under v0
        $this->indexVersion = 1;                    // an index write bumped the version
        $resolver->resolve_ids( $state );           // v1 key misses → query #2

        self::assertSame( 2, $this->queryCount, 'A version bump must invalidate the cached result.' );
    }

    public function test_per_user_reserved_keys_are_not_cached(): void {
        $resolver = new Resolver();
        // _bin_ids present → skip cache; both calls must re-query.
        $state = [ 'brand' => [ 'acme' ], '_bin_ids' => [ 5, 6 ] ];

        $resolver->resolve_ids( $state );
        $resolver->resolve_ids( $state );

        self::assertSame( 2, $this->queryCount, 'Filters carrying a reserved per-user key are never cached.' );
    }

    public function test_cache_disabled_via_filter(): void {
        Functions\when( 'apply_filters' )->alias( static function ( $hook, $value ) {
            return $hook === 'hof_resolver_cache_enabled' ? false : $value;
        } );

        $resolver = new Resolver();
        $state    = [ 'brand' => [ 'acme' ] ];

        $resolver->resolve_ids( $state );
        $resolver->resolve_ids( $state );

        self::assertSame( 2, $this->queryCount, 'The kill-switch filter disables caching.' );
    }
}
