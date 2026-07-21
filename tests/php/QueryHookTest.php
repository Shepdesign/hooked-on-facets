<?php
/**
 * @package HookedOnFacets\Tests
 */

declare(strict_types=1);

namespace HookedOnFacets\Tests;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HookedOnFacets\Filter\Resolver;
use HookedOnFacets\QueryHook;
use HookedOnFacets\Routing\FilterState;
use PHPUnit\Framework\TestCase;

/**
 * Covers the WooCommerce interplay of the query hook: post__in lands on the
 * query AND survives WC_Query::product_query(), which overwrites post__in
 * at pre_get_posts@10 with the result of its loop_shop_post_in filter
 * (default []) — the sandbox-verified soft failure that made every main-query
 * filter a no-op on live WooCommerce.
 */
final class QueryHookTest extends TestCase {

    /** @var callable|null The loop_shop_post_in callback QueryHook registers. */
    private $captured_filter = null;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $_GET = [];
        FilterState::reset();
        $this->captured_filter = null;

        Functions\when( 'is_admin' )->justReturn( false );
        Functions\when( 'get_option' )->alias( static function ( $name, $default = false ) {
            if ( $name === 'hof_facets' ) {
                return [ [ 'name' => 'brand', 'kind' => 'taxonomy', 'source' => 'product_brand', 'display' => 'checkbox' ] ];
            }
            return $default;
        } );
        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );
        Functions\when( 'sanitize_text_field' )->alias( static fn( $s ) => trim( (string) $s ) );
        Functions\when( 'sanitize_key' )->alias(
            static fn( $s ) => strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) $s ) )
        );
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );

        // The resolver's INTERSECT query — two matching product ids.
        $GLOBALS['wpdb'] = new class() {
            public string $prefix = 'wp_';
            public function prepare( string $sql, ...$args ): string {
                foreach ( $args as $a ) {
                    $replacement = is_scalar( $a ) ? (string) $a : '';
                    $sql         = preg_replace( '/%[sdf]/', "'" . $replacement . "'", $sql, 1 );
                }
                return $sql;
            }
            public function get_col( string $sql ): array {
                return [ 29, 49 ];
            }
        };
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wpdb'] );
        $_GET = [];
        FilterState::reset();
        Monkey\tearDown();
        parent::tearDown();
    }

    /** Opt-in query carrying programmatic filters — no $_GET plumbing needed. */
    private function interceptedQuery(): \WP_Query {
        $query = new \WP_Query();
        $query->is_main = false;
        $query->set( 'hof_facet_target', true );
        $query->set( 'hof_filters', [ 'brand' => [ 'acme' ] ] );
        return $query;
    }

    public function test_sets_post_in_and_feeds_loop_shop_post_in(): void {
        Filters\expectAdded( 'loop_shop_post_in' )->once()->whenHappen( function ( $cb ) {
            $this->captured_filter = $cb;
        } );

        $query = $this->interceptedQuery();
        ( new QueryHook( new Resolver() ) )->on_pre_get_posts( $query );

        self::assertSame( [ 29, 49 ], $query->get( 'post__in' ), 'ids must land on the query directly' );
        self::assertNotNull( $this->captured_filter, 'loop_shop_post_in must be fed for WC-managed queries' );

        // WC calls the filter with [] by default — our ids must come back.
        self::assertSame( [ 29, 49 ], ( $this->captured_filter )( [] ) );
        // Another plugin already narrowed — intersect, never widen.
        self::assertSame( [ 49 ], ( $this->captured_filter )( [ 49, 999 ] ) );
        // Disjoint contribution — the no-results sentinel, not an empty
        // array (empty means "no restriction" to WC).
        self::assertSame( [ 0 ], ( $this->captured_filter )( [ 999 ] ) );
    }
}
