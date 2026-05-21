<?php
/**
 * License state owner.
 *
 * Single source of truth for what the plugin believes about its license:
 *   - the key (stored as-is for re-activation; never exposed back to the UI)
 *   - the most recent status from the EDD store
 *   - expiry, site count, limit, fingerprint for display
 *
 * State lives in `wp_options['hof_license']`. Reads are cheap (one autoloaded
 * option); writes flow through here so cron + admin + REST stay in sync.
 *
 * @package HookedOnFacets\Licensing
 */

declare(strict_types=1);

namespace HookedOnFacets\Licensing;

use HookedOnFacets\Contracts\Bootable;

defined( 'ABSPATH' ) || exit;

final class LicenseManager implements Bootable {

    public const OPTION_KEY = 'hof_license';

    /** Daily revalidation cron hook. */
    private const CRON_HOOK = 'hof_license_revalidate';

    /** How long an activation/check response stays "fresh" before we re-ping. */
    private const STALE_AFTER = 24 * HOUR_IN_SECONDS;

    private LicenseClient $client;

    public function __construct( LicenseClient $client ) {
        $this->client = $client;
    }

    public function register_hooks(): void {
        // Daily revalidation. Survives plugin upgrades because we re-schedule
        // on every activation if not already queued.
        add_action( self::CRON_HOOK, [ $this, 'revalidate' ] );
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
        }
    }

    /**
     * Current state — safe to echo to the admin REST endpoint.
     *
     * @return array{
     *   configured: bool,
     *   status: string,
     *   fingerprint: string,
     *   expires: ?string,
     *   expires_human: ?string,
     *   site_count: ?int,
     *   license_limit: ?int,
     *   last_check: ?int,
     *   enforcement: string,
     *   store_url: string,
     * }
     */
    public function state(): array {
        $raw = (array) get_option( self::OPTION_KEY, [] );
        $key = (string) ( $raw['key'] ?? '' );
        return [
            'configured'    => $key !== '',
            'status'        => (string) ( $raw['status'] ?? ( $key === '' ? 'not_set' : 'unknown' ) ),
            'fingerprint'   => $key !== '' ? $this->fingerprint( $key ) : '',
            'expires'       => $raw['expires']       ?? null,
            'expires_human' => $raw['expires_human'] ?? null,
            'site_count'    => isset( $raw['site_count'] )    ? (int) $raw['site_count']    : null,
            'license_limit' => isset( $raw['license_limit'] ) ? (int) $raw['license_limit'] : null,
            'last_check'    => isset( $raw['last_check'] )    ? (int) $raw['last_check']    : null,
            'enforcement'   => self::enforcement_mode(),
            'store_url'     => self::store_url(),
        ];
    }

    /**
     * Activate a key against the configured EDD store. Persists the result.
     *
     * @return array{ok: bool, status: string, error?: string}
     */
    public function activate( string $key ): array {
        $key = trim( $key );
        if ( $key === '' ) {
            return [ 'ok' => false, 'status' => 'empty', 'error' => 'License key is empty.' ];
        }

        if ( self::is_dev_mode() ) {
            // Local development bypass — pretend the key is valid forever.
            $this->persist( [
                'key'           => $key,
                'status'        => 'valid',
                'expires'       => 'lifetime',
                'expires_human' => 'Never (dev mode)',
                'site_count'    => 1,
                'license_limit' => 1,
                'last_check'    => time(),
            ] );
            return [ 'ok' => true, 'status' => 'valid' ];
        }

        $result = $this->client->activate( $key );
        $this->persist( array_merge( $result, [ 'key' => $key, 'last_check' => time() ] ) );

        return [
            'ok'     => ( $result['status'] ?? '' ) === 'valid',
            'status' => (string) ( $result['status'] ?? 'unknown' ),
            'error'  => $result['error'] ?? null,
        ];
    }

    /**
     * Deactivate the local key (also tells the store if reachable).
     *
     * @return array{ok: bool}
     */
    public function deactivate(): array {
        $raw = (array) get_option( self::OPTION_KEY, [] );
        $key = (string) ( $raw['key'] ?? '' );
        if ( $key !== '' && ! self::is_dev_mode() ) {
            // Fire and forget — even if the store call fails, we wipe locally.
            $this->client->deactivate( $key );
        }
        delete_option( self::OPTION_KEY );
        return [ 'ok' => true ];
    }

    /**
     * Re-check the stored key against the store. Called daily by cron and
     * on demand by the admin.
     */
    public function revalidate(): void {
        $raw = (array) get_option( self::OPTION_KEY, [] );
        $key = (string) ( $raw['key'] ?? '' );
        if ( $key === '' || self::is_dev_mode() ) {
            return;
        }

        // Skip if a check has happened in the last STALE_AFTER seconds and
        // the status is currently valid. Avoids hammering the store on every
        // admin page load when the cron fires reliably.
        $last = isset( $raw['last_check'] ) ? (int) $raw['last_check'] : 0;
        if ( ( $raw['status'] ?? '' ) === 'valid' && ( time() - $last ) < self::STALE_AFTER ) {
            return;
        }

        $result = $this->client->check( $key );
        $this->persist( array_merge( $raw, $result, [ 'key' => $key, 'last_check' => time() ] ) );
    }

    /**
     * Is the plugin currently licensed?  Honors dev mode + enforcement.
     */
    public function is_licensed(): bool {
        if ( self::is_dev_mode() ) return true;
        $raw = (array) get_option( self::OPTION_KEY, [] );
        return ( $raw['status'] ?? '' ) === 'valid';
    }

    /**
     * Configured EDD store URL. Sites override via HOF_LICENSE_STORE_URL.
     */
    public static function store_url(): string {
        if ( defined( 'HOF_LICENSE_STORE_URL' ) && HOF_LICENSE_STORE_URL ) {
            return rtrim( (string) HOF_LICENSE_STORE_URL, '/' );
        }
        return 'https://hookedonfacets.com';
    }

    /**
     * Item ID on the EDD store. Match the Download in EDD Software Licensing.
     */
    public static function item_id(): int {
        if ( defined( 'HOF_LICENSE_ITEM_ID' ) ) return (int) HOF_LICENSE_ITEM_ID;
        return 0; // 0 means unconfigured — the client surfaces a friendly error.
    }

    /**
     * Plugin slug used for update API + WP transients.
     */
    public static function plugin_slug(): string {
        return 'hooked-on-facets';
    }

    /**
     * Enforcement mode: 'soft' (default) shows admin notices but doesn't gate
     * features; 'strict' (future) blocks features until a valid key is set.
     */
    public static function enforcement_mode(): string {
        if ( defined( 'HOF_LICENSE_ENFORCEMENT' ) ) {
            $mode = (string) HOF_LICENSE_ENFORCEMENT;
            return in_array( $mode, [ 'soft', 'strict' ], true ) ? $mode : 'soft';
        }
        return 'soft';
    }

    /**
     * Dev mode bypass — set HOF_LICENSE_DEV_MODE = true in wp-config.php to
     * pretend every key is valid. Useful in CI + local dev.
     */
    public static function is_dev_mode(): bool {
        return defined( 'HOF_LICENSE_DEV_MODE' ) && HOF_LICENSE_DEV_MODE;
    }

    /**
     * Short, non-secret fingerprint for the admin display. First 8 + last 4
     * chars of the key, joined with an ellipsis. The full key never leaves
     * the server after activation.
     */
    private function fingerprint( string $key ): string {
        if ( strlen( $key ) <= 12 ) return str_repeat( '•', max( 0, strlen( $key ) ) );
        return substr( $key, 0, 8 ) . '…' . substr( $key, -4 );
    }

    /**
     * @param array<string, mixed> $partial
     */
    private function persist( array $partial ): void {
        $existing = (array) get_option( self::OPTION_KEY, [] );
        $merged   = array_merge( $existing, $partial );
        // Strip any stray non-scalar values just in case.
        foreach ( $merged as $k => $v ) {
            if ( $v !== null && ! is_scalar( $v ) ) {
                unset( $merged[ $k ] );
            }
        }
        update_option( self::OPTION_KEY, $merged, true );
    }
}
