<?php
/**
 * Thin EDD Software Licensing API client.
 *
 * Wraps the four `edd_action` endpoints we use:
 *   - activate_license
 *   - deactivate_license
 *   - check_license
 *   - get_version (used by Updater, not directly here)
 *
 * Returns a normalized `{status, expires, expires_human, site_count,
 * license_limit, error?}` shape regardless of which call ran, so
 * LicenseManager doesn't have to know API specifics.
 *
 * @package HookedOnFacets\Licensing
 */

declare(strict_types=1);

namespace HookedOnFacets\Licensing;

defined( 'ABSPATH' ) || exit;

final class LicenseClient {

    private const TIMEOUT_SECS = 8;

    /**
     * @return array{status: string, expires?: string, expires_human?: string,
     *               site_count?: int, license_limit?: int, error?: string}
     */
    public function activate( string $key ): array {
        return $this->call( 'activate_license', $key );
    }

    /**
     * @return array{status: string, error?: string}
     */
    public function deactivate( string $key ): array {
        return $this->call( 'deactivate_license', $key );
    }

    /**
     * @return array{status: string, expires?: string, expires_human?: string,
     *               site_count?: int, license_limit?: int, error?: string}
     */
    public function check( string $key ): array {
        return $this->call( 'check_license', $key );
    }

    /**
     * @return array<string, mixed>
     */
    public function get_version( string $key, string $current_version ): array {
        return $this->raw_request( 'get_version', [
            'license' => $key,
            'version' => $current_version,
            'slug'    => LicenseManager::plugin_slug(),
        ] );
    }

    /**
     * @return array{status: string, expires?: string, expires_human?: string,
     *               site_count?: int, license_limit?: int, error?: string}
     */
    private function call( string $action, string $key ): array {
        $body = $this->raw_request( $action, [
            'license' => $key,
            'url'     => home_url(),
        ] );

        if ( isset( $body['__transport_error'] ) ) {
            return [
                'status' => 'transport_error',
                'error'  => (string) $body['__transport_error'],
            ];
        }

        // EDD SL returns 'success' false on most error cases with 'license'
        // carrying a status string like 'invalid', 'expired', 'missing',
        // 'site_inactive', 'item_name_mismatch'.
        $status = isset( $body['license'] ) ? (string) $body['license'] : 'unknown';

        return [
            'status'        => $status,
            'expires'       => isset( $body['expires'] )        ? (string) $body['expires']        : null,
            'expires_human' => isset( $body['expires_human'] )  ? (string) $body['expires_human']  : null,
            'site_count'    => isset( $body['site_count'] )     ? (int) $body['site_count']        : null,
            'license_limit' => isset( $body['license_limit'] )  ? (int) $body['license_limit']     : null,
            'error'         => isset( $body['error'] )          ? (string) $body['error']          : null,
        ];
    }

    /**
     * @param array<string, string> $params
     * @return array<string, mixed>
     */
    private function raw_request( string $action, array $params ): array {
        $store = LicenseManager::store_url();
        $item  = LicenseManager::item_id();
        if ( $item <= 0 ) {
            return [ '__transport_error' => 'Store item ID not configured (define HOF_LICENSE_ITEM_ID).' ];
        }

        $body = array_merge( $params, [
            'edd_action' => $action,
            'item_id'    => (string) $item,
        ] );

        $response = wp_remote_post( $store, [
            'timeout'   => self::TIMEOUT_SECS,
            'sslverify' => true,
            'body'      => $body,
        ] );

        if ( is_wp_error( $response ) ) {
            return [ '__transport_error' => $response->get_error_message() ];
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        if ( $code < 200 || $code >= 300 ) {
            return [ '__transport_error' => sprintf( 'Store returned HTTP %d', $code ) ];
        }

        $decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $decoded ) ) {
            return [ '__transport_error' => 'Invalid JSON from store' ];
        }
        return $decoded;
    }
}
