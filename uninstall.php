<?php
/**
 * Uninstall — runs when the user clicks "Delete" on the plugin.
 *
 * Conservative by default: leaves the index table and configured facets in
 * place so reinstall picks up where the user left off.
 *
 * To opt into full cleanup, either:
 *   1. Set `define( 'HOF_DELETE_DATA', true );` in wp-config.php, or
 *   2. Set the option `hof_uninstall_remove_data` to a truthy value.
 *
 * When opted in, drops:
 *   - the wp_hof_index table
 *   - the `hof_facets`, `hof_db_version`, `hof_uninstall_remove_data`,
 *     `hof_background_reindex_state` options
 *   - the `hof_facet_counts` transient
 *   - any scheduled `hof_background_reindex` events (Action Scheduler + wp_cron)
 *
 * Runs in isolation — no plugin classes are autoloaded here, so this file
 * intentionally uses only WP core APIs and raw $wpdb. Hook / option names are
 * literals here on purpose (the Indexer class isn't loaded); keep them in sync
 * with HookedOnFacets\Indexer.
 *
 * @package HookedOnFacets
 */

declare(strict_types=1);

// Hard exit if not invoked by the WordPress uninstall flow.
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$remove_data = ( defined( 'HOF_DELETE_DATA' ) && HOF_DELETE_DATA )
    || (bool) get_option( 'hof_uninstall_remove_data', false );

if ( ! $remove_data ) {
    return;
}

global $wpdb;

// Drop the flat index table.
$table = $wpdb->prefix . 'hof_index';
$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

// Remove plugin options.
foreach ( [ 'hof_facets', 'hof_db_version', 'hof_uninstall_remove_data', 'hof_background_reindex_state' ] as $option ) {
    delete_option( $option );
}

// Drop any short-lived caches we own.
delete_transient( 'hof_facet_counts' );

// Clear any pending background-reindex chunks under both schedulers.
if ( function_exists( 'as_unschedule_all_actions' ) ) {
    as_unschedule_all_actions( 'hof_background_reindex', array(), 'hooked-on-facets' );
}
wp_clear_scheduled_hook( 'hof_background_reindex' );
