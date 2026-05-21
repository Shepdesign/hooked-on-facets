<?php
/**
 * WordPress plugin-updater glue for EDD Software Licensing.
 *
 * Hooks two filters:
 *   - `pre_set_site_transient_update_plugins` — adds our plugin to the
 *     "updates available" list when the store reports a newer version.
 *   - `plugins_api` — fills in the "View details" modal on the Plugins
 *     screen with the store's changelog / description.
 *
 * Both calls cache results in a transient for an hour so admin page loads
 * don't trigger a store round-trip every time. Disabled entirely when
 * dev-mode is on or when no license is configured (since the package URL
 * requires a valid key).
 *
 * @package HookedOnFacets\Licensing
 */

declare(strict_types=1);

namespace HookedOnFacets\Licensing;

use HookedOnFacets\Contracts\Bootable;

defined( 'ABSPATH' ) || exit;

final class Updater implements Bootable {

    private const CACHE_KEY = 'hof_license_update_cache';
    private const CACHE_TTL = HOUR_IN_SECONDS;

    private LicenseClient $client;

    public function __construct( LicenseClient $client ) {
        $this->client = $client;
    }

    public function register_hooks(): void {
        add_filter( 'pre_set_site_transient_update_plugins', [ $this, 'inject_update' ] );
        add_filter( 'plugins_api',                            [ $this, 'plugin_details' ], 10, 3 );

        // Bust the cache when license state changes — activating a new key
        // should immediately re-check available versions.
        add_action( 'update_option_' . LicenseManager::OPTION_KEY, [ $this, 'clear_cache' ] );
        add_action( 'delete_option_' . LicenseManager::OPTION_KEY, [ $this, 'clear_cache' ] );
    }

    public function clear_cache(): void {
        delete_transient( self::CACHE_KEY );
    }

    /**
     * Inject an "update available" entry into the plugins transient.
     *
     * @param object|mixed $transient
     * @return object|mixed
     */
    public function inject_update( $transient ) {
        if ( ! is_object( $transient ) ) {
            return $transient;
        }
        $info = $this->fetch_version_info();
        if ( $info === null ) {
            return $transient;
        }

        $current = defined( 'HOF_VERSION' ) ? (string) HOF_VERSION : '0.0.0';
        $new     = (string) ( $info['new_version'] ?? '' );
        if ( $new === '' || version_compare( $new, $current, '<=' ) ) {
            return $transient;
        }

        if ( ! isset( $transient->response ) || ! is_array( $transient->response ) ) {
            $transient->response = [];
        }

        $plugin_file = $this->plugin_file();
        $transient->response[ $plugin_file ] = (object) [
            'id'          => LicenseManager::plugin_slug(),
            'slug'        => LicenseManager::plugin_slug(),
            'plugin'      => $plugin_file,
            'new_version' => $new,
            'url'         => (string) ( $info['homepage'] ?? '' ),
            'package'     => (string) ( $info['package']  ?? '' ),
            'tested'      => (string) ( $info['tested']   ?? '' ),
            'requires'    => (string) ( $info['requires'] ?? '' ),
            'icons'       => $this->arr( $info, 'icons' ),
            'banners'     => $this->arr( $info, 'banners' ),
        ];

        return $transient;
    }

    /**
     * Provide the "View details" modal payload. WP calls plugins_api for
     * many things; we only respond when slug matches us.
     *
     * @param false|object|array $result
     * @param string             $action
     * @param object             $args
     * @return false|object|array
     */
    public function plugin_details( $result, $action, $args ) {
        if ( $action !== 'plugin_information' ) return $result;
        if ( ! isset( $args->slug ) || $args->slug !== LicenseManager::plugin_slug() ) return $result;

        $info = $this->fetch_version_info();
        if ( $info === null ) return $result;

        return (object) [
            'name'          => (string) ( $info['name']         ?? 'Hooked on Facets' ),
            'slug'          => LicenseManager::plugin_slug(),
            'version'       => (string) ( $info['new_version']  ?? '' ),
            'author'        => '<a href="https://shepdesign.com">shepdesign</a>',
            'homepage'      => (string) ( $info['homepage']     ?? '' ),
            'requires'      => (string) ( $info['requires']     ?? '' ),
            'tested'        => (string) ( $info['tested']       ?? '' ),
            'last_updated'  => (string) ( $info['last_updated'] ?? '' ),
            'download_link' => (string) ( $info['package']      ?? '' ),
            'sections'      => $this->arr( $info, 'sections' ),
            'banners'       => $this->arr( $info, 'banners' ),
        ];
    }

    /**
     * Fetch + cache the get_version payload. Returns null when no license
     * is configured, when we're in dev mode (no remote update to chase),
     * or on transport failure.
     *
     * @return array<string, mixed>|null
     */
    private function fetch_version_info(): ?array {
        if ( LicenseManager::is_dev_mode() ) return null;
        if ( LicenseManager::item_id() <= 0 ) return null;

        $cached = get_transient( self::CACHE_KEY );
        if ( is_array( $cached ) ) {
            return $cached;
        }

        $key = (string) ( ( (array) get_option( LicenseManager::OPTION_KEY, [] ) )['key'] ?? '' );
        if ( $key === '' ) return null; // No license = no updates.

        $current = defined( 'HOF_VERSION' ) ? (string) HOF_VERSION : '0.0.0';
        $info    = $this->client->get_version( $key, $current );
        if ( ! isset( $info['new_version'] ) ) {
            // Cache the negative result too — short TTL prevents hammering
            // the store when it's misbehaving.
            set_transient( self::CACHE_KEY, [], 10 * MINUTE_IN_SECONDS );
            return null;
        }

        set_transient( self::CACHE_KEY, $info, self::CACHE_TTL );
        return $info;
    }

    private function plugin_file(): string {
        // e.g. "hooked-on-facets/hooked-on-facets.php" — what plugins_api / the
        // update transient keys plugin entries by.
        if ( ! defined( 'HOF_PLUGIN_BASENAME' ) ) {
            return 'hooked-on-facets/hooked-on-facets.php';
        }
        return (string) HOF_PLUGIN_BASENAME;
    }

    /**
     * @return array<string, mixed>
     */
    private function arr( array $info, string $key ): array {
        return isset( $info[ $key ] ) && is_array( $info[ $key ] ) ? $info[ $key ] : [];
    }
}
