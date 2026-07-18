<?php
/**
 * @package HookedOnFacets\Tests
 */

declare(strict_types=1);

namespace HookedOnFacets\Tests\Routing;

use HookedOnFacets\Routing\SlugMapperInterface;
use HookedOnFacets\Routing\UrlCodec;
use PHPUnit\Framework\TestCase;

/**
 * Pure round-trip coverage for the codec: ordering, repeated keys, tail
 * routing, reserved keys, collisions-by-fake, and the hard-404 null returns.
 * No WP — the mapper is a fixture fake.
 */
final class UrlCodecTest extends TestCase {

    /** Fake mapper: brand/color are identity sets; material has a slug map; sku unmappable. */
    private function mapper(): SlugMapperInterface {
        return new class() implements SlugMapperInterface {
            private const SETS = [
                'brand' => [ 'adidas' => 'adidas', 'nike' => 'nike' ],
                'color' => [ 'blue' => 'blue', 'red' => 'red' ],
                'material' => [ 'Solid Oak' => 'solid-oak', 'Walnut' => 'walnut' ],
            ];
            public function slug( string $f, string $v ): ?string {
                return self::SETS[ $f ][ $v ] ?? null;
            }
            public function value( string $f, string $s ): ?string {
                $flipped = array_flip( self::SETS[ $f ] ?? [] );
                return $flipped[ $s ] ?? null;
            }
            public function is_mappable( string $f ): bool {
                return isset( self::SETS[ $f ] );
            }
            public function client_map( string $f ): ?array {
                return null;
            }
        };
    }

    private function codec( string $base = 'filter' ): UrlCodec {
        $defs = [
            [ 'name' => 'brand', 'kind' => 'taxonomy', 'display' => 'checkbox' ],
            [ 'name' => 'color', 'kind' => 'taxonomy', 'display' => 'swatch' ],
            [ 'name' => 'material', 'kind' => 'meta', 'display' => 'dropdown' ],
            [ 'name' => 'price', 'kind' => 'meta', 'display' => 'range' ],
            [ 'name' => 'sku', 'kind' => 'meta', 'display' => 'checkbox' ], // unmappable → tail
        ];
        return new UrlCodec( $defs, $this->mapper(), $base );
    }

    // ── encode ───────────────────────────────────────────────────────────────

    public function test_encode_single_facet_single_value(): void {
        $out = $this->codec()->encode( [ 'brand' => [ 'nike' ] ] );
        self::assertSame( '/filter/brand/nike/', $out['path'] );
        self::assertSame( [], $out['tail'] );
    }

    public function test_encode_orders_facets_by_config_and_values_by_slug(): void {
        // State arrives color-first with values reversed — canonical output anyway.
        $out = $this->codec()->encode( [
            'color' => [ 'red', 'blue' ],
            'brand' => [ 'nike', 'adidas' ],
        ] );
        self::assertSame( '/filter/brand/adidas/brand/nike/color/blue/color/red/', $out['path'] );
    }

    public function test_encode_scalar_value_accepted(): void {
        $out = $this->codec()->encode( [ 'brand' => 'nike' ] );
        self::assertSame( '/filter/brand/nike/', $out['path'] );
    }

    public function test_encode_range_search_reserved_and_unmappable_go_to_tail(): void {
        $state = [
            'brand'    => [ 'nike' ],
            'price'    => [ 'min' => 10.0, 'max' => 50.0 ],
            'search'   => 'oak desk',
            'sku'      => [ 'A100' ],
            '_bin_ids' => [ 12, 34 ],
        ];
        $out = $this->codec()->encode( $state );
        self::assertSame( '/filter/brand/nike/', $out['path'] );
        self::assertSame(
            [
                'price'    => [ 'min' => 10.0, 'max' => 50.0 ],
                'search'   => 'oak desk',
                'sku'      => [ 'A100' ],
                '_bin_ids' => [ 12, 34 ],
            ],
            $out['tail']
        );
    }

    public function test_encode_meta_value_uses_slug(): void {
        $out = $this->codec()->encode( [ 'material' => [ 'Solid Oak' ] ] );
        self::assertSame( '/filter/material/solid-oak/', $out['path'] );
    }

    public function test_encode_empty_or_tail_only_state_has_empty_path(): void {
        self::assertSame( '', $this->codec()->encode( [] )['path'] );
        self::assertSame( '', $this->codec()->encode( [ 'search' => 'x' ] )['path'] );
    }

    public function test_encode_respects_custom_base(): void {
        $out = $this->codec( 'f' )->encode( [ 'brand' => [ 'nike' ] ] );
        self::assertSame( '/f/brand/nike/', $out['path'] );
    }

    // ── decode ───────────────────────────────────────────────────────────────

    public function test_decode_accumulates_repeated_keys(): void {
        self::assertSame(
            [ 'brand' => [ 'adidas', 'nike' ], 'color' => [ 'blue' ] ],
            $this->codec()->decode( 'brand/adidas/brand/nike/color/blue' )
        );
    }

    public function test_decode_resolves_meta_slug_to_value(): void {
        self::assertSame(
            [ 'material' => [ 'Solid Oak' ] ],
            $this->codec()->decode( 'material/solid-oak' )
        );
    }

    public function test_decode_unknown_facet_is_null(): void {
        self::assertNull( $this->codec()->decode( 'ghost/nike' ) );
    }

    public function test_decode_unknown_value_is_null(): void {
        self::assertNull( $this->codec()->decode( 'brand/puma' ) );
    }

    public function test_decode_non_path_facet_is_null(): void {
        self::assertNull( $this->codec()->decode( 'price/10' ) );
        self::assertNull( $this->codec()->decode( 'sku/a100' ) );
    }

    public function test_decode_odd_segments_or_empty_is_null(): void {
        self::assertNull( $this->codec()->decode( 'brand' ) );
        self::assertNull( $this->codec()->decode( '' ) );
    }

    public function test_decode_tolerates_surrounding_slashes(): void {
        self::assertSame(
            [ 'brand' => [ 'nike' ] ],
            $this->codec()->decode( '/brand/nike/' )
        );
    }

    // ── round trip + strip ──────────────────────────────────────────────────

    public function test_round_trip_discrete_state_is_stable(): void {
        $state = [ 'brand' => [ 'adidas', 'nike' ], 'material' => [ 'Walnut' ] ];
        $codec = $this->codec();
        $enc   = $codec->encode( $state );
        // '/filter/…' → codec path input strips the base segment.
        $inner = preg_replace( '#^/filter/#', '', rtrim( $enc['path'], '/' ) );
        self::assertSame( $state, $codec->decode( $inner ) );
    }

    public function test_strip_base_path_removes_filter_suffix(): void {
        $codec = $this->codec();
        self::assertSame( '/shop/', $codec->strip_base_path( '/shop/filter/brand/nike/' ) );
        self::assertSame( '/shop/', $codec->strip_base_path( '/shop/' ) );
        // Only a whole segment named "filter" triggers the strip.
        self::assertSame( '/shop-filter/x/', $codec->strip_base_path( '/shop-filter/x/' ) );
    }
}
