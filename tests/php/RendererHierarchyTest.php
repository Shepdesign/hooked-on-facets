<?php
/**
 * @package HookedOnFacets\Tests
 */

declare(strict_types=1);

namespace HookedOnFacets\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HookedOnFacets\Facets\Renderer;
use HookedOnFacets\Filter\Resolver;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Covers hierarchy-facet rendering edge cases that don't need a live WP.
 * WP escaping/format helpers are stubbed as pass-throughs.
 */
final class RendererHierarchyTest extends TestCase {
    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_html_e' )->echoArg();
        Functions\when( 'number_format_i18n' )->returnArg();
        Functions\when( 'checked' )->justReturn( '' );
        // A non-WP_Term return sends every bucket down the "root" branch — enough
        // to exercise the render-row closure without stubbing the WP_Term class.
        Functions\when( 'get_term_by' )->justReturn( false );
        // render_hierarchy's rows now probe PrettyUrls::enabled() per option
        // (pretty-link crawlable-anchor support) — stub get_option() so that
        // resolves to "off" (no permalink_structure) without a live WP.
        Functions\when( 'get_option' )->justReturn( false );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /**
     * Regression: a taxonomy term whose slug is a numeric string ("56") becomes
     * an *integer* array key — PHP coerces numeric-string keys — which was then
     * handed to the render-row closure's `string $slug` parameter. Under
     * strict_types that throws an uncaught TypeError, 500'ing every page that
     * renders the facet. The renderer must tolerate numeric slugs.
     */
    public function test_numeric_term_slug_renders_without_fatal(): void {
        $renderer = new Renderer( new Resolver() );

        $facet = [
            'name'    => 'category',
            'label'   => 'Category',
            'kind'    => 'taxonomy',
            'source'  => 'product_cat',
            'display' => 'hierarchy',
        ];
        $counts = [
            'type'    => 'values',
            'buckets' => [
                [ 'value' => '56', 'display' => 'Fifty-Six', 'count' => 3 ],
                [ 'value' => 'shoes', 'display' => 'Shoes', 'count' => 7 ],
            ],
        ];

        $method = new \ReflectionMethod( Renderer::class, 'render_hierarchy' );
        $method->setAccessible( true );

        $html = $method->invoke( $renderer, $facet, [], $counts );

        self::assertStringContainsString( 'hof-facet-hierarchy', $html );
        self::assertStringContainsString( 'value="56"', $html );
        self::assertStringContainsString( 'value="shoes"', $html );
    }
}
