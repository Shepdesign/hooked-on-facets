<?php
/**
 * Displays — the registry of facet display types.
 *
 * The free core ships ten displays. Add-ons (HOF Pro) extend the available
 * set at runtime via the `hof_available_displays` filter. Stored facet
 * configs using a known-but-unavailable display (Pro facets while the
 * add-on is inactive) are preserved, not rewritten — they simply don't
 * render or appear in the builder until the add-on returns.
 *
 * @package HookedOnFacets
 */

declare(strict_types=1);

namespace HookedOnFacets\Facets;

defined( 'ABSPATH' ) || exit;

final class Displays {

	/** Display types the free core renders. */
	public const FREE = [
		'checkbox',
		'radio',
		'dropdown',
		'toggle',
		'hierarchy',
		'range',
		'date_range',
		'search',
		'swatch',
		'pagination',
	];

	/**
	 * The signature displays, shipped by the HOF Pro add-on. Named here only
	 * so stored configs survive add-on deactivation (see sanitize path) —
	 * the core contains no code for them.
	 */
	public const SIGNATURE = [
		'ask',
		'visual_dna',
		'swiper',
		'spin_the_wheel',
		'matrix',
		'saved_bin',
	];

	/**
	 * Displays available right now (free + whatever add-ons register).
	 *
	 * @return string[]
	 */
	public static function available(): array {
		/**
		 * Extend the set of available facet displays.
		 *
		 * @param string[] $displays
		 */
		$displays = apply_filters( 'hof_available_displays', self::FREE );
		return array_values( array_unique( array_map( 'strval', (array) $displays ) ) );
	}

	/**
	 * Displays a stored config may reference without being rewritten:
	 * everything available now plus the signature set (so deactivating the
	 * add-on never mangles saved facets).
	 *
	 * @return string[]
	 */
	public static function storable(): array {
		return array_values( array_unique( array_merge( self::available(), self::SIGNATURE ) ) );
	}
}
