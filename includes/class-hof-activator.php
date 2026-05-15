<?php
/**
 * Activator — installs the HOF Turbo Indexer flat table on plugin activation.
 *
 * @package HookedOnFacets
 */

declare(strict_types=1);

namespace HookedOnFacets;

defined( 'ABSPATH' ) || exit;

/**
 * Handles one-time install work: schema creation, version stamping, capability
 * setup. Static-only by design — activation is a one-shot procedure, not state.
 */
final class Activator {

    /** Unprefixed table name. Real name is {$wpdb->prefix}hof_index. */
    public const TABLE = 'hof_index';

    /** Bump when the schema changes; triggers re-run of dbDelta on upgrade. */
    public const DB_VERSION = '1.1.0';

    /** Option key storing the installed schema version. */
    public const DB_VERSION_OPTION = 'hof_db_version';

    private function __construct() {}

    /**
     * Fired by register_activation_hook.
     */
    public static function activate(): void {
        self::install_index_table();
        update_option( self::DB_VERSION_OPTION, self::DB_VERSION, true );
    }

    /**
     * Create the flat index table.
     *
     * Schema is intentionally a narrow EAV shape: one row per
     * (object, facet, value). This is the layout that wins on multi-facet
     * intersect filtering at scale — the composite index on
     * (facet_name, facet_value) is the hot path for "give me every object_id
     * matching facet X = Y", and the per-object index handles the reverse
     * lookup needed for active-state highlighting.
     *
     * Why VARCHAR(191) on indexed string columns:
     *   utf8mb4 indexed columns are capped at 191 chars under InnoDB's
     *   default 767-byte index prefix on older row formats. 191 is the safe
     *   universal ceiling and is more than enough for slugs, term names,
     *   and meta values that should be facetable.
     *
     * Why a separate facet_numeric DECIMAL(20,6):
     *   Range filters (price, rating, weight) and the 2D Slider Matrix need
     *   to scan numerics without parsing strings. NULL for non-numeric
     *   facets keeps row size sane.
     */
    public static function install_index_table(): void {
        global $wpdb;

        $table   = $wpdb->prefix . self::TABLE;
        $charset = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$table} (
            id              BIGINT UNSIGNED  NOT NULL AUTO_INCREMENT,
            object_id       BIGINT UNSIGNED  NOT NULL,
            object_type     VARCHAR(32)      NOT NULL DEFAULT 'post',
            facet_name      VARCHAR(64)      NOT NULL,
            facet_source    VARCHAR(64)      NOT NULL DEFAULT '',
            facet_value     VARCHAR(191)     NOT NULL DEFAULT '',
            facet_display   VARCHAR(191)     NOT NULL DEFAULT '',
            facet_numeric   DECIMAL(20,6)             DEFAULT NULL,
            term_id         BIGINT UNSIGNED           DEFAULT NULL,
            parent_id       BIGINT UNSIGNED           DEFAULT NULL,
            depth           TINYINT UNSIGNED NOT NULL DEFAULT 0,
            created_at      DATETIME         NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            -- object_id is appended so leg scans on the resolver hot path are
            -- index-only (no row lookup to fetch object_id from the heap).
            KEY facet_lookup        (facet_name, facet_value, object_id),
            KEY facet_numeric_range (facet_name, facet_numeric, object_id),
            KEY object_facet        (object_id, facet_name),
            KEY object_type         (object_type, object_id),
            KEY term_lookup         (term_id)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }
}
