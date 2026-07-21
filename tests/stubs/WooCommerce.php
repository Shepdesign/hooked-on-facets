<?php
/**
 * Minimal WooCommerce class stub so class_exists( 'WooCommerce' ) gates can
 * be exercised without a live WooCommerce install. Declared empty — tests
 * only branch on existence, never on behavior.
 *
 * @package HookedOnFacets\Tests
 */

declare(strict_types=1);

if ( ! class_exists( 'WooCommerce' ) ) {
	class WooCommerce {}
}
