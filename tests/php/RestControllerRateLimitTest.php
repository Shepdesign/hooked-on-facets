<?php
/**
 * @package HookedOnFacets\Tests
 */

declare(strict_types=1);

namespace HookedOnFacets\Tests;

use HookedOnFacets\Api\RestController;
use HookedOnFacets\Filter\Resolver;
use HookedOnFacets\Indexer;
use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Pins the per-IP rate limiter and input caps on the public, unauthenticated
 * endpoints, driven through the real visual_dna() handler.
 *
 * The limiter must be a true fixed window: the reset time is anchored at the
 * window's first hit and under-limit traffic must NOT extend it. (The original
 * implementation passed the full window as the transient TTL on every hit, so
 * a legitimate slow caller — e.g. one request per 50s — never let the window
 * expire, accumulated to the cap, and got blocked despite being far under the
 * per-minute rate.)
 *
 * Transients are stubbed via Brain Monkey with captured args; $wpdb is a
 * Mockery mock so we can also prove a rate-limited request does no DB work.
 */
final class RestControllerRateLimitTest extends TestCase {
    use MockeryPHPUnitIntegration;

    /** @var string[] */
    private array $transient_gets;

    /** @var array<array{0: string, 1: mixed, 2: int}> */
    private array $transient_sets;

    /** What get_transient() returns — the "stored" bucket state. */
    private mixed $transient_value;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();

        $this->transient_gets  = [];
        $this->transient_sets  = [];
        $this->transient_value = false;
        Functions\when( 'get_transient' )->alias( function ( string $key ) {
            $this->transient_gets[] = $key;
            return $this->transient_value;
        } );
        Functions\when( 'set_transient' )->alias( function ( string $key, $value, int $ttl ) {
            $this->transient_sets[] = [ $key, $value, $ttl ];
            return true;
        } );
    }

    protected function tearDown(): void {
        unset( $_SERVER['REMOTE_ADDR'] );
        unset( $GLOBALS['wpdb'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    private function controller(): RestController {
        return new RestController( new Resolver(), new Indexer() );
    }

    /** A rate-limited request must never reach the database. */
    private function stub_wpdb_expecting_no_queries(): void {
        $wpdb = Mockery::mock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldNotReceive( 'prepare' );
        $wpdb->shouldNotReceive( 'get_results' );
        $wpdb->shouldNotReceive( 'get_var' );
        $GLOBALS['wpdb'] = $wpdb;
    }

    private function stub_wpdb_returning_rows(): void {
        $wpdb = Mockery::mock();
        $wpdb->prefix = 'wp_';
        $wpdb->shouldReceive( 'prepare' )->andReturnUsing( fn ( string $sql ) => $sql );
        $wpdb->shouldReceive( 'get_results' )->andReturn( [ [ 'object_id' => '5' ] ] );
        $wpdb->shouldReceive( 'get_var' )->andReturn( 3 );
        $GLOBALS['wpdb'] = $wpdb;
    }

    public function test_visual_dna_returns_429_and_skips_db_when_rate_limited(): void {
        // Bucket already exhausted, window still open.
        $this->transient_value = [ 'hits' => PHP_INT_MAX, 'reset' => time() + 30 ];
        $this->stub_wpdb_expecting_no_queries();

        $response = $this->controller()->visual_dna( new \WP_REST_Request( [ 'hex' => '#ff0000' ] ) );

        $this->assertSame( 429, $response->get_status() );
        $this->assertSame( 'rate_limited', $response->get_data()['error_code'] );
        // The blocked request must not rewrite the bucket (that would extend the block).
        $this->assertSame( [], $this->transient_sets );
        // And it must have consulted the visual_dna bucket, not another endpoint's.
        $this->assertStringStartsWith( 'hof_rl_visual_dna_', $this->transient_gets[0] );
    }

    public function test_under_limit_hit_preserves_window_anchor(): void {
        $reset = time() + 10; // 10s left in the current window
        $this->transient_value = [ 'hits' => 1, 'reset' => $reset ];
        $this->stub_wpdb_returning_rows();

        $response = $this->controller()->visual_dna( new \WP_REST_Request( [ 'hex' => '#ff0000' ] ) );

        $this->assertSame( 200, $response->get_status() );
        $this->assertCount( 1, $this->transient_sets );
        [ , $value, $ttl ] = $this->transient_sets[0];
        $this->assertSame( 2, $value['hits'] );
        $this->assertSame( $reset, $value['reset'], 'window anchor must not move on an under-limit hit' );
        $this->assertGreaterThan( 0, $ttl );
        $this->assertLessThanOrEqual( 10, $ttl, 'TTL must be the remaining window, not a fresh full window' );
    }

    public function test_expired_window_resets_counter(): void {
        // Exhausted bucket whose window has already ended: caller is allowed
        // again and the counter restarts at 1 with a fresh anchor.
        $this->transient_value = [ 'hits' => PHP_INT_MAX, 'reset' => time() - 1 ];
        $this->stub_wpdb_returning_rows();

        $response = $this->controller()->visual_dna( new \WP_REST_Request( [ 'hex' => '#ff0000' ] ) );

        $this->assertSame( 200, $response->get_status() );
        $this->assertCount( 1, $this->transient_sets );
        [ , $value ] = $this->transient_sets[0];
        $this->assertSame( 1, $value['hits'] );
        $this->assertGreaterThan( time(), $value['reset'] );
    }

    public function test_legacy_integer_transient_is_treated_as_fresh_window(): void {
        // Buckets written by the pre-window-anchor limiter stored a bare int.
        // They carry no anchor, so treat them as a fresh window rather than
        // guessing — worst case a caller gets one extra window on upgrade.
        $this->transient_value = 19;
        $this->stub_wpdb_returning_rows();

        $response = $this->controller()->visual_dna( new \WP_REST_Request( [ 'hex' => '#ff0000' ] ) );

        $this->assertSame( 200, $response->get_status() );
        [ , $value ] = $this->transient_sets[0];
        $this->assertSame( 1, $value['hits'] );
    }

    public function test_visual_dna_palette_is_capped_at_eight_colors(): void {
        $hexes = [
            '#ff0000', '#00ff00', '#0000ff', '#ffff00', '#ff00ff', '#00ffff',
            '#111111', '#222222', '#333333', '#444444', '#555555', '#666666',
        ];
        $this->stub_wpdb_returning_rows();

        $response = $this->controller()->visual_dna( new \WP_REST_Request( [ 'hexes' => $hexes ] ) );

        $this->assertSame( 200, $response->get_status() );
        $this->assertCount( 8, $response->get_data()['query_palette'] );
    }
}
