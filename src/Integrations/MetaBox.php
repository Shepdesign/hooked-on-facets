<?php
/**
 * Meta Box (metabox.io) source integration.
 *
 * A suggestion provider mirroring the ACF integration: it inspects the
 * registered Meta Box field definitions and proposes facet configs so an admin
 * can one-click add facets for their custom fields without knowing the
 * underlying meta keys or guessing the right display type.
 *
 * Meta Box stores each field's value in postmeta under the field's `id`, so a
 * Meta Box facet is just a `meta`-kind facet pointed at that key — the same
 * shape as ACF. Multi-value fields (multi-select, checkbox_list,
 * image_advanced) store one postmeta row per value, which the indexer reads
 * via get_post_meta(..., false) and explodes through normalize_meta_values().
 *
 * Field type → facet display:
 *   text, textarea, email, url      → search
 *   number, range, slider           → range
 *   select / select_advanced (one)  → dropdown
 *   select / select_advanced (many),
 *   checkbox_list                   → checkbox
 *   radio, button_group             → radio
 *   checkbox (single), switch       → toggle
 *   date                            → date_range (stored Y-m-d, or a unix
 *                                     timestamp when the field's timestamp
 *                                     option is on — both resolve correctly)
 *   datetime                        → date_range (stored Y-m-d H:i:s)
 *   taxonomy                        → taxonomy facet — Meta Box assigns the
 *                                     chosen terms to the post (writes to
 *                                     wp_term_relationships, like ACF
 *                                     Save-Terms-on), so source = the taxonomy
 *   post                            → checkbox + resolve='post' (post IDs in meta)
 *   user                            → checkbox + resolve='user' (user IDs in meta)
 *
 * Deferred:
 *   - taxonomy_advanced — stores the chosen term IDs as a single
 *     comma-separated string in meta; needs a comma-split adapter the indexer
 *     doesn't have yet.
 *   - time — a time-of-day, not a calendar date.
 *   - Structural / media types — group, wysiwyg, single_image, image_advanced,
 *     file, file_advanced, key_value, etc. — aren't meaningfully facetable.
 *
 * @package HookedOnFacets\Integrations
 */

declare(strict_types=1);

namespace HookedOnFacets\Integrations;

defined( 'ABSPATH' ) || exit;

final class MetaBox {

    public function is_active(): bool {
        return function_exists( 'rwmb_meta' ) && function_exists( 'rwmb_get_registry' );
    }

    /**
     * Suggest facet configs for the site's Meta Box fields.
     *
     * Existing facets keyed by `name` are skipped so the result is net-new
     * only. Each suggested field must already carry data (a taxonomy facet
     * checks its taxonomy; everything else checks its postmeta key) so admins
     * don't see facets for fields nothing has filled in.
     *
     * @param array<int, array<string, mixed>> $existing Currently configured facets.
     * @return array<int, array<string, mixed>>
     */
    public function suggest( array $existing = [] ): array {
        if ( ! $this->is_active() ) {
            return [];
        }

        $taken = array_fill_keys(
            array_filter( array_map(
                static fn( $f ) => is_array( $f ) ? (string) ( $f['name'] ?? '' ) : '',
                $existing
            ) ),
            true
        );

        $out  = [];
        $seen = [];
        foreach ( $this->all_fields() as $field ) {
            $cfg = $this->map_field( is_array( $field ) ? $field : [] );
            if ( $cfg === null ) {
                continue;
            }
            // De-dupe against existing facets and against a field that appears
            // in more than one meta box (shared keys).
            if ( isset( $taken[ $cfg['name'] ] ) || isset( $seen[ $cfg['name'] ] ) ) {
                continue;
            }
            $has_data = ( $cfg['kind'] === 'taxonomy' )
                ? $this->taxonomy_in_use( (string) $cfg['source'] )
                : $this->meta_in_use( (string) $cfg['source'] );
            if ( ! $has_data ) {
                continue;
            }
            $seen[ $cfg['name'] ] = true;
            $out[] = $cfg;
        }

        return $out;
    }

    /**
     * Flatten every field across every registered meta box.
     *
     * @return array<int, array<string, mixed>>
     */
    private function all_fields(): array {
        $registry = rwmb_get_registry( 'meta_box' );
        if ( ! is_object( $registry ) || ! is_callable( [ $registry, 'all' ] ) ) {
            return [];
        }

        $fields = [];
        foreach ( (array) $registry->all() as $meta_box ) {
            foreach ( $this->meta_box_fields( $meta_box ) as $field ) {
                if ( is_array( $field ) ) {
                    $fields[] = $field;
                }
            }
        }
        return $fields;
    }

    /**
     * Pull the field definitions out of one meta box object. Newer Meta Box
     * exposes normalized `->fields`; the raw config lives in `->meta_box['fields']`.
     *
     * @return array<int|string, mixed>
     */
    private function meta_box_fields( mixed $meta_box ): array {
        if ( ! is_object( $meta_box ) ) {
            return [];
        }
        if ( isset( $meta_box->meta_box['fields'] ) && is_array( $meta_box->meta_box['fields'] ) ) {
            return $meta_box->meta_box['fields'];
        }
        if ( isset( $meta_box->fields ) && is_array( $meta_box->fields ) ) {
            return $meta_box->fields;
        }
        return [];
    }

    /**
     * Map one Meta Box field definition to a facet config, or null to skip it.
     *
     * @param array<string, mixed> $field
     * @return array<string, mixed>|null
     */
    private function map_field( array $field ): ?array {
        // Meta Box keys the meta by the field's `id`; `name` is the label.
        $key  = (string) ( $field['id'] ?? '' );
        $type = (string) ( $field['type'] ?? '' );
        if ( $key === '' || $type === '' ) {
            return null;
        }

        $slug = sanitize_key( $key );
        if ( $slug === '' ) {
            return null;
        }

        $label = (string) ( $field['name'] ?? '' );
        if ( $label === '' ) {
            $label = ucwords( str_replace( [ '_', '-' ], ' ', $key ) );
        }

        $cfg = [
            'name'     => $slug,
            'label'    => $label,
            'kind'     => 'meta',
            'source'   => $key,
            'display'  => 'checkbox',
            'settings' => [],
        ];

        $multiple = ! empty( $field['multiple'] );

        switch ( $type ) {
            case 'text':
            case 'textarea':
            case 'email':
            case 'url':
                $cfg['display'] = 'search';
                return $cfg;

            case 'number':
            case 'range':
            case 'slider':
                $cfg['display'] = 'range';
                return $cfg;

            case 'select':
            case 'select_advanced':
                $cfg['display'] = $multiple ? 'checkbox' : 'dropdown';
                return $cfg;

            case 'checkbox_list':
                $cfg['display'] = 'checkbox';
                return $cfg;

            case 'radio':
            case 'button_group':
                $cfg['display'] = 'radio';
                return $cfg;

            case 'checkbox':
            case 'switch':
                $cfg['display']  = 'toggle';
                $cfg['settings'] = [
                    'true_value' => '1',
                    'on_label'   => $label,
                    'off_label'  => 'All',
                ];
                return $cfg;

            case 'date':
            case 'datetime':
                $cfg['display'] = 'date_range';
                return $cfg;

            case 'taxonomy':
                // Meta Box assigns the chosen terms to the post, so the
                // existing taxonomy index path serves it. A field may target
                // more than one taxonomy; the first is enough to bind a facet.
                $taxonomy = $field['taxonomy'] ?? '';
                if ( is_array( $taxonomy ) ) {
                    $taxonomy = (string) reset( $taxonomy );
                }
                $taxonomy = (string) $taxonomy;
                if ( $taxonomy === '' ) {
                    return null;
                }
                $cfg['kind']    = 'taxonomy';
                $cfg['source']  = $taxonomy;
                $cfg['display'] = 'checkbox';
                return $cfg;

            case 'post':
                $cfg['display']  = 'checkbox';
                $cfg['settings'] = [ 'resolve' => 'post' ];
                return $cfg;

            case 'user':
                $cfg['display']  = 'checkbox';
                $cfg['settings'] = [ 'resolve' => 'user' ];
                return $cfg;

            default:
                // taxonomy_advanced (comma-separated IDs), time, and structural
                // / media types — see the class docblock for why each is deferred.
                return null;
        }
    }

    private function meta_in_use( string $meta_key ): bool {
        global $wpdb;
        $hit = $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1",
            $meta_key
        ) );
        return $hit !== null;
    }

    private function taxonomy_in_use( string $taxonomy ): bool {
        if ( ! taxonomy_exists( $taxonomy ) ) {
            return false;
        }
        return (int) wp_count_terms( [ 'taxonomy' => $taxonomy, 'hide_empty' => true ] ) > 0;
    }
}
