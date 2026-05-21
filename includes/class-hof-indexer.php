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

    /** Posts per batch in the bulk rebuild path. */
    private const BULK_BATCH_SIZE = 5000;

    /** Rows per bulk INSERT. Bump down if hitting max_allowed_packet. */
    private const INSERT_CHUNK = 500;

    /**
     * Post fields the `field` facet kind is allowed to read.
     *
     * Direct SQL means we can't trust user-supplied column names — this
     * allowlist is the safety boundary against injection via the facet
     * source config.
     */
    private const ALLOWED_POST_FIELDS = [
        'post_title', 'post_name', 'post_author', 'post_status',
        'post_type', 'post_date', 'post_modified', 'post_parent',
        'menu_order', 'comment_count',
    ];

    /** Per-chunk size for background reindex jobs. */
    public const BACKGROUND_CHUNK = 200;

    /** Cron hook the chunked reindex runs under. */
    public const BACKGROUND_HOOK = 'hof_background_reindex';

    /** wp_options key tracking background-reindex progress for /reindex/status. */
    public const BACKGROUND_STATE_OPTION = 'hof_background_reindex_state';

    public function register_hooks(): void {
        add_action( 'save_post',        [ $this, 'on_post_save' ], 20, 3 );
        add_action( 'deleted_post',     [ $this, 'on_post_delete' ], 10, 1 );
        add_action( 'set_object_terms', [ $this, 'on_object_terms_changed' ], 10, 4 );

        // Background reindex: each scheduled event handles one chunk and
        // re-schedules the next if work remains. Survives plugin upgrades.
        add_action( self::BACKGROUND_HOOK, [ $this, 'run_background_chunk' ], 10, 1 );
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
     * Routes to the bulk rebuild path — one query per facet per batch
     * instead of WP get_the_terms/get_post_meta loops. The incremental
     * save_post path (reindex_object) is unchanged.
     *
     * @param callable|null $progress Called as $progress(int $count) after each batch.
     * @return int Total objects indexed.
     */
    public function reindex_all( ?callable $progress = null ): int {
        return $this->bulk_reindex_all( $progress );
    }

    /**
     * Batched bulk rebuild — the fast path for full reindex.
     *
     * Per batch of post IDs, runs at most one SQL query per configured
     * facet (joined directly to wp_term_relationships / wp_postmeta /
     * wp_posts) and one bulk INSERT chunk. Avoids get_the_terms() /
     * get_post_meta() entirely, which is where the per-post path burns
     * its time on large catalogs.
     *
     * Trade-off vs. reindex_object(): we skip plugin filters that hook
     * into get_terms / get_post_meta. Acceptable for a bulk rebuild —
     * incremental updates (save_post, set_object_terms) still go through
     * reindex_object() and pick up those filters.
     *
     * @param callable|null $progress Called as $progress(int $count) after each batch.
     * @return int Total objects indexed.
     */
    public function bulk_reindex_all( ?callable $progress = null ): int {
        global $wpdb;
        $table = $wpdb->prefix . Activator::TABLE;

        $wpdb->query( "TRUNCATE TABLE {$table}" );

        $facets = $this->get_configured_facets();
        if ( empty( $facets ) ) {
            return 0;
        }

        // Group facets by kind so we can issue one query per (kind, source) per batch.
        $by_kind = [ 'taxonomy' => [], 'meta' => [], 'field' => [] ];
        foreach ( $facets as $facet ) {
            $name    = $facet['name']    ?? null;
            $source  = $facet['source']  ?? null;
            $kind    = $facet['kind']    ?? null;
            $display = $facet['display'] ?? null;
            if ( ! $name || ! $source || ! isset( $by_kind[ $kind ] ) ) {
                continue;
            }
            $by_kind[ $kind ][] = [ 'name' => $name, 'source' => $source, 'display' => $display ];
        }

        // Precompute term_id → depth maps, one per indexed taxonomy.
        // Saves a recursive get_term() walk per term per object.
        $depth_maps = [];
        foreach ( $by_kind['taxonomy'] as $f ) {
            if ( ! isset( $depth_maps[ $f['source'] ] ) ) {
                $depth_maps[ $f['source'] ] = $this->build_term_depth_map( $f['source'] );
            }
        }

        $types              = $this->indexed_post_types();
        $types_placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
        $count              = 0;
        $last_id            = 0;

        while ( true ) {
            // Keyset pagination — beats LIMIT/OFFSET at scale (no growing offset cost).
            $params   = array_merge( [ $last_id ], $types, [ self::BULK_BATCH_SIZE ] );
            $post_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts}
                 WHERE ID > %d
                 AND post_status = 'publish'
                 AND post_type IN ({$types_placeholders})
                 ORDER BY ID ASC
                 LIMIT %d",
                $params
            ) );

            if ( empty( $post_ids ) ) {
                break;
            }

            $post_ids = array_map( 'intval', $post_ids );
            $last_id  = end( $post_ids );

            $rows = [];
            foreach ( $by_kind['taxonomy'] as $f ) {
                $rows = array_merge( $rows, $this->bulk_rows_from_taxonomy(
                    $post_ids, $f['name'], $f['source'], $depth_maps[ $f['source'] ]
                ) );
            }
            foreach ( $by_kind['meta'] as $f ) {
                $rows = array_merge( $rows, $this->bulk_rows_from_meta(
                    $post_ids, $f['name'], $f['source'],
                    ( $f['display'] ?? '' ) === 'date_range'
                ) );
            }
            foreach ( $by_kind['field'] as $f ) {
                $rows = array_merge( $rows, $this->bulk_rows_from_field(
                    $post_ids, $f['name'], $f['source'],
                    ( $f['display'] ?? '' ) === 'date_range'
                ) );
            }

            if ( ! empty( $rows ) ) {
                $this->bulk_insert( $rows );
            }

            $count += count( $post_ids );
            if ( $progress ) {
                $progress( $count );
            }
        }

        return $count;
    }

    /**
     * Reindex a single object: clear its rows, then insert fresh ones.
     */
    public function reindex_object( int $object_id, string $object_type = 'post' ): void {
        $this->delete_object( $object_id );

        $rows = $this->gather_rows( $object_id, $object_type );

        // Visual DNA v3 — N synthetic rows per object, one per palette entry.
        // Each row carries one LAB triplet in lab_l/lab_a/lab_b plus its
        // weight (fraction of opaque pixels in that bucket) in facet_numeric.
        // Skipped silently when there's no thumbnail or extraction fails.
        if ( $object_type === 'post' ) {
            $rows = array_merge( $rows, $this->visual_dna_rows( $object_id ) );
        }

        if ( empty( $rows ) ) {
            return;
        }

        $this->bulk_insert( $rows );
    }

    /**
     * Build the synthetic _visual_dna_lab rows for an object's palette.
     * Returns [] when extraction fails / there's no featured image.
     *
     * @return array<int, array<string, mixed>>
     */
    private function visual_dna_rows( int $object_id ): array {
        static $extractor = null;
        if ( $extractor === null ) {
            $extractor = new \HookedOnFacets\VisualDna\ColorExtractor();
        }
        $palette = $extractor->extract_palette_from_post( $object_id );
        if ( $palette === null ) {
            return [];
        }
        $rows = [];
        foreach ( $palette as $entry ) {
            $rows[] = [
                'object_id'     => $object_id,
                'object_type'   => 'post',
                'facet_name'    => '_visual_dna_lab',
                'lab_l'         => $entry['L'],
                'lab_a'         => $entry['a'],
                'lab_b'         => $entry['b'],
                'facet_numeric' => $entry['weight'],
            ];
        }
        return $rows;
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

            $is_date = ( $facet['display'] ?? '' ) === 'date_range';

            $rows = array_merge( $rows, match ( $kind ) {
                'taxonomy' => $this->rows_from_taxonomy( $object_id, $object_type, $name, $source ),
                'meta'     => $this->rows_from_meta( $object_id, $object_type, $name, $source, $is_date ),
                'field'    => $this->rows_from_field( $object_id, $object_type, $name, $source, $is_date ),
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
    private function rows_from_meta( int $object_id, string $object_type, string $facet_name, string $meta_key, bool $is_date = false ): array {
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

            $numeric = $this->resolve_numeric( $string, $is_date );

            $rows[] = [
                'object_id'     => $object_id,
                'object_type'   => $object_type,
                'facet_name'    => $facet_name,
                'facet_source'  => $meta_key,
                'facet_value'   => substr( $string, 0, 191 ),
                'facet_display' => substr( $string, 0, 191 ),
                'facet_numeric' => $numeric,
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
    private function rows_from_field( int $object_id, string $object_type, string $facet_name, string $field, bool $is_date = false ): array {
        $post = get_post( $object_id );
        if ( ! $post ) {
            return [];
        }

        $value = $post->{$field} ?? null;
        if ( ! is_scalar( $value ) || $value === '' ) {
            return [];
        }

        $string  = (string) $value;
        $numeric = $this->resolve_numeric( $string, $is_date );

        return [ [
            'object_id'     => $object_id,
            'object_type'   => $object_type,
            'facet_name'    => $facet_name,
            'facet_source'  => $field,
            'facet_value'   => substr( $string, 0, 191 ),
            'facet_display' => substr( $string, 0, 191 ),
            'facet_numeric' => $numeric,
            'term_id'       => null,
            'parent_id'     => null,
            'depth'         => 0,
        ] ];
    }

    // ── Bulk row gatherers (used by bulk_reindex_all) ──────────────────────

    /**
     * @param int[] $post_ids
     * @param array<int, int> $depth_map term_id → depth, precomputed once per taxonomy.
     * @return array<int, array<string, mixed>>
     */
    private function bulk_rows_from_taxonomy( array $post_ids, string $facet_name, string $taxonomy, array $depth_map ): array {
        global $wpdb;
        $ids_placeholders = implode( ', ', array_fill( 0, count( $post_ids ), '%d' ) );

        $records = $wpdb->get_results( $wpdb->prepare(
            "SELECT tr.object_id, t.term_id, t.slug, t.name, tt.parent
             FROM {$wpdb->term_relationships} tr
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
             WHERE tt.taxonomy = %s
             AND tr.object_id IN ({$ids_placeholders})",
            array_merge( [ $taxonomy ], $post_ids )
        ), ARRAY_A );

        if ( empty( $records ) ) {
            return [];
        }

        $rows = [];
        foreach ( $records as $r ) {
            $term_id = (int) $r['term_id'];
            $rows[] = [
                'object_id'     => (int) $r['object_id'],
                'object_type'   => 'post',
                'facet_name'    => $facet_name,
                'facet_source'  => $taxonomy,
                'facet_value'   => (string) $r['slug'],
                'facet_display' => (string) $r['name'],
                'facet_numeric' => null,
                'term_id'       => $term_id,
                'parent_id'     => ( (int) $r['parent'] ) ?: null,
                'depth'         => $depth_map[ $term_id ] ?? 0,
            ];
        }
        return $rows;
    }

    /**
     * @param int[] $post_ids
     * @return array<int, array<string, mixed>>
     */
    private function bulk_rows_from_meta( array $post_ids, string $facet_name, string $meta_key, bool $is_date = false ): array {
        global $wpdb;
        $ids_placeholders = implode( ', ', array_fill( 0, count( $post_ids ), '%d' ) );

        $records = $wpdb->get_results( $wpdb->prepare(
            "SELECT post_id, meta_value
             FROM {$wpdb->postmeta}
             WHERE meta_key = %s
             AND post_id IN ({$ids_placeholders})",
            array_merge( [ $meta_key ], $post_ids )
        ), ARRAY_A );

        if ( empty( $records ) ) {
            return [];
        }

        $rows = [];
        foreach ( $records as $r ) {
            $value = (string) $r['meta_value'];
            if ( $value === '' ) {
                continue;
            }
            // Mirror rows_from_meta(): scalar-only. Serialized values get the
            // `a:`/`O:` prefix and are skipped — they need a custom adapter.
            if ( is_serialized( $value ) ) {
                continue;
            }
            $rows[] = [
                'object_id'     => (int) $r['post_id'],
                'object_type'   => 'post',
                'facet_name'    => $facet_name,
                'facet_source'  => $meta_key,
                'facet_value'   => substr( $value, 0, 191 ),
                'facet_display' => substr( $value, 0, 191 ),
                'facet_numeric' => $this->resolve_numeric( $value, $is_date ),
                'term_id'       => null,
                'parent_id'     => null,
                'depth'         => 0,
            ];
        }
        return $rows;
    }

    /**
     * @param int[] $post_ids
     * @return array<int, array<string, mixed>>
     */
    private function bulk_rows_from_field( array $post_ids, string $facet_name, string $field, bool $is_date = false ): array {
        // Direct SQL with a dynamic column name — allowlist is the safety boundary.
        if ( ! in_array( $field, self::ALLOWED_POST_FIELDS, true ) ) {
            return [];
        }

        global $wpdb;
        $ids_placeholders = implode( ', ', array_fill( 0, count( $post_ids ), '%d' ) );

        $records = $wpdb->get_results( $wpdb->prepare(
            "SELECT ID, {$field} AS value
             FROM {$wpdb->posts}
             WHERE ID IN ({$ids_placeholders})",
            $post_ids
        ), ARRAY_A );

        if ( empty( $records ) ) {
            return [];
        }

        $rows = [];
        foreach ( $records as $r ) {
            $value = (string) $r['value'];
            if ( $value === '' ) {
                continue;
            }
            $rows[] = [
                'object_id'     => (int) $r['ID'],
                'object_type'   => 'post',
                'facet_name'    => $facet_name,
                'facet_source'  => $field,
                'facet_value'   => substr( $value, 0, 191 ),
                'facet_display' => substr( $value, 0, 191 ),
                'facet_numeric' => $this->resolve_numeric( $value, $is_date ),
                'term_id'       => null,
                'parent_id'     => null,
                'depth'         => 0,
            ];
        }
        return $rows;
    }

    /**
     * Resolve a string value to a numeric for `facet_numeric`.
     *
     * Numeric strings convert directly. Date strings (when $is_date) convert
     * via strtotime() to a Unix epoch — `Date Range` facets store epoch
     * seconds so the existing range resolver path works unchanged. Unparseable
     * dates return null so a single bad row doesn't poison the index.
     */
    private function resolve_numeric( string $value, bool $is_date ): ?float {
        if ( $is_date ) {
            // Pure numeric values (existing epochs / unix timestamps) pass through.
            if ( is_numeric( $value ) ) {
                return (float) $value;
            }
            $ts = strtotime( $value );
            return $ts === false ? null : (float) $ts;
        }
        return is_numeric( $value ) ? (float) $value : null;
    }

    /**
     * Build a term_id → depth map for one taxonomy, in a single query.
     *
     * @return array<int, int>
     */
    private function build_term_depth_map( string $taxonomy ): array {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT tt.term_id, tt.parent
             FROM {$wpdb->term_taxonomy} tt
             WHERE tt.taxonomy = %s",
            $taxonomy
        ), ARRAY_A );

        if ( empty( $rows ) ) {
            return [];
        }

        $parents = [];
        foreach ( $rows as $r ) {
            $parents[ (int) $r['term_id'] ] = (int) $r['parent'];
        }

        $depths = [];
        foreach ( $parents as $term_id => $_parent ) {
            $depth   = 0;
            $current = $term_id;
            $guard   = 0;
            while ( ! empty( $parents[ $current ] ) && $guard < 20 ) {
                $current = $parents[ $current ];
                $depth++;
                $guard++;
            }
            $depths[ $term_id ] = $depth;
        }
        return $depths;
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

        // Fill in defaults so visual_dna_row's narrow shape coexists with the
        // full facet-row shape in the same INSERT.
        $defaults = [
            'object_type'   => 'post',
            'facet_source'  => '',
            'facet_value'   => '',
            'facet_display' => '',
            'facet_numeric' => null,
            'lab_l'         => null,
            'lab_a'         => null,
            'lab_b'         => null,
            'term_id'       => null,
            'parent_id'     => null,
            'depth'         => 0,
        ];

        foreach ( array_chunk( $rows, self::INSERT_CHUNK ) as $chunk ) {
            $value_clauses = [];
            $params        = [];

            foreach ( $chunk as $row ) {
                $row += $defaults;

                $value_clauses[] = sprintf(
                    '(%%d, %%s, %%s, %%s, %%s, %%s, %s, %s, %s, %s, %s, %s, %%d)',
                    $row['facet_numeric'] !== null ? '%f' : 'NULL',
                    $row['lab_l']         !== null ? '%f' : 'NULL',
                    $row['lab_a']         !== null ? '%f' : 'NULL',
                    $row['lab_b']         !== null ? '%f' : 'NULL',
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
                if ( $row['lab_l']         !== null ) { $params[] = $row['lab_l']; }
                if ( $row['lab_a']         !== null ) { $params[] = $row['lab_a']; }
                if ( $row['lab_b']         !== null ) { $params[] = $row['lab_b']; }
                if ( $row['term_id']       !== null ) { $params[] = $row['term_id']; }
                if ( $row['parent_id']     !== null ) { $params[] = $row['parent_id']; }
                $params[] = $row['depth'];
            }

            $sql = "INSERT INTO {$table}
                    (object_id, object_type, facet_name, facet_source, facet_value, facet_display, facet_numeric, lab_l, lab_a, lab_b, term_id, parent_id, depth)
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

    // ── Background reindex (wp_cron-driven) ─────────────────────────────────

    /**
     * Queue a full reindex to run asynchronously in chunks via wp_cron.
     *
     * Synchronous reindex is fine for small catalogs (<10k products), but
     * once you're north of that the admin button starts timing out PHP-FPM.
     * This path counts the total objects up front, wipes the index, then
     * schedules per-chunk events at -1s intervals so the first chunk fires
     * on the next wp_cron tick and subsequent chunks chain via
     * `run_background_chunk`'s self-rescheduling.
     *
     * Returns an array describing the queued job so the REST layer can
     * report it back to the admin polling UI.
     *
     * @return array{job_id: string, total: int, chunks: int, chunk_size: int, started_at: int}
     */
    public function queue_reindex_all( int $chunk_size = self::BACKGROUND_CHUNK ): array {
        global $wpdb;
        $chunk_size = max( 50, min( 2000, $chunk_size ) );
        $types      = $this->indexed_post_types();
        $types_in   = implode( ', ', array_fill( 0, count( $types ), '%s' ) );

        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ({$types_in})",
            $types
        ) );

        $chunks = $total > 0 ? (int) ceil( $total / $chunk_size ) : 0;
        $job_id = wp_generate_uuid4();

        // Reset the index — same contract as synchronous bulk_reindex_all.
        $table = $wpdb->prefix . Activator::TABLE;
        $wpdb->query( "TRUNCATE TABLE {$table}" );

        update_option( self::BACKGROUND_STATE_OPTION, [
            'job_id'     => $job_id,
            'total'      => $total,
            'chunks'     => $chunks,
            'chunk_size' => $chunk_size,
            'done_posts' => 0,
            'done_chunks'=> 0,
            'last_id'    => 0,
            'started_at' => time(),
            'finished_at'=> null,
            'running'    => $chunks > 0,
        ], false );

        if ( $chunks > 0 ) {
            // Fire the first chunk on the next cron tick. Subsequent chunks
            // chain themselves from run_background_chunk.
            wp_schedule_single_event( time() + 1, self::BACKGROUND_HOOK, [ $job_id ] );
        }

        return [
            'job_id'     => $job_id,
            'total'      => $total,
            'chunks'     => $chunks,
            'chunk_size' => $chunk_size,
            'started_at' => time(),
        ];
    }

    /**
     * Cron handler — process one chunk and re-schedule if work remains.
     *
     * Honors the job_id so a stale event from a previous job (e.g. cancelled
     * by a second queue call) doesn't overwrite the new state.
     */
    public function run_background_chunk( string $job_id ): void {
        $state = (array) get_option( self::BACKGROUND_STATE_OPTION, [] );
        if ( ( $state['job_id'] ?? '' ) !== $job_id ) {
            return; // Stale event from a superseded job.
        }
        if ( empty( $state['running'] ) ) {
            return;
        }

        global $wpdb;
        $chunk_size = (int) ( $state['chunk_size'] ?? self::BACKGROUND_CHUNK );
        $last_id    = (int) ( $state['last_id']    ?? 0 );
        $types      = $this->indexed_post_types();
        $types_in   = implode( ', ', array_fill( 0, count( $types ), '%s' ) );

        $params  = array_merge( $types, [ $last_id, $chunk_size ] );
        $post_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_status = 'publish' AND post_type IN ({$types_in}) AND ID > %d
             ORDER BY ID ASC
             LIMIT %d",
            $params
        ) );

        if ( empty( $post_ids ) ) {
            // No more work — mark finished.
            $state['running']     = false;
            $state['finished_at'] = time();
            update_option( self::BACKGROUND_STATE_OPTION, $state, false );
            return;
        }

        // Reuse the bulk pipeline so this chunk runs at the same speed as
        // the full bulk path — gather + insert one chunk's worth of facets.
        $post_ids = array_map( 'intval', $post_ids );
        $facets   = $this->get_configured_facets();

        $by_kind = [ 'taxonomy' => [], 'meta' => [], 'field' => [] ];
        foreach ( $facets as $f ) {
            $name    = $f['name']    ?? null;
            $source  = $f['source']  ?? null;
            $kind    = $f['kind']    ?? null;
            $display = $f['display'] ?? null;
            if ( ! $name || ! $source || ! isset( $by_kind[ $kind ] ) ) {
                continue;
            }
            $by_kind[ $kind ][] = [ 'name' => $name, 'source' => $source, 'display' => $display ];
        }

        $depth_maps = [];
        foreach ( $by_kind['taxonomy'] as $f ) {
            if ( ! isset( $depth_maps[ $f['source'] ] ) ) {
                $depth_maps[ $f['source'] ] = $this->build_term_depth_map( $f['source'] );
            }
        }

        $rows = [];
        foreach ( $by_kind['taxonomy'] as $f ) {
            $rows = array_merge( $rows, $this->bulk_rows_from_taxonomy(
                $post_ids, $f['name'], $f['source'], $depth_maps[ $f['source'] ]
            ) );
        }
        foreach ( $by_kind['meta'] as $f ) {
            $rows = array_merge( $rows, $this->bulk_rows_from_meta(
                $post_ids, $f['name'], $f['source'],
                ( $f['display'] ?? '' ) === 'date_range'
            ) );
        }
        foreach ( $by_kind['field'] as $f ) {
            $rows = array_merge( $rows, $this->bulk_rows_from_field(
                $post_ids, $f['name'], $f['source'],
                ( $f['display'] ?? '' ) === 'date_range'
            ) );
        }
        if ( ! empty( $rows ) ) {
            $this->bulk_insert( $rows );
        }

        // Visual DNA palette extraction for this chunk's posts.
        foreach ( $post_ids as $pid ) {
            $lab_rows = $this->visual_dna_rows( $pid );
            if ( ! empty( $lab_rows ) ) {
                $this->bulk_insert( $lab_rows );
            }
        }

        $state['done_posts']  = (int) $state['done_posts'] + count( $post_ids );
        $state['done_chunks'] = (int) $state['done_chunks'] + 1;
        $state['last_id']     = (int) end( $post_ids );

        $remaining = (int) $state['total'] - (int) $state['done_posts'];
        if ( $remaining <= 0 || count( $post_ids ) < $chunk_size ) {
            $state['running']     = false;
            $state['finished_at'] = time();
        } else {
            wp_schedule_single_event( time() + 1, self::BACKGROUND_HOOK, [ $job_id ] );
        }

        update_option( self::BACKGROUND_STATE_OPTION, $state, false );
    }

    /**
     * @return array{job_id: ?string, total: int, done_posts: int, chunks: int,
     *               done_chunks: int, started_at: ?int, finished_at: ?int,
     *               running: bool, percent: float}
     */
    public function background_state(): array {
        $raw   = (array) get_option( self::BACKGROUND_STATE_OPTION, [] );
        $total = (int) ( $raw['total'] ?? 0 );
        $done  = (int) ( $raw['done_posts'] ?? 0 );
        return [
            'job_id'      => isset( $raw['job_id'] )      ? (string) $raw['job_id'] : null,
            'total'       => $total,
            'done_posts'  => $done,
            'chunks'      => (int) ( $raw['chunks']      ?? 0 ),
            'done_chunks' => (int) ( $raw['done_chunks'] ?? 0 ),
            'started_at'  => isset( $raw['started_at'] )  ? (int) $raw['started_at']  : null,
            'finished_at' => isset( $raw['finished_at'] ) ? (int) $raw['finished_at'] : null,
            'running'     => ! empty( $raw['running'] ),
            'percent'     => $total > 0 ? round( ( $done / $total ) * 100, 1 ) : 0.0,
        ];
    }
}
