<?php
/**
 * Advanced Custom Fields (ACF) source integration.
 *
 * A suggestion provider, mirroring the WooCommerce integration: it inspects
 * the registered ACF field groups and proposes facet configs so an admin can
 * one-click add facets for their custom fields without knowing the underlying
 * meta keys or guessing the right display type.
 *
 * ACF stores each field's value in post meta under the field's `name`, so an
 * ACF facet is just a `meta`-kind facet pointed at that key — no new indexer
 * path is needed for the scalar field types. The value this integration adds
 * is discovery (which fields exist) and mapping (field type → facet display).
 *
 * Field type → facet display:
 *
 *   select (single) → dropdown
 *   select (multi),
 *   checkbox        → checkbox  (serialized array of scalars; the indexer's
 *                                normalize_meta_values() explodes it into buckets)
 *   radio,
 *   button_group    → radio
 *   true_false      → toggle  (true_value '1')
 *   number, range   → range
 *   text, textarea,
 *   email, url      → search
 *   taxonomy        → with "Save Terms" on, a taxonomy facet (ACF writes to
 *                     wp_term_relationships, so the existing taxonomy index
 *                     path serves it). With it off, the terms live only as
 *                     term IDs in meta → a meta facet with settings.resolve =
 *                     'term' (term ID → name at index time).
 *   relationship,
 *   post_object     → checkbox, with settings.resolve = 'post'. These store a
 *                     serialized array of post IDs; the indexer resolves the
 *                     IDs to post titles at index time (value = ID, display =
 *                     title), so the buckets read as post names.
 *   user            → checkbox, with settings.resolve = 'user'. Serialized
 *                     array of user IDs (scalar for a single user); resolved
 *                     to display names at index time.
 *   date_picker,
 *   date_time_picker → date_range. date_picker stores a compact `Ymd`,
 *                     date_time_picker `Y-m-d H:i:s`; the indexer normalizes
 *                     both to epoch seconds so the range resolver scales them.
 *
 * Deliberately NOT suggested (deferred):
 *   - time_picker — a time-of-day (`H:i:s`), not a calendar date.
 *   - Structural / presentational types — repeater, group, flexible_content,
 *     wysiwyg, image, file, message, tab, etc. — aren't meaningfully facetable.
 *
 * @package HookedOnFacets\Integrations
 */

declare(strict_types=1);

namespace HookedOnFacets\Integrations;

defined( 'ABSPATH' ) || exit;

final class Acf {

    public function is_active(): bool {
        return function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' );
    }

    /**
     * Suggest facet configs for the site's ACF fields.
     *
     * Existing facets keyed by `name` are skipped so the result is net-new
     * only — calling code can merge straight into the editor's facets array.
     * Each suggested field's meta key must already be in use on at least one
     * post, so admins don't see facets for fields nothing has filled in.
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
            // De-dupe against existing facets and against fields that appear in
            // more than one group (ACF clones / shared keys).
            if ( isset( $taken[ $cfg['name'] ] ) || isset( $seen[ $cfg['name'] ] ) ) {
                continue;
            }
            // Only suggest facets with real data. A taxonomy-kind facet (ACF
            // taxonomy field with Save Terms on) is backed by terms, not meta,
            // so it checks the taxonomy rather than a postmeta key.
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
     * Flatten every field across every registered field group.
     *
     * @return array<int, array<string, mixed>>
     */
    private function all_fields(): array {
        $fields = [];
        foreach ( (array) acf_get_field_groups() as $group ) {
            $key = is_array( $group ) ? ( $group['key'] ?? null ) : null;
            if ( ! $key ) {
                continue;
            }
            foreach ( (array) acf_get_fields( $key ) as $field ) {
                $fields[] = $field;
            }
        }
        return $fields;
    }

    /**
     * Map one ACF field definition to a facet config, or null to skip it.
     *
     * @param array<string, mixed> $field
     * @return array<string, mixed>|null
     */
    private function map_field( array $field ): ?array {
        $name = (string) ( $field['name'] ?? '' );
        $type = (string) ( $field['type'] ?? '' );
        if ( $name === '' || $type === '' ) {
            return null;
        }

        $slug = sanitize_key( $name );
        if ( $slug === '' ) {
            return null;
        }

        $label = (string) ( $field['label'] ?? '' );
        if ( $label === '' ) {
            $label = ucwords( str_replace( [ '_', '-' ], ' ', $name ) );
        }

        $cfg = [
            'name'     => $slug,
            'label'    => $label,
            'kind'     => 'meta',
            'source'   => $name,
            'display'  => 'checkbox',
            'settings' => [],
        ];

        switch ( $type ) {
            case 'select':
                // Single select → dropdown. Multi-select stores a serialized
                // array of the chosen scalar values, which the indexer's
                // serialized-array adapter explodes into buckets → checkbox.
                $cfg['display'] = empty( $field['multiple'] ) ? 'dropdown' : 'checkbox';
                return $cfg;

            case 'checkbox':
                // Always multi-value: a serialized array of chosen scalar
                // values, exploded by the indexer's adapter into buckets.
                $cfg['display'] = 'checkbox';
                return $cfg;

            case 'taxonomy':
                // With "Save Terms" on, ACF writes the selected terms to
                // wp_term_relationships, so a plain taxonomy-kind facet serves
                // them through the existing taxonomy index path. With it off,
                // the terms live only as term IDs in postmeta — a meta facet
                // resolved via the 'term' kind (term ID → name at index time).
                if ( empty( $field['save_terms'] ) ) {
                    $cfg['display']  = 'checkbox';
                    $cfg['settings'] = [ 'resolve' => 'term' ];
                    return $cfg;
                }
                $taxonomy = (string) ( $field['taxonomy'] ?? '' );
                if ( $taxonomy === '' ) {
                    return null;
                }
                $cfg['kind']    = 'taxonomy';
                $cfg['source']  = $taxonomy;
                $cfg['display'] = 'checkbox';
                return $cfg;

            case 'relationship':
            case 'post_object':
                // Serialized array of post IDs (a single post_object may store
                // a scalar ID). The indexer resolves IDs → post titles at index
                // time when settings.resolve = 'post'.
                $cfg['display']  = 'checkbox';
                $cfg['settings'] = [ 'resolve' => 'post' ];
                return $cfg;

            case 'user':
                // Single user → scalar user ID; multiple → serialized array of
                // user IDs. The indexer resolves IDs → display names at index
                // time when settings.resolve = 'user'.
                $cfg['display']  = 'checkbox';
                $cfg['settings'] = [ 'resolve' => 'user' ];
                return $cfg;

            case 'radio':
            case 'button_group':
                $cfg['display'] = 'radio';
                return $cfg;

            case 'true_false':
                $cfg['display']  = 'toggle';
                $cfg['settings'] = [
                    'true_value' => '1',
                    'on_label'   => $label,
                    'off_label'  => 'All',
                ];
                return $cfg;

            case 'number':
            case 'range':
                $cfg['display'] = 'range';
                return $cfg;

            case 'date_picker':
            case 'date_time_picker':
                // date_picker stores a compact Ymd; date_time_picker stores
                // Y-m-d H:i:s. The indexer normalizes both to epoch seconds
                // (resolve_numeric's date branch), so the date_range resolver
                // path scales them correctly. time_picker is a time-of-day,
                // not a calendar date, so it stays deferred.
                $cfg['display'] = 'date_range';
                return $cfg;

            case 'text':
            case 'textarea':
            case 'email':
            case 'url':
                $cfg['display'] = 'search';
                return $cfg;

            default:
                // Multi-value / serialized, date, and structural types — see
                // the class docblock for why each is deferred.
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
