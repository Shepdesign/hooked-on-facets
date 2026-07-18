<?php
/**
 * @package HookedOnFacets\Tests
 */

declare(strict_types=1);

namespace HookedOnFacets\Tests\Routing;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HookedOnFacets\Routing\SlugMapper;
use PHPUnit\Framework\TestCase;

/**
 * Covers forward/reverse mapping, deterministic collision suffixes, the
 * version-scoped cache, and the distinct-value cap. $wpdb is a hand stub;
 * the object cache is an in-memory array.
 */
final class SlugMapperTest extends TestCase {

    /** @var array<string, mixed> */
    private array $cache = [];

    /** @var array<string, list<string>> facet_name → DISTINCT facet_value rows */
    private array $rows = [];

    private int $query_count = 0;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->cache       = [];
        $this->rows        = [];
        $this->query_count = 0;

        Functions\when( 'get_option' )->alias( static fn( $name, $default = false ) => $name === 'hof_index_version' ? 7 : $default );
        Functions\when( 'sanitize_title' )->alias(
            static fn( $t ) => trim( preg_replace( '/[^a-z0-9-]+/', '-', strtolower( (string) $t ) ), '-' )
        );
        Functions\when( 'wp_cache_get' )->alias( fn( $key, $group ) => $this->cache[ "$group:$key" ] ?? false );
        Functions\when( 'wp_cache_set' )->alias( function ( $key, $value, $group, $ttl = 0 ) {
            $this->cache[ "$group:$key" ] = $value;
            return true;
        } );

        // Minimal $wpdb: prepare() interpolates the one %s; get_col() returns fixture rows.
        $rows        = &$this->rows;
        $query_count = &$this->query_count;
        $GLOBALS['wpdb'] = new class( $rows, $query_count ) {
            public string $prefix = 'wp_';
            public function __construct( private array &$rows, private int &$query_count ) {}
            public function prepare( string $sql, ...$args ): string {
                return str_replace( '%s', "'" . $args[0] . "'", $sql );
            }
            public function get_col( string $sql ): array {
                $this->query_count++;
                if ( preg_match( "/facet_name = '([^']+)'/", $sql, $m ) ) {
                    return $this->rows[ $m[1] ] ?? [];
                }
                return [];
            }
        };
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wpdb'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    /** @param list<array<string, mixed>> $defs */
    private function mapper( array $defs ): SlugMapper {
        $by_name = [];
        foreach ( $defs as $d ) {
            $by_name[ $d['name'] ] = $d;
        }
        return new SlugMapper( $by_name );
    }

    public function test_taxonomy_is_identity_but_validates_membership(): void {
        $this->rows['brand'] = [ 'adidas', 'nike' ];
        $m = $this->mapper( [ [ 'name' => 'brand', 'kind' => 'taxonomy' ] ] );

        self::assertSame( 'nike', $m->slug( 'brand', 'nike' ) );
        self::assertSame( 'nike', $m->value( 'brand', 'nike' ) );
        self::assertNull( $m->slug( 'brand', 'puma' ) );
        self::assertNull( $m->value( 'brand', 'puma' ) );
        self::assertNull( $m->client_map( 'brand' ) ); // identity → no client map
    }

    public function test_meta_slugifies_and_reverses(): void {
        $this->rows['material'] = [ 'Solid Oak', 'Walnut' ];
        $m = $this->mapper( [ [ 'name' => 'material', 'kind' => 'meta' ] ] );

        self::assertSame( 'solid-oak', $m->slug( 'material', 'Solid Oak' ) );
        self::assertSame( 'Solid Oak', $m->value( 'material', 'solid-oak' ) );
        self::assertSame(
            [ 'Solid Oak' => 'solid-oak', 'Walnut' => 'walnut' ],
            $m->client_map( 'material' )
        );
    }

    public function test_collisions_get_deterministic_suffixes(): void {
        // Both slugify to "12" under the sanitize_title stub above (each
        // punctuation char is stripped to a trimmed dash) — ORDER BY
        // facet_value order decides who keeps the bare slug.
        $this->rows['size'] = [ '12"', '12*' ];
        $m = $this->mapper( [ [ 'name' => 'size', 'kind' => 'meta' ] ] );

        self::assertSame( '12', $m->slug( 'size', '12"' ) );
        self::assertSame( '12-2', $m->slug( 'size', '12*' ) );
        self::assertSame( '12"', $m->value( 'size', '12' ) );
        self::assertSame( '12*', $m->value( 'size', '12-2' ) );
    }

    public function test_map_is_cached_per_version(): void {
        $this->rows['brand'] = [ 'nike' ];
        $m = $this->mapper( [ [ 'name' => 'brand', 'kind' => 'taxonomy' ] ] );

        $m->slug( 'brand', 'nike' );
        $m->slug( 'brand', 'nike' );
        self::assertSame( 1, $this->query_count );
        self::assertArrayHasKey( 'hof_slugmap:map:brand:v7', $this->cache );
    }

    public function test_second_instance_reuses_cache_without_requerying(): void {
        // Warm the cache with one instance, then drop the fixture rows: a
        // second instance can only resolve via a genuine wp_cache_get hit,
        // so a key-format mismatch between the get and set sites would fail
        // here rather than being masked by the in-request memo.
        $this->rows['brand'] = [ 'nike' ];
        $warm = $this->mapper( [ [ 'name' => 'brand', 'kind' => 'taxonomy' ] ] );
        self::assertSame( 'nike', $warm->slug( 'brand', 'nike' ) );
        self::assertSame( 1, $this->query_count );

        $this->rows = [];
        $cold = $this->mapper( [ [ 'name' => 'brand', 'kind' => 'taxonomy' ] ] );
        self::assertSame( 'nike', $cold->slug( 'brand', 'nike' ) );
        self::assertSame( 1, $this->query_count );
    }

    public function test_real_value_colliding_with_generated_suffix_stays_bijective(): void {
        // 'foo bar' bumps to the generated 'foo-bar-2' — which the real value
        // 'foo-bar-2' then finds taken, bumping again to 'foo-bar-2-2'. The
        // map must stay bijective through the cascade.
        $this->rows['finish'] = [ 'Foo Bar', 'foo bar', 'foo-bar-2' ];
        $m = $this->mapper( [ [ 'name' => 'finish', 'kind' => 'meta' ] ] );

        self::assertSame( 'foo-bar', $m->slug( 'finish', 'Foo Bar' ) );
        self::assertSame( 'foo-bar-2', $m->slug( 'finish', 'foo bar' ) );
        self::assertSame( 'foo-bar-2-2', $m->slug( 'finish', 'foo-bar-2' ) );
        self::assertSame( 'Foo Bar', $m->value( 'finish', 'foo-bar' ) );
        self::assertSame( 'foo bar', $m->value( 'finish', 'foo-bar-2' ) );
        self::assertSame( 'foo-bar-2', $m->value( 'finish', 'foo-bar-2-2' ) );
    }

    public function test_empty_rows_facet_is_unmappable(): void {
        // Configured facet with nothing indexed yet — empty map, not null,
        // but still unmappable to callers.
        $m = $this->mapper( [ [ 'name' => 'material', 'kind' => 'meta' ] ] );

        self::assertFalse( $m->is_mappable( 'material' ) );
        self::assertNull( $m->slug( 'material', 'oak' ) );
        self::assertNull( $m->client_map( 'material' ) );
    }

    public function test_over_cap_facet_is_unmappable(): void {
        Functions\when( 'apply_filters' )->alias(
            static fn( $hook, $value ) => $hook === 'hof_pretty_urls_max_values' ? 2 : $value
        );
        $this->rows['sku'] = [ 'a', 'b', 'c' ];
        $m = $this->mapper( [ [ 'name' => 'sku', 'kind' => 'meta' ] ] );

        self::assertFalse( $m->is_mappable( 'sku' ) );
        self::assertNull( $m->slug( 'sku', 'a' ) );
        self::assertNull( $m->client_map( 'sku' ) );
    }

    public function test_unknown_facet_is_unmappable(): void {
        $m = $this->mapper( [] );
        self::assertFalse( $m->is_mappable( 'ghost' ) );
        self::assertNull( $m->slug( 'ghost', 'x' ) );
        self::assertNull( $m->value( 'ghost', 'x' ) );
    }
}
