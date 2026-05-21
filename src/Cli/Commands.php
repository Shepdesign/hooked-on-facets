<?php
/**
 * WP-CLI commands.
 *
 *   wp hof reindex                        full reindex with progress bar
 *   wp hof reindex --post=<id>            single object
 *   wp hof status                         index stats: rows, objects, by-facet breakdown
 *   wp hof facets                         list configured facets
 *
 * Registered on the `cli_init` hook so the WP_CLI class is guaranteed loaded.
 * Class loads lazily — outside the CLI context this file imposes no cost.
 *
 * @package HookedOnFacets\Cli
 */

declare(strict_types=1);

namespace HookedOnFacets\Cli;

use HookedOnFacets\Contracts\Bootable;
use HookedOnFacets\Indexer;
use HookedOnFacets\Plugin;

defined( 'ABSPATH' ) || exit;

final class Commands implements Bootable {

    public function register_hooks(): void {
        add_action( 'cli_init', [ $this, 'register' ] );
    }

    public function register(): void {
        if ( ! class_exists( \WP_CLI::class ) ) {
            return;
        }
        \WP_CLI::add_command( 'hof', self::class );
    }

    /**
     * Rebuild the HOF index.
     *
     * ## OPTIONS
     *
     * [--post=<id>]
     * : Reindex a single object_id instead of the full catalog.
     *
     * [--object-type=<type>]
     * : Object type for single-post mode (default 'post').
     *
     * ## EXAMPLES
     *
     *     wp hof reindex
     *     wp hof reindex --post=42
     *
     * @when after_wp_load
     *
     * @param array<int, string>     $args
     * @param array<string, string>  $assoc_args
     */
    public function reindex( array $args, array $assoc_args ): void {
        $indexer = Plugin::instance()->make( Indexer::class );

        if ( isset( $assoc_args['post'] ) ) {
            $post_id     = (int) $assoc_args['post'];
            $object_type = $assoc_args['object-type'] ?? 'post';
            if ( $post_id <= 0 ) {
                \WP_CLI::error( 'Invalid --post value.' );
            }
            $indexer->reindex_object( $post_id, $object_type );
            \WP_CLI::success( "Reindexed {$object_type} {$post_id}." );
            return;
        }

        \WP_CLI::log( 'Rebuilding the full index…' );
        $started = microtime( true );

        // Progress bar — Indexer::bulk_reindex_all accepts a progress
        // callback so we can route per-batch updates to wp-cli.
        $progress = null;
        $started_progress = false;
        $cb = function ( int $done, int $total ) use ( &$progress, &$started_progress ) {
            if ( ! $started_progress ) {
                $progress = \WP_CLI\Utils\make_progress_bar( 'Indexing', $total );
                $started_progress = true;
            }
            // make_progress_bar uses tick(); recompute deltas from cumulative.
            static $last_done = 0;
            $delta = max( 0, $done - $last_done );
            for ( $i = 0; $i < $delta; $i++ ) $progress->tick();
            $last_done = $done;
        };

        $count   = $indexer->bulk_reindex_all( $cb );
        if ( $progress ) $progress->finish();
        $elapsed = round( microtime( true ) - $started, 2 );

        \WP_CLI::success( sprintf( 'Reindexed %d objects in %ss.', $count, $elapsed ) );
    }

    /**
     * Print index stats.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table | json (default: table).
     *
     * @when after_wp_load
     *
     * @param array<int, string>    $args
     * @param array<string, string> $assoc_args
     */
    public function status( array $args, array $assoc_args ): void {
        global $wpdb;
        $table   = $wpdb->prefix . \HookedOnFacets\Activator::TABLE;
        $rows    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        $objects = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT object_id) FROM {$table}" );

        $by_facet = $wpdb->get_results(
            "SELECT facet_name AS name, COUNT(*) AS rows_n, COUNT(DISTINCT object_id) AS objects_n
             FROM {$table}
             GROUP BY facet_name
             ORDER BY facet_name",
            ARRAY_A
        ) ?: [];

        $format = (string) ( $assoc_args['format'] ?? 'table' );
        if ( $format === 'json' ) {
            \WP_CLI::log( wp_json_encode( [
                'rows'     => $rows,
                'objects'  => $objects,
                'by_facet' => $by_facet,
            ], JSON_PRETTY_PRINT ) );
            return;
        }

        \WP_CLI::log( sprintf( 'Index rows:    %s',  number_format_i18n( $rows ) ) );
        \WP_CLI::log( sprintf( 'Distinct IDs:  %s',  number_format_i18n( $objects ) ) );
        \WP_CLI::log( '' );
        \WP_CLI\Utils\format_items( 'table', $by_facet, [ 'name', 'rows_n', 'objects_n' ] );
    }

    /**
     * List configured facets.
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : table | json (default: table).
     *
     * @when after_wp_load
     *
     * @param array<int, string>    $args
     * @param array<string, string> $assoc_args
     */
    public function facets( array $args, array $assoc_args ): void {
        $facets = (array) get_option( Indexer::OPTION_FACETS, [] );
        $rows   = [];
        foreach ( $facets as $f ) {
            if ( ! is_array( $f ) ) continue;
            $rows[] = [
                'name'    => (string) ( $f['name']    ?? '' ),
                'label'   => (string) ( $f['label']   ?? '' ),
                'kind'    => (string) ( $f['kind']    ?? '' ),
                'source'  => (string) ( $f['source']  ?? '' ),
                'display' => (string) ( $f['display'] ?? '' ),
            ];
        }

        $format = (string) ( $assoc_args['format'] ?? 'table' );
        \WP_CLI\Utils\format_items( $format, $rows, [ 'name', 'label', 'kind', 'source', 'display' ] );
    }
}
