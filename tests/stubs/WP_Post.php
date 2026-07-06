<?php
/**
 * Minimal \WP_Post stub so tests can pass a value satisfying the
 * on_post_save( int, \WP_Post, bool ) type hint without a live WordPress.
 * Only the properties the indexer reads are modelled.
 *
 * @package HookedOnFacets\Tests
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_Post' ) ) {
    // phpcs:ignore
    class WP_Post {
        public int $ID = 0;
        public string $post_status = 'publish';
        public string $post_type = 'post';

        /** @param array<string, mixed> $props */
        public function __construct( array $props = [] ) {
            foreach ( $props as $k => $v ) {
                $this->$k = $v;
            }
        }
    }
}
