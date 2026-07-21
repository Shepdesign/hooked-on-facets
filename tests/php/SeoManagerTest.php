<?php
/**
 * @package HookedOnFacets\Tests
 */

declare(strict_types=1);

namespace HookedOnFacets\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HookedOnFacets\Routing\SlugMapperInterface;
use HookedOnFacets\Routing\UrlCodec;
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
        unset( $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD'] );
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

    // ── pretty canonical + redirects ────────────────────────────────────────

    /**
     * Codec fixture: brand (taxonomy checkbox) + price (range) + city
     * (taxonomy checkbox whose mapper returns the raw value verbatim as its
     * slug — used to pin the double-encoded-slug redirect fixed point).
     */
    private function prettyCodec(): UrlCodec {
        $mapper = new class() implements SlugMapperInterface {
            public function slug( string $f, string $v ): ?string {
                if ( $f === 'brand' && in_array( $v, [ 'nike', 'adidas' ], true ) ) {
                    return $v;
                }
                if ( $f === 'city' && $v === '%d0%bf' ) {
                    return $v;
                }
                return null;
            }
            public function value( string $f, string $s ): ?string {
                return $this->slug( $f, $s );
            }
            public function is_mappable( string $f ): bool {
                return $f === 'brand' || $f === 'city';
            }
            public function client_map( string $f ): ?array {
                return null;
            }
        };
        return new UrlCodec(
            [
                [ 'name' => 'brand', 'kind' => 'taxonomy', 'display' => 'checkbox' ],
                [ 'name' => 'price', 'kind' => 'meta', 'display' => 'range' ],
                [ 'name' => 'city', 'kind' => 'taxonomy', 'display' => 'checkbox' ],
            ],
            $mapper,
            'filter'
        );
    }

    private function withTrailingSlashit(): void {
        Functions\when( 'user_trailingslashit' )->alias( static fn( $s ) => rtrim( (string) $s, '/' ) . '/' );
    }

    public function test_pretty_url_for_builds_canonical_path_and_tail(): void {
        $this->withOptions();
        $this->withTrailingSlashit();
        $seo = new SeoManager();

        $out = $seo->pretty_url_for(
            'https://shop.test/shop/?hof%5Bbrand%5D%5B%5D=nike&hof%5Bprice%5D%5Bmin%5D=10&utm=x',
            [ 'brand' => [ 'nike' ], 'price' => [ 'min' => 10 ] ],
            $this->prettyCodec()
        );

        self::assertSame(
            'https://shop.test/shop/filter/brand/nike/?utm=x&hof%5Bprice%5D%5Bmin%5D=10',
            $out
        );
    }

    public function test_pretty_url_for_canonicalizes_segment_order(): void {
        $this->withOptions();
        $this->withTrailingSlashit();
        $seo = new SeoManager();

        // Current URL has values in non-canonical order; state is what decode produced.
        $out = $seo->pretty_url_for(
            'https://shop.test/shop/filter/brand/nike/brand/adidas/',
            [ 'brand' => [ 'nike', 'adidas' ] ],
            $this->prettyCodec()
        );

        self::assertSame( 'https://shop.test/shop/filter/brand/adidas/brand/nike/', $out );
    }

    public function test_pretty_url_for_preserves_pagination(): void {
        $this->withOptions();
        $this->withTrailingSlashit();
        $seo = new SeoManager();

        $out = $seo->pretty_url_for(
            'https://shop.test/shop/page/3/?hof%5Bbrand%5D%5B%5D=nike',
            [ 'brand' => [ 'nike' ] ],
            $this->prettyCodec()
        );

        self::assertSame( 'https://shop.test/shop/filter/brand/nike/page/3/', $out );
    }

    public function test_redirect_target_legacy_to_pretty(): void {
        $this->withOptions();
        $this->withTrailingSlashit();
        $seo = new SeoManager();

        $target = $seo->redirect_target(
            'https://shop.test/shop/?hof%5Bbrand%5D%5B%5D=nike',
            [ 'brand' => [ 'nike' ] ],
            $this->prettyCodec()
        );

        self::assertSame( 'https://shop.test/shop/filter/brand/nike/', $target );
    }

    public function test_redirect_target_loop_guard_on_canonical_url(): void {
        $this->withOptions();
        $this->withTrailingSlashit();
        $seo = new SeoManager();

        self::assertSame( '', $seo->redirect_target(
            'https://shop.test/shop/filter/brand/nike/',
            [ 'brand' => [ 'nike' ] ],
            $this->prettyCodec()
        ) );
    }

    public function test_redirect_target_empty_when_nothing_path_eligible(): void {
        $this->withOptions();
        $this->withTrailingSlashit();
        $seo = new SeoManager();

        self::assertSame( '', $seo->redirect_target(
            'https://shop.test/shop/?hof%5Bprice%5D%5Bmin%5D=10',
            [ 'price' => [ 'min' => 10 ] ],
            $this->prettyCodec()
        ) );
    }

    public function test_pretty_url_for_strips_bare_hof_path_param(): void {
        $this->withOptions();
        $this->withTrailingSlashit();
        $seo = new SeoManager();

        // hof_path is a public query var: /shop/?hof_path=brand/nike serves the
        // same content as the pretty path and must canonicalize to it — the
        // target must never re-emit hof_path (else the 301 loop-guard parks a
        // permanent duplicate).
        $out = $seo->pretty_url_for(
            'https://shop.test/shop/?hof_path=brand%2Fnike&utm=x',
            [ 'brand' => [ 'nike' ] ],
            $this->prettyCodec()
        );

        self::assertSame( 'https://shop.test/shop/filter/brand/nike/?utm=x', $out );
    }

    public function test_redirect_target_fixed_point_with_tail(): void {
        $this->withOptions();
        $this->withTrailingSlashit();
        $seo = new SeoManager();

        // The canonical pretty URL for a state with both a path facet and a
        // range tail re-encodes to itself — the loop guard must recognize
        // that fixed point even though the target carries a ?hof[*] tail.
        self::assertSame( '', $seo->redirect_target(
            'https://shop.test/shop/filter/brand/nike/?hof%5Bprice%5D%5Bmin%5D=10',
            [ 'brand' => [ 'nike' ], 'price' => [ 'min' => 10 ] ],
            $this->prettyCodec()
        ) );
    }

    public function test_pretty_url_for_preserves_foreign_params_exactly(): void {
        $this->withOptions();
        $this->withTrailingSlashit();
        $seo = new SeoManager();

        // 'hoffman' merely starts with 'hof' — an exact-match strip must
        // leave it alone (a prefix match would incorrectly eat it).
        $out = $seo->pretty_url_for(
            'https://shop.test/shop/?hoffman=1&hof%5Bbrand%5D%5B%5D=nike',
            [ 'brand' => [ 'nike' ] ],
            $this->prettyCodec()
        );

        self::assertSame( 'https://shop.test/shop/filter/brand/nike/?hoffman=1', $out );
    }

    public function test_current_url_preserves_percent_encoding(): void {
        $this->withOptions();
        Functions\when( 'is_ssl' )->justReturn( true );
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'sanitize_text_field' )->returnArg();

        $_SERVER['HTTP_HOST']   = 'shop.test';
        $_SERVER['REQUEST_URI'] = '/shop/?hof%5Bbrand%5D%5B%5D=nike';

        $seo    = new SeoManager();
        $method = new \ReflectionMethod( SeoManager::class, 'current_url' );
        $method->setAccessible( true );

        // sanitize_text_field() would strip the %5B/%5D octets — current_url()
        // must not run REQUEST_URI through it (see the method's docblock).
        self::assertSame( 'https://shop.test/shop/?hof%5Bbrand%5D%5B%5D=nike', $method->invoke( $seo ) );
    }

    public function test_redirect_target_double_encoded_slug_fixed_point(): void {
        $this->withOptions();
        $this->withTrailingSlashit();
        $seo = new SeoManager();

        // 'city' maps '%d0%bf' to itself (see prettyCodec()) — encode()
        // rawurlencodes it into the path segment '%25d0%25bf'. Re-decoding
        // that once (redirect_target()'s loop-guard normalizer) must land
        // back on the same string the request carried, or the codec's
        // encode/decode pair isn't actually a fixed point for double-encoded
        // values and this 301 layer would loop forever on such a URL.
        $target = $seo->redirect_target(
            'https://shop.test/shop/filter/city/%25d0%25bf/',
            [ 'city' => [ '%d0%bf' ] ],
            $this->prettyCodec()
        );

        self::assertSame( '', $target );
    }
}
