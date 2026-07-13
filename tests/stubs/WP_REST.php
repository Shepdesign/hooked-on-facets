<?php
/**
 * Minimal WP_REST_Request / WP_REST_Response stubs for driving REST handler
 * methods directly (no live WordPress). Only the surface the handlers touch:
 * get_param() on the request; data + status on the response.
 *
 * @package HookedOnFacets\Tests
 */

declare(strict_types=1);

if ( ! class_exists( 'WP_REST_Request' ) ) {
    class WP_REST_Request {
        /** @param array<string, mixed> $params */
        public function __construct( private array $params = [] ) {}

        public function get_param( string $key ): mixed {
            return $this->params[ $key ] ?? null;
        }
    }
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
    class WP_REST_Response {
        public function __construct( private mixed $data = null, private int $status = 200 ) {}

        public function get_data(): mixed {
            return $this->data;
        }

        public function get_status(): int {
            return $this->status;
        }
    }
}
