<?php
/**
 * Deactivator — runs on plugin deactivation.
 *
 * Preserves the wp_hof_index table and configured options so deactivate +
 * reactivate is non-destructive. Schema removal belongs in uninstall.php.
 *
 * @package HookedOnFacets
 */

declare(strict_types=1);

namespace HookedOnFacets;

defined( 'ABSPATH' ) || exit;

final class Deactivator {

    private function __construct() {}

    public static function deactivate(): void {
        // Clear any pending background-reindex chunks under both schedulers.
        // The constant is the single source of truth for the hook name.
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( Indexer::BACKGROUND_HOOK, [], Indexer::AS_GROUP );
        }
        wp_clear_scheduled_hook( Indexer::BACKGROUND_HOOK );

        // Drop any short-lived caches we own.
        delete_transient( 'hof_facet_counts' );

        // Required whenever rewrite-affecting plugins change activation state.
        flush_rewrite_rules();
    }
}
