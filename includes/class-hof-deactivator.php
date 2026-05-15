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
        // Clear scheduled reindex events (Action Scheduler-backed in Phase 2).
        wp_clear_scheduled_hook( 'hof_reindex_batch' );

        // Drop any short-lived caches we own.
        delete_transient( 'hof_facet_counts' );

        // Required whenever rewrite-affecting plugins change activation state.
        flush_rewrite_rules();
    }
}
