<?php
/**
 * @package HookedOnFacets\Tests
 */

declare(strict_types=1);

namespace HookedOnFacets\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HookedOnFacets\Admin\SwatchTermFields;
use HookedOnFacets\Facets\Renderer;
use HookedOnFacets\Filter\Resolver;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

/**
 * Covers the Visual DNA color-term map for taxonomy targets.
 *
 * Regression: the map builder read term meta key `swatch_color`, but the
 * Color Swatch admin fields write `_hof_swatch_color`
 * (SwatchTermFields::META_COLOR). Every admin-entered swatch hex was
 * ignored — terms whose slug happened to be a CSS color name silently fell
 * back to the CSS hex, and everything else was dropped from the palette.
 */
final class RendererVisualDnaColorMapTest extends TestCase {
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'is_wp_error' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_map_prefers_swatch_term_meta_over_css_fallback(): void {
		$terms = [
			// Slug collides with a CSS color name — the stored swatch hex
			// must win over the fallback table's #dc143c.
			new \WP_Term( [ 'term_id' => 7, 'slug' => 'crimson', 'name' => 'crimson' ] ),
			// Not a CSS color name — only reachable via the stored meta.
			new \WP_Term( [ 'term_id' => 8, 'slug' => 'sand', 'name' => 'sand' ] ),
		];
		Functions\when( 'get_terms' )->justReturn( $terms );

		$stored = [
			7 => '#B3202F',
			8 => '#D8C8A8',
		];
		Functions\when( 'get_term_meta' )->alias(
			static function ( int $term_id, string $key ) use ( $stored ): string {
				return $key === SwatchTermFields::META_COLOR ? ( $stored[ $term_id ] ?? '' ) : '';
			}
		);

		$method = new \ReflectionMethod( Renderer::class, 'build_color_term_map_taxonomy' );
		$method->setAccessible( true );
		$map = $method->invoke(
			new Renderer( new Resolver() ),
			[ 'name' => 'color', 'kind' => 'taxonomy', 'source' => 'pa_color', 'display' => 'swatch' ]
		);

		$by_slug = array_column( $map, 'hex', 'slug' );

		$this->assertSame( '#B3202F', $by_slug['crimson'] ?? null, 'stored swatch hex must beat the CSS fallback' );
		$this->assertSame( '#D8C8A8', $by_slug['sand'] ?? null, 'non-CSS-named term must resolve via stored swatch meta' );
	}
}
