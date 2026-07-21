<?php
/**
 * Minimal \WP_Term stub so tests can satisfy the renderer's
 * `$term instanceof \WP_Term` checks without a live WordPress.
 * Only the properties the color-map builder reads are modelled.
 *
 * @package HookedOnFacets\Tests
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_Term' ) ) {
	// phpcs:ignore
	class WP_Term {
		public int $term_id  = 0;
		public string $slug  = '';
		public string $name  = '';

		/** @param array<string, mixed> $props */
		public function __construct( array $props = [] ) {
			foreach ( $props as $k => $v ) {
				$this->$k = $v;
			}
		}
	}
}
