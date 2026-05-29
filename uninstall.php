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
 * When opted in, drops (per site on multisite):
 *   - the wp_hof_index table
 *   - the `hof_facets`, `hof_db_version`, `hof_uninstall_remove_data`,
 *     `hof_background_reindex_state` options
 *   - the `hof_facet_counts` transient
 *   - any scheduled `hof_background_reindex` events (Action Scheduler + wp_cron)
 *
 * Multisite: every site's data is per-blog, so cleanup runs once per site
 * (switch_to_blog), and the opt-in gate is checked per site — a site that
 * never set hof_uninstall_remove_data keeps its data even if a sibling opted
 * in. The HOF_DELETE_DATA constant, if set, opts in network-wide.
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

/**
 * Whether the current site has opted into full data removal.
 */
function hof_uninstall_should_remove(): bool {
    return ( defined( 'HOF_DELETE_DATA' ) && HOF_DELETE_DATA )
        || (bool) get_option( 'hof_uninstall_remove_data', false );
}

/**
 * Remove all HOF data for the current blog, if it opted in.
 */
function hof_uninstall_cleanup_current_site(): void {
    if ( ! hof_uninstall_should_remove() ) {
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
}

if ( is_multisite() ) {
    foreach ( get_sites( [ 'number' => 0, 'fields' => 'ids' ] ) as $hof_site_id ) {
        switch_to_blog( (int) $hof_site_id );
        hof_uninstall_cleanup_current_site();
        restore_current_blog();
    }
} else {
    hof_uninstall_cleanup_current_site();
}
