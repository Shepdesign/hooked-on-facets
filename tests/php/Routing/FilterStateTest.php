<?php
/**
 * @package HookedOnFacets\Tests
 */

declare(strict_types=1);

namespace HookedOnFacets\Tests\Routing;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HookedOnFacets\Routing\FilterState;
use PHPUnit\Framework\TestCase;

/**
 * Covers the merged-state provider: legacy-only, path ⊕ tail with path
 * winning per key, invalid-path flagging, memoization, and reset().
 */
final class FilterStateTest extends TestCase {

    /** @var array<string, mixed> */
    private array $options = [];

    private string $query_var = '';

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $_GET            = [];
        $this->options   = [];
        $this->query_var = '';
        FilterState::reset();

        Functions\when( 'get_option' )->alias( fn( $name, $default = false ) => $this->options[ $name ] ?? $default );
        Functions\when( 'get_query_var' )->alias( fn( $name, $default = '' ) => $name === 'hof_path' ? $this->query_var : $default );
        Functions\when( 'wp_unslash' )->returnArg();
        // sanitize_filter_state() (invoked via legacy_tail()) keys every
        // facet name through sanitize_key() before anything else runs.
        Functions\when( 'sanitize_key' )->alias(
            static fn( $s ) => strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) $s ) )
        );
        Functions\when( 'sanitize_text_field' )->alias( static fn( $s ) => trim( (string) $s ) );
        Functions\when( 'sanitize_title' )->alias(
            static fn( $t ) => trim( preg_replace( '/[^a-z0-9-]+/', '-', strtolower( (string) $t ) ), '-' )
        );
        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );

        // Index rows backing the slug maps: one taxonomy facet "brand".
        $GLOBALS['wpdb'] = new class() {
            public string $prefix = 'wp_';
            public function prepare( string $sql, ...$args ): string {
                return str_replace( '%s', "'" . $args[0] . "'", $sql );
            }
            public function get_col( string $sql ): array {
                return str_contains( $sql, "'brand'" ) ? [ 'adidas', 'nike' ] : [];
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

    private function enablePretty( bool $enabled = true ): void {
        $this->options['permalink_structure'] = '/%postname%/';
        $this->options['hof_pretty_urls']     = [ 'enabled' => $enabled, 'base' => 'filter' ];
        $this->options['hof_facets']          = [
            [ 'name' => 'brand', 'kind' => 'taxonomy', 'display' => 'checkbox' ],
        ];
    }

    public function test_legacy_query_only(): void {
        $_GET['hof'] = [ 'brand' => [ 'nike' ] ];
        self::assertSame( [ 'brand' => [ 'nike' ] ], FilterState::current() );
    }

    public function test_disabled_pretty_ignores_hof_path(): void {
        $this->enablePretty( false );
        $this->query_var = 'brand/nike';
        self::assertSame( [], FilterState::current() );
    }

    public function test_path_state_merges_over_tail(): void {
        $this->enablePretty();
        $this->query_var = 'brand/nike';
        // Tail carries a range AND a stale legacy discrete key — path wins per key.
        $_GET['hof'] = [ 'price' => [ 'min' => '10' ], 'brand' => [ 'stale' ] ];

        self::assertSame(
            [ 'price' => [ 'min' => 10.0 ], 'brand' => [ 'nike' ] ],
            FilterState::current()
        );
        self::assertFalse( FilterState::is_path_invalid() );
    }

    public function test_invalid_path_flags_and_returns_tail_only(): void {
        $this->enablePretty();
        $this->query_var = 'brand/puma'; // unknown value
        $_GET['hof']     = [ 'search' => 'desk' ];

        self::assertSame( [ 'search' => 'desk' ], FilterState::current() );
        self::assertTrue( FilterState::is_path_invalid() );
    }

    public function test_memoized_until_reset(): void {
        $_GET['hof'] = [ 'brand' => [ 'nike' ] ];
        self::assertSame( [ 'brand' => [ 'nike' ] ], FilterState::current() );

        $_GET['hof'] = [ 'brand' => [ 'adidas' ] ];
        self::assertSame( [ 'brand' => [ 'nike' ] ], FilterState::current(), 'memoized' );

        FilterState::reset();
        self::assertSame( [ 'brand' => [ 'adidas' ] ], FilterState::current() );
    }

    public function test_codec_null_without_facets(): void {
        self::assertNull( FilterState::codec() );

        $this->enablePretty();
        FilterState::reset(); // codec() memoizes — a config change needs a reset
        self::assertNotNull( FilterState::codec() );
    }

    public function test_pretty_state_not_memoized_before_parse_request(): void {
        $this->enablePretty();
        $this->query_var = 'brand/nike';
        Functions\when( 'did_action' )->justReturn( 0 );

        self::assertSame( [ 'brand' => [ 'nike' ] ], FilterState::current(),
            'Pre-parse_request read must still resolve the current query var.' );

        // hof_path isn't resolved yet in the pre-parse window — simulate that
        // by clearing it and re-calling. With did_action() still 0, current()
        // must recompute (not return the earlier memoized state).
        $this->query_var = '';
        self::assertSame( [], FilterState::current(),
            'A pre-parse_request read must not lock in a memo — it must recompute.' );

        // Once parse_request has fired, memoization kicks back in.
        Functions\when( 'did_action' )->justReturn( 1 );
        FilterState::reset();
        $this->query_var = 'brand/nike';

        $first = FilterState::current();
        $_GET['hof'] = [ 'search' => 'desk' ]; // mutate between calls
        $second = FilterState::current();

        self::assertSame( $first, $second, 'Post-parse_request state must be memoized.' );
        self::assertSame( [ 'brand' => [ 'nike' ] ], $second );
    }

    public function test_resolver_delegates_to_filter_state(): void {
        $this->enablePretty();
        $this->query_var = 'brand/nike';
        $_GET['hof']     = [ 'search' => 'desk' ];
        Functions\when( 'did_action' )->justReturn( 1 );

        self::assertSame(
            [ 'search' => 'desk', 'brand' => [ 'nike' ] ],
            \HookedOnFacets\Filter\Resolver::parse_request_filters()
        );
    }
}
