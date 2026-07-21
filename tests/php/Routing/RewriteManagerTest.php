<?php
/**
 * @package HookedOnFacets\Tests
 */

declare(strict_types=1);

namespace HookedOnFacets\Tests\Routing;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HookedOnFacets\Routing\FilterState;
use HookedOnFacets\Routing\PrettyUrls;
use HookedOnFacets\Routing\RewriteManager;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Covers the pure rewrite-rule generation (plain + paginated variants per
 * base, capture renumbering after taxonomy captures, custom base segment),
 * plus the deferred-flush and 404-guard glue.
 */
final class RewriteManagerTest extends TestCase {
    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        FilterState::reset();
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wpdb'] );
        FilterState::reset();
        Monkey\tearDown();
        // MockeryPHPUnitIntegration closes Mockery and asserts expectations.
        parent::tearDown();
    }

    public function test_shop_base_rules(): void {
        $rules = RewriteManager::build_rules(
            [ [ 'prefix' => 'shop', 'query' => 'post_type=product', 'captures' => 0 ] ],
            'filter'
        );

        self::assertSame(
            [
                '^shop/filter/(.+?)/page/([0-9]{1,})/?$' => 'index.php?post_type=product&hof_path=$matches[1]&paged=$matches[2]',
                '^shop/filter/(.+?)/?$'                  => 'index.php?post_type=product&hof_path=$matches[1]',
            ],
            $rules
        );
    }

    public function test_taxonomy_base_renumbers_captures(): void {
        $rules = RewriteManager::build_rules(
            [ [ 'prefix' => 'product-category/(.+?)', 'query' => 'product_cat=$matches[1]', 'captures' => 1 ] ],
            'filter'
        );

        self::assertSame(
            [
                '^product-category/(.+?)/filter/(.+?)/page/([0-9]{1,})/?$' => 'index.php?product_cat=$matches[1]&hof_path=$matches[2]&paged=$matches[3]',
                '^product-category/(.+?)/filter/(.+?)/?$'                  => 'index.php?product_cat=$matches[1]&hof_path=$matches[2]',
            ],
            $rules
        );
    }

    public function test_custom_base_segment(): void {
        $rules = RewriteManager::build_rules(
            [ [ 'prefix' => 'shop', 'query' => 'post_type=product', 'captures' => 0 ] ],
            'f'
        );
        self::assertArrayHasKey( '^shop/f/(.+?)/?$', $rules );
    }

    public function test_multiple_bases_paginated_rules_stay_grouped_first(): void {
        $rules = RewriteManager::build_rules(
            [
                [ 'prefix' => 'shop', 'query' => 'post_type=product', 'captures' => 0 ],
                [ 'prefix' => 'product-tag/([^/]+)', 'query' => 'product_tag=$matches[1]', 'captures' => 1 ],
            ],
            'filter'
        );
        // Paginated must precede plain per base so /page/2/ isn't swallowed by (.+?).
        $keys = array_keys( $rules );
        self::assertStringContainsString( '/page/', $keys[0] );
        self::assertCount( 4, $rules );
    }

    // ── maybe_flush ─────────────────────────────────────────────────────────

    public function test_maybe_flush_consumes_flag_once(): void {
        $flag = true;
        Functions\when( 'get_option' )->alias(
            function ( $name, $default = false ) use ( &$flag ) {
                return $name === PrettyUrls::FLUSH_FLAG ? ( $flag ? 1 : false ) : $default;
            }
        );
        Functions\expect( 'delete_option' )
            ->once()
            ->with( PrettyUrls::FLUSH_FLAG );
        Functions\expect( 'flush_rewrite_rules' )
            ->once()
            ->with( false );

        $manager = new RewriteManager();

        $manager->maybe_flush(); // Flag present — delete_option + flush fire.

        $flag = false;
        $manager->maybe_flush(); // Flag absent — neither fires again (still exactly once total).
    }

    // ── guard_invalid_path ──────────────────────────────────────────────────

    public function test_guard_404s_invalid_path_and_skips_when_disabled_or_admin_or_non_main(): void {
        Functions\when( 'sanitize_key' )->alias(
            static fn( $s ) => strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) $s ) )
        );
        Functions\when( 'sanitize_text_field' )->alias( static fn( $s ) => trim( (string) $s ) );
        Functions\when( 'sanitize_title' )->alias(
            static fn( $t ) => trim( preg_replace( '/[^a-z0-9-]+/', '-', strtolower( (string) $t ) ), '-' )
        );
        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );

        $options = [
            'permalink_structure' => '/%postname%/',
            'hof_pretty_urls'     => [ 'enabled' => true, 'base' => 'filter' ],
            'hof_facets'          => [
                [ 'name' => 'brand', 'kind' => 'taxonomy', 'display' => 'checkbox' ],
            ],
        ];
        // Reference capture — later sub-cases mutate $options and expect the
        // stub to see the change on the next call.
        Functions\when( 'get_option' )->alias(
            function ( $name, $default = false ) use ( &$options ) {
                return $options[ $name ] ?? $default;
            }
        );

        $GLOBALS['wpdb'] = new class() {
            public string $prefix = 'wp_';
            public function prepare( string $sql, ...$args ): string {
                return str_replace( '%s', "'" . $args[0] . "'", $sql );
            }
            public function get_col( string $sql ): array {
                return str_contains( $sql, "'brand'" ) ? [ 'adidas', 'nike' ] : [];
            }
        };

        $manager = new RewriteManager();

        // 1. Non-main query — must not even reach the PrettyUrls/codec checks.
        Functions\when( 'is_admin' )->justReturn( false );
        FilterState::reset();
        $query = new \WP_Query();
        $query->is_main = false;
        $query->set( 'hof_path', 'brand/puma' );
        $manager->guard_invalid_path( $query );
        self::assertFalse( $query->is_404, 'non-main query must not be 404d' );

        // 2. Admin — first-line guard, short-circuits before anything else.
        Functions\when( 'is_admin' )->justReturn( true );
        FilterState::reset();
        $query = new \WP_Query();
        $query->is_main = true;
        $query->set( 'hof_path', 'brand/puma' );
        $manager->guard_invalid_path( $query );
        self::assertFalse( $query->is_404, 'admin requests must not be 404d' );

        Functions\when( 'is_admin' )->justReturn( false );

        // 3. Empty / non-string hof_path — nothing to validate.
        foreach ( [ '', [ 'brand', 'puma' ] ] as $path ) {
            FilterState::reset();
            $query = new \WP_Query();
            $query->is_main = true;
            $query->set( 'hof_path', $path );
            $manager->guard_invalid_path( $query );
            self::assertFalse( $query->is_404, 'empty/non-string hof_path must not be 404d' );
        }

        // 4. Pretty URLs disabled — known transient-window skip (see the
        // comment on guard_invalid_path()).
        $options['hof_pretty_urls'] = [ 'enabled' => false, 'base' => 'filter' ];
        FilterState::reset();
        $query = new \WP_Query();
        $query->is_main = true;
        $query->set( 'hof_path', 'brand/puma' );
        $manager->guard_invalid_path( $query );
        self::assertFalse( $query->is_404, 'disabled pretty urls must not be 404d' );

        $options['hof_pretty_urls'] = [ 'enabled' => true, 'base' => 'filter' ];

        // 5. Enabled + undecodable path ('puma' isn't a known brand slug) —
        // hard 404. The no-results sentinel must ship too: set_404() alone
        // renders the 404 template but WP::handle_404() only sends the 404
        // STATUS when the query is empty (sandbox-verified soft-404 without it).
        FilterState::reset();
        $query = new \WP_Query();
        $query->is_main = true;
        $query->set( 'hof_path', 'brand/puma' );
        $manager->guard_invalid_path( $query );
        self::assertTrue( $query->is_404, 'unresolvable path must 404' );
        self::assertSame( [ 0 ], $query->get( 'post__in' ), 'query must be emptied so the 404 status ships' );
    }
}
