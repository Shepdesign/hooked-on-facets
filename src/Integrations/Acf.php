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
 * Scope — scalar field types only. The indexer's meta path is scalar-only
 * (it skips serialized values; see Indexer::bulk_rows_from_meta), so we only
 * suggest field types that store a single scalar value and therefore index
 * correctly today:
 *
 *   select (single) → dropdown
 *   radio,
 *   button_group    → radio
 *   true_false      → toggle  (true_value '1')
 *   number, range   → range
 *   text, textarea,
 *   email, url      → search
 *
 * Deliberately NOT suggested (deferred):
 *   - Multi-value types — checkbox, multi-select, taxonomy, relationship,
 *     post_object, user, gallery — store serialized arrays the indexer skips.
 *     These need the indexer's serialized-array adapter first.
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
            if ( ! $this->meta_in_use( (string) $cfg['source'] ) ) {
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
                // ACF stores a serialized array when "multiple" is on — that's
                // the deferred multi-value case, so only single selects map.
                if ( ! empty( $field['multiple'] ) ) {
                    return null;
                }
                $cfg['display'] = 'dropdown';
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
}
