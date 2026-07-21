<?php
/**
 * @package HookedOnFacets\Tests
 */

declare(strict_types=1);

namespace HookedOnFacets\Tests\Routing;

use Brain\Monkey;
use HookedOnFacets\Routing\RewriteManager;
use PHPUnit\Framework\TestCase;

/**
 * Covers the pure rewrite-rule generation: plain + paginated variants per
 * base, capture renumbering after taxonomy captures, custom base segment.
 */
final class RewriteManagerTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
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
}
