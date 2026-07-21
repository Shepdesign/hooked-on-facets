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

        // Drop HOF's pretty-URL rules before flushing — deactivation runs
        // after init, so extra_rules_top still carries them and a plain
        // flush would re-persist rules whose hof_path query var no longer
        // exists (leaving /filter/ URLs serving unfiltered 200s).
        if ( isset( $GLOBALS['wp_rewrite'] ) && is_array( $GLOBALS['wp_rewrite']->extra_rules_top ?? null ) ) {
            foreach ( $GLOBALS['wp_rewrite']->extra_rules_top as $regex => $target ) {
                if ( is_string( $target ) && str_contains( $target, 'hof_path=' ) ) {
                    unset( $GLOBALS['wp_rewrite']->extra_rules_top[ $regex ] );
                }
            }
        }

        // Required whenever rewrite-affecting plugins change activation state.
        flush_rewrite_rules();
    }
}
