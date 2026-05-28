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
 *   taxonomy        → taxonomy facet, but only when the field's "Save Terms"
 *                     option is on (ACF then writes to wp_term_relationships,
 *                     so the existing taxonomy index path serves it). Save-
 *                     Terms-off fields store term IDs in meta only — deferred.
 *
 * Deliberately NOT suggested (deferred):
 *   - ID-array types — relationship, post_object, user (and Save-Terms-off
 *     taxonomy) — store a serialized array of IDs. The indexer's adapter would
 *     explode them into raw-ID buckets; useful display needs ID → name
 *     resolution first.
 *   - Date types — date_picker / date_time_picker store `Ymd`, which the
 *     range path reads as a raw integer rather than a timestamp; suggesting a
 *     date_range facet would mis-scale until the indexer normalizes ACF dates.
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
                // them through the existing taxonomy index path — no meta-ID
                // resolution needed. Without it, the terms live only as IDs in
                // meta; that's the deferred ID → name case, so skip.
                $taxonomy = (string) ( $field['taxonomy'] ?? '' );
                if ( $taxonomy === '' || empty( $field['save_terms'] ) ) {
                    return null;
                }
                $cfg['kind']    = 'taxonomy';
                $cfg['source']  = $taxonomy;
                $cfg['display'] = 'checkbox';
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
