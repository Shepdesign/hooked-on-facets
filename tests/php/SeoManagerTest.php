<?php
/**
 * @package HookedOnFacets\Tests
 */

declare(strict_types=1);

namespace HookedOnFacets\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HookedOnFacets\Seo\SeoManager;
use PHPUnit\Framework\TestCase;

/**
 * Covers the pure SEO decision logic — canonical URL stripping, noindex
 * thresholds, and the active-filters summary. WP option reads are stubbed.
 */
final class SeoManagerTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        // wp_parse_url is a thin wrapper around parse_url for these inputs.
        Functions\when( 'wp_parse_url' )->alias( static fn( $url ) => parse_url( (string) $url ) );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    /** Stub the two options SeoManager reads: hof_seo and hof_facets. */
    private function withOptions( array $seo = [], array $facets = [] ): void {
        Functions\when( 'get_option' )->alias( static function ( $name, $default = false ) use ( $seo, $facets ) {
            if ( $name === SeoManager::OPTION ) {
                return $seo;
            }
            if ( $name === 'hof_facets' ) {
                return $facets;
            }
            return $default;
        } );
    }

    // ── canonical_url ────────────────────────────────────────────────────────

    public function test_canonical_strips_hof_params_keeps_others(): void {
        $this->withOptions();
        $seo = new SeoManager();

        $out = $seo->canonical_url( 'https://shop.test/store/?hof[brand][]=acme&hof[price][min]=10&utm=x' );

        self::assertSame( 'https://shop.test/store/?utm=x', $out );
    }

    public function test_canonical_with_only_hof_params_yields_clean_path(): void {
        $this->withOptions();
        $seo = new SeoManager();

        $out = $seo->canonical_url( 'https://shop.test/store/?hof[color][]=red' );

        self::assertSame( 'https://shop.test/store/', $out );
    }

    public function test_canonical_preserves_port_and_no_query(): void {
        $this->withOptions();
        $seo = new SeoManager();

        self::assertSame( 'http://localhost:8080/shop/', $seo->canonical_url( 'http://localhost:8080/shop/' ) );
    }

    public function test_canonical_empty_or_invalid_returns_empty(): void {
        $this->withOptions();
        $seo = new SeoManager();

        self::assertSame( '', $seo->canonical_url( '' ) );
        self::assertSame( '', $seo->canonical_url( 'not a url' ) );
    }

    // ── should_noindex ─────────────────────────────────────────────────────────

    public function test_single_facet_is_indexable_by_default(): void {
        $this->withOptions(); // defaults: noindex_combos=true, threshold=2
        $seo = new SeoManager();

        self::assertFalse( $seo->should_noindex( [ 'brand' => [ 'acme' ] ] ) );
    }

    public function test_two_facets_are_noindexed_by_default(): void {
        $this->withOptions();
        $seo = new SeoManager();

        self::assertTrue( $seo->should_noindex( [ 'brand' => [ 'acme' ], 'color' => [ 'red' ] ] ) );
    }

    public function test_reserved_keys_do_not_count_toward_threshold(): void {
        $this->withOptions();
        $seo = new SeoManager();

        // One real facet + two reserved keys → still single-facet, indexable.
        self::assertFalse( $seo->should_noindex( [
            'brand'       => [ 'acme' ],
            '_visual_ids' => [ 1, 2 ],
            '_bin_ids'    => [ 3 ],
        ] ) );
        self::assertSame( 1, $seo->active_facet_count( [
            'brand'       => [ 'acme' ],
            '_visual_ids' => [ 1, 2 ],
        ] ) );
    }

    public function test_noindex_disabled_via_settings(): void {
        $this->withOptions( [ 'noindex_combos' => false ] );
        $seo = new SeoManager();

        self::assertFalse( $seo->should_noindex( [ 'a' => [ '1' ], 'b' => [ '2' ], 'c' => [ '3' ] ] ) );
    }

    public function test_custom_threshold_is_honored(): void {
        $this->withOptions( [ 'noindex_threshold' => 3 ] );
        $seo = new SeoManager();

        self::assertFalse( $seo->should_noindex( [ 'a' => [ '1' ], 'b' => [ '2' ] ] ) );
        self::assertTrue( $seo->should_noindex( [ 'a' => [ '1' ], 'b' => [ '2' ], 'c' => [ '3' ] ] ) );
    }

    // ── filters_summary ──────────────────────────────────────────────────────

    public function test_summary_uses_facet_labels(): void {
        $this->withOptions( [], [
            [ 'name' => 'brand', 'label' => 'Brand' ],
            [ 'name' => 'color', 'label' => 'Colour' ],
        ] );
        $seo = new SeoManager();

        $out = $seo->filters_summary( [ 'brand' => [ 'acme' ], 'color' => [ 'red', 'blue' ] ] );

        self::assertSame( 'Brand: acme, Colour: red, blue', $out );
    }

    public function test_summary_falls_back_to_name_and_renders_range(): void {
        $this->withOptions(); // no facet defs → fall back to the key name
        $seo = new SeoManager();

        self::assertSame( 'price: 10–50', $seo->filters_summary( [ 'price' => [ 'min' => '10', 'max' => '50' ] ] ) );
        self::assertSame( 'price: ≥10', $seo->filters_summary( [ 'price' => [ 'min' => '10' ] ] ) );
        self::assertSame( 'price: ≤50', $seo->filters_summary( [ 'price' => [ 'max' => '50' ] ] ) );
    }

    public function test_summary_skips_reserved_keys(): void {
        $this->withOptions();
        $seo = new SeoManager();

        self::assertSame( 'tag: sale', $seo->filters_summary( [ 'tag' => 'sale', '_bin_ids' => [ 1, 2 ] ] ) );
    }
}
