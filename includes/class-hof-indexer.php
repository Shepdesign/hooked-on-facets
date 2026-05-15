<?php
/**
 * Indexer — populates wp_hof_index from posts, terms, and post meta.
 *
 * Two entry points:
 *   - reindex_all()    — full rebuild (admin button, wp-cli)
 *   - reindex_object() — single object (incremental, called from WP hooks)
 *
 * Facets to index come from the `hof_facets` option, which the admin SPA
 * writes. Until that option is populated the indexer is a no-op — safe to
 * have hooks active before the UI ships.
 *
 * @package HookedOnFacets
 */

declare(strict_types=1);

namespace HookedOnFacets;

use HookedOnFacets\Contracts\Bootable;

defined( 'ABSPATH' ) || exit;

final class Indexer implements Bootable {

    /** Option key holding the array of facet definitions. */
    public const OPTION_FACETS = 'hof_facets';

    /** Objects per batch in reindex_all(). */
    private const BATCH_SIZE = 200;

    /** Rows per bulk INSERT. Bump down if hitting max_allowed_packet. */
    private const INSERT_CHUNK = 500;

    public function register_hooks(): void {
        add_action( 'save_post',        [ $this, 'on_post_save' ], 20, 3 );
        add_action( 'deleted_post',     [ $this, 'on_post_delete' ], 10, 1 );
        add_action( 'set_object_terms', [ $this, 'on_object_terms_changed' ], 10, 4 );
    }

    // ── Hook callbacks ──────────────────────────────────────────────────────

    public function on_post_save( int $post_id, \WP_Post $post, bool $update ): void {
        if ( empty( $this->get_configured_facets() ) ) {
            return;
        }
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        // Trash → wipe rows. Restore from trash re-fires save_post with a live status, which reindexes.
        if ( in_array( $post->post_status, [ 'auto-draft', 'trash' ], true ) ) {
            $this->delete_object( $post_id );
            return;
        }
        if ( ! in_array( $post->post_type, $this->indexed_post_types(), true ) ) {
            return;
        }

        $this->reindex_object( $post_id );
    }

    public function on_post_delete( int $post_id ): void {
        $this->delete_object( $post_id );
    }

    /**
     * @param int[]  $terms
     * @param int[]  $tt_ids
     */
    public function on_object_terms_changed( int $object_id, array $terms, array $tt_ids, string $taxonomy ): void {
        if ( ! $this->is_indexed_taxonomy( $taxonomy ) ) {
            return;
        }
        $this->reindex_object( $object_id );
    }

    // ── Public API ──────────────────────────────────────────────────────────

    /**
     * Wipe the index and rebuild from scratch.
     *
     * @param callable|null $progress Called as $progress(int $count) after each batch.
     * @return int Total objects indexed.
     */
    public function reindex_all( ?callable $progress = null ): int {
        global $wpdb;
        $table = $wpdb->prefix . Activator::TABLE;

        // TRUNCATE is faster than DELETE for large tables and resets AUTO_INCREMENT.
        $wpdb->query( "TRUNCATE TABLE {$table}" );

        $types               = $this->indexed_post_types();
        $types_placeholders  = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
        $count               = 0;
        $offset              = 0;

        while ( true ) {
            $sql = $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE post_status = 'publish'
                 AND post_type IN ({$types_placeholders})
                 ORDER BY ID ASC
                 LIMIT %d OFFSET %d",
                array_merge( $types, [ self::BATCH_SIZE, $offset ] )
            );

            $post_ids = $wpdb->get_col( $sql );
            if ( empty( $post_ids ) ) {
                break;
            }

            foreach ( $post_ids as $id ) {
                $this->reindex_object( (int) $id );
                $count++;
            }

            if ( $progress ) {
                $progress( $count );
            }

            $offset += self::BATCH_SIZE;
        }

        return $count;
    }

    /**
     * Reindex a single object: clear its rows, then insert fresh ones.
     */
    public function reindex_object( int $object_id, string $object_type = 'post' ): void {
        $this->delete_object( $object_id );

        $rows = $this->gather_rows( $object_id, $object_type );
        if ( empty( $rows ) ) {
            return;
        }

        $this->bulk_insert( $rows );
    }

    public function delete_object( int $object_id ): void {
        global $wpdb;
        $table = $wpdb->prefix . Activator::TABLE;
        $wpdb->delete( $table, [ 'object_id' => $object_id ], [ '%d' ] );
    }

    // ── Internals ───────────────────────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    private function gather_rows( int $object_id, string $object_type ): array {
        $facets = $this->get_configured_facets();
        if ( empty( $facets ) ) {
            return [];
        }

        $rows = [];
        foreach ( $facets as $facet ) {
            $name   = $facet['name']   ?? null;
            $source = $facet['source'] ?? null;
            $kind   = $facet['kind']   ?? null;

            if ( ! $name || ! $source || ! $kind ) {
                continue;
            }

            $rows = array_merge( $rows, match ( $kind ) {
                'taxonomy' => $this->rows_from_taxonomy( $object_id, $object_type, $name, $source ),
                'meta'     => $this->rows_from_meta( $object_id, $object_type, $name, $source ),
                'field'    => $this->rows_from_field( $object_id, $object_type, $name, $source ),
                default    => [],
            } );
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows_from_taxonomy( int $object_id, string $object_type, string $facet_name, string $taxonomy ): array {
        $terms = get_the_terms( $object_id, $taxonomy );
        if ( ! $terms || is_wp_error( $terms ) ) {
            return [];
        }

        $rows = [];
        foreach ( $terms as $term ) {
            $rows[] = [
                'object_id'     => $object_id,
                'object_type'   => $object_type,
                'facet_name'    => $facet_name,
                'facet_source'  => $taxonomy,
                'facet_value'   => $term->slug,
                'facet_display' => $term->name,
                'facet_numeric' => null,
                'term_id'       => (int) $term->term_id,
                'parent_id'     => $term->parent ? (int) $term->parent : null,
                'depth'         => $this->term_depth( $term, $taxonomy ),
            ];
        }
        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows_from_meta( int $object_id, string $object_type, string $facet_name, string $meta_key ): array {
        $values = get_post_meta( $object_id, $meta_key, false );
        if ( empty( $values ) ) {
            return [];
        }

        $rows = [];
        foreach ( $values as $value ) {
            if ( ! is_scalar( $value ) ) {
                continue; // Serialized arrays/objects need a custom adapter (Phase 2).
            }
            $string = (string) $value;
            if ( $string === '' ) {
                continue;
            }

            $rows[] = [
                'object_id'     => $object_id,
                'object_type'   => $object_type,
                'facet_name'    => $facet_name,
                'facet_source'  => $meta_key,
                'facet_value'   => substr( $string, 0, 191 ),
                'facet_display' => substr( $string, 0, 191 ),
                'facet_numeric' => is_numeric( $string ) ? (float) $string : null,
                'term_id'       => null,
                'parent_id'     => null,
                'depth'         => 0,
            ];
        }
        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows_from_field( int $object_id, string $object_type, string $facet_name, string $field ): array {
        $post = get_post( $object_id );
        if ( ! $post ) {
            return [];
        }

        $value = $post->{$field} ?? null;
        if ( ! is_scalar( $value ) || $value === '' ) {
            return [];
        }

        $string = (string) $value;
        return [ [
            'object_id'     => $object_id,
            'object_type'   => $object_type,
            'facet_name'    => $facet_name,
            'facet_source'  => $field,
            'facet_value'   => substr( $string, 0, 191 ),
            'facet_display' => substr( $string, 0, 191 ),
            'facet_numeric' => is_numeric( $string ) ? (float) $string : null,
            'term_id'       => null,
            'parent_id'     => null,
            'depth'         => 0,
        ] ];
    }

    /**
     * Chunked bulk INSERT.
     *
     * wpdb::prepare can't emit NULL via %d/%s, so we build per-row placeholders
     * dynamically — numeric/term/parent columns become either a typed
     * placeholder or the literal `NULL`, and we only push their values into
     * the params array when they're non-null.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    private function bulk_insert( array $rows ): void {
        global $wpdb;
        $table = $wpdb->prefix . Activator::TABLE;

        foreach ( array_chunk( $rows, self::INSERT_CHUNK ) as $chunk ) {
            $value_clauses = [];
            $params        = [];

            foreach ( $chunk as $row ) {
                $value_clauses[] = sprintf(
                    '(%%d, %%s, %%s, %%s, %%s, %%s, %s, %s, %s, %%d)',
                    $row['facet_numeric'] !== null ? '%f' : 'NULL',
                    $row['term_id']       !== null ? '%d' : 'NULL',
                    $row['parent_id']     !== null ? '%d' : 'NULL'
                );

                $params[] = $row['object_id'];
                $params[] = $row['object_type'];
                $params[] = $row['facet_name'];
                $params[] = $row['facet_source'];
                $params[] = $row['facet_value'];
                $params[] = $row['facet_display'];
                if ( $row['facet_numeric'] !== null ) { $params[] = $row['facet_numeric']; }
                if ( $row['term_id']       !== null ) { $params[] = $row['term_id']; }
                if ( $row['parent_id']     !== null ) { $params[] = $row['parent_id']; }
                $params[] = $row['depth'];
            }

            $sql = "INSERT INTO {$table}
                    (object_id, object_type, facet_name, facet_source, facet_value, facet_display, facet_numeric, term_id, parent_id, depth)
                    VALUES " . implode( ', ', $value_clauses );

            $wpdb->query( $wpdb->prepare( $sql, $params ) );
        }
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function get_configured_facets(): array {
        $facets = get_option( self::OPTION_FACETS, [] );
        return is_array( $facets ) ? $facets : [];
    }

    private function is_indexed_taxonomy( string $taxonomy ): bool {
        foreach ( $this->get_configured_facets() as $facet ) {
            if ( ( $facet['kind'] ?? null ) === 'taxonomy' && ( $facet['source'] ?? null ) === $taxonomy ) {
                return true;
            }
        }
        return false;
    }

    private function term_depth( \WP_Term $term, string $taxonomy ): int {
        $depth   = 0;
        $current = $term;
        while ( $current->parent && $depth < 20 ) {
            $parent = get_term( $current->parent, $taxonomy );
            if ( ! $parent || is_wp_error( $parent ) ) {
                break;
            }
            $current = $parent;
            $depth++;
        }
        return $depth;
    }

    /**
     * Post types eligible for indexing.
     *
     * @return string[]
     */
    private function indexed_post_types(): array {
        /**
         * Filter post types eligible for HOF indexing.
         *
         * @param string[] $types
         */
        return apply_filters( 'hof_indexed_post_types', [ 'post', 'page', 'product' ] );
    }
}
