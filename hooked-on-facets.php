<?php
/**
 * Plugin Name:       Hooked on Facets
 * Plugin URI:        https://hookedonfacets.com
 * Description:       Ultra-modern faceted search and filtering for WordPress + WooCommerce.
 * Version:           0.5.0-alpha
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            shepdesign
 * Author URI:        https://shepdesign.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       hooked-on-facets
 * Domain Path:       /languages
 *
 * @package HookedOnFacets
 */

declare(strict_types=1);

// Hard exit on direct access — never trust the loader.
defined( 'ABSPATH' ) || exit;

// Guard against double-load if the plugin file is included twice.
if ( defined( 'HOF_VERSION' ) ) {
    return;
}

/*
|--------------------------------------------------------------------------
| Constants
|--------------------------------------------------------------------------
| Absolute paths/URLs are resolved once, here. Every other file should read
| from these constants rather than calling plugin_dir_* repeatedly.
*/
define( 'HOF_VERSION',          '0.5.0-alpha' );
define( 'HOF_PLUGIN_FILE',      __FILE__ );
define( 'HOF_PLUGIN_DIR',       plugin_dir_path( __FILE__ ) );
define( 'HOF_PLUGIN_URL',       plugin_dir_url( __FILE__ ) );
define( 'HOF_PLUGIN_BASENAME',  plugin_basename( __FILE__ ) );
define( 'HOF_MIN_PHP',          '8.2' );
define( 'HOF_MIN_WP',           '6.4' );

/*
|--------------------------------------------------------------------------
| Environment gates
|--------------------------------------------------------------------------
| Fail loudly but safely — surface admin notices, return early, never fatal.
*/
if ( version_compare( PHP_VERSION, HOF_MIN_PHP, '<' ) ) {
    add_action( 'admin_notices', static function (): void {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html( sprintf(
                /* translators: 1: required PHP version, 2: current PHP version */
                __( 'Hooked on Facets requires PHP %1$s or higher. You are running %2$s.', 'hooked-on-facets' ),
                HOF_MIN_PHP,
                PHP_VERSION
            ) )
        );
    } );
    return;
}

global $wp_version;
if ( isset( $wp_version ) && version_compare( $wp_version, HOF_MIN_WP, '<' ) ) {
    add_action( 'admin_notices', static function () use ( $wp_version ): void {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html( sprintf(
                /* translators: 1: required WordPress version, 2: current WordPress version */
                __( 'Hooked on Facets requires WordPress %1$s or higher. You are running %2$s.', 'hooked-on-facets' ),
                HOF_MIN_WP,
                $wp_version
            ) )
        );
    } );
    return;
}

/*
|--------------------------------------------------------------------------
| Composer autoload
|--------------------------------------------------------------------------
| Loads PSR-4 (HookedOnFacets\ → src/) plus the classmap covering the legacy
| WordPress-style filenames in includes/. If vendor/ is missing, degrade to
| an admin notice rather than a fatal — the dev hasn't run composer install.
*/
$hof_autoload = HOF_PLUGIN_DIR . 'vendor/autoload.php';
if ( ! is_readable( $hof_autoload ) ) {
    add_action( 'admin_notices', static function (): void {
        printf(
            '<div class="notice notice-error"><p>%s</p></div>',
            esc_html__( 'Hooked on Facets: Composer dependencies are missing. Run `composer install` inside the plugin directory.', 'hooked-on-facets' )
        );
    } );
    return;
}
require_once $hof_autoload;

/*
|--------------------------------------------------------------------------
| Lifecycle hooks
|--------------------------------------------------------------------------
| Activator installs the schema. Deactivator preserves data (only flushes
| rewrites + scheduled events). Uninstall lives in uninstall.php (TBD).
*/
register_activation_hook( __FILE__, [ \HookedOnFacets\Activator::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \HookedOnFacets\Deactivator::class, 'deactivate' ] );

/*
|--------------------------------------------------------------------------
| Boot
|--------------------------------------------------------------------------
| `init` priority 0 — text domain loads before any service registers strings.
| `plugins_loaded` priority 5 — the Plugin container resolves and boots core
| services (Indexer, then QueryHook, REST, admin as each lands) before most
| third-party plugin hooks fire at the default priority 10.
*/
add_action( 'init', static function (): void {
    load_plugin_textdomain(
        'hooked-on-facets',
        false,
        dirname( HOF_PLUGIN_BASENAME ) . '/languages'
    );
}, 0 );

add_action( 'plugins_loaded', static function (): void {
    // Auto-run dbDelta if the installed schema version is older than the
    // constant — covers in-place upgrades that don't re-trigger the
    // activation hook (composer-deployed updates, git pulls in dev, etc.).
    $installed = (string) get_option( \HookedOnFacets\Activator::DB_VERSION_OPTION, '' );
    if ( $installed !== \HookedOnFacets\Activator::DB_VERSION ) {
        \HookedOnFacets\Activator::install_index_table();
        update_option( \HookedOnFacets\Activator::DB_VERSION_OPTION, \HookedOnFacets\Activator::DB_VERSION, true );
    }

    \HookedOnFacets\Plugin::instance()->boot();
}, 5 );
