<?php
/**
 * Pods (pods.io) source integration.
 *
 * A suggestion provider mirroring the ACF integration: it inspects the fields
 * defined on the site's post-type Pods and proposes facet configs.
 *
 * Storage caveat — Pods can store a field either in postmeta or in its own
 * tables (the per-Pod "table" storage, or wp_podsrel for relationships). This
 * provider only knows how to index postmeta, so every suggestion is gated by
 * the same meta_in_use() check the ACF provider uses: a field whose values
 * live in a Pods table (rather than postmeta) simply carries no matching
 * postmeta and is silently skipped. That keeps suggestions honest without this
 * code having to model every Pods storage backend.
 *
 * Field type → facet display:
 *   text, paragraph, email, website, phone, slug → search
 *   number, currency                            → range
 *   boolean                                     → toggle
 *   date, datetime                              → date_range
 *   pick (relationship), by pick_object:
 *     post_type → checkbox + resolve='post'
 *     user      → checkbox + resolve='user'
 *     taxonomy  → checkbox + resolve='term'
 *     custom-simple → checkbox (the stored value is the option key itself)
 *
 * Deferred:
 *   - time — a time-of-day, not a calendar date.
 *   - pick relationships to other object types (comment, role, capability,
 *     custom tables) and table/wp_podsrel-stored relationships.
 *   - file, wysiwyg, code, password, color and other structural / media types.
 *
 * @package HookedOnFacets\Integrations
 */

declare(strict_types=1);

namespace HookedOnFacets\Integrations;

defined( 'ABSPATH' ) || exit;

final class Pods {

    public function is_active(): bool {
        return function_exists( 'pods' ) && function_exists( 'pods_api' );
    }

    /**
     * Suggest facet configs for the site's Pods fields.
     *
     * Existing facets keyed by `name` are skipped so the result is net-new
     * only. Each suggested field's meta key must already be in use on at least
     * one post.
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
            if ( isset( $taken[ $cfg['name'] ] ) || isset( $seen[ $cfg['name'] ] ) ) {
                continue;
            }
            // Every Pods suggestion is a meta-kind facet (a 'term'-resolved
            // pick still reads its IDs from postmeta), so the data check is
            // always the postmeta key.
            if ( ! $this->meta_in_use( (string) $cfg['source'] ) ) {
                continue;
            }
            $seen[ $cfg['name'] ] = true;
            $out[] = $cfg;
        }

        return $out;
    }

    /**
     * Flatten every field across every post-type Pod.
     *
     * @return array<int, array<string, mixed>>
     */
    private function all_fields(): array {
        $api = pods_api();
        if ( ! is_object( $api ) || ! is_callable( [ $api, 'load_pods' ] ) ) {
            return [];
        }

        $fields = [];
        foreach ( (array) $api->load_pods( [ 'type' => [ 'post_type' ] ] ) as $pod ) {
            $pod_fields = is_array( $pod ) ? ( $pod['fields'] ?? [] ) : [];
            foreach ( (array) $pod_fields as $name => $field ) {
                if ( ! is_array( $field ) ) {
                    continue;
                }
                // Pods keys fields by name; fall back to the array key.
                if ( ! isset( $field['name'] ) && is_string( $name ) ) {
                    $field['name'] = $name;
                }
                $fields[] = $field;
            }
        }
        return $fields;
    }

    /**
     * Map one Pods field definition to a facet config, or null to skip it.
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
            case 'text':
            case 'paragraph':
            case 'email':
            case 'website':
            case 'phone':
            case 'slug':
                $cfg['display'] = 'search';
                return $cfg;

            case 'number':
            case 'currency':
                $cfg['display'] = 'range';
                return $cfg;

            case 'boolean':
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

            case 'pick':
                return $this->map_pick( $field, $cfg );

            default:
                return null;
        }
    }

    /**
     * Map a Pods relationship (`pick`) field by its related object type. The
     * pick target lives at the top level or under `options` depending on the
     * Pods version, so check both.
     *
     * @param array<string, mixed> $field
     * @param array<string, mixed> $cfg
     * @return array<string, mixed>|null
     */
    private function map_pick( array $field, array $cfg ): ?array {
        $options = isset( $field['options'] ) && is_array( $field['options'] ) ? $field['options'] : [];
        $object  = (string) ( $field['pick_object'] ?? ( $options['pick_object'] ?? '' ) );

        switch ( $object ) {
            case 'post_type':
            case 'post-type':
                $cfg['settings'] = [ 'resolve' => 'post' ];
                return $cfg;

            case 'user':
                $cfg['settings'] = [ 'resolve' => 'user' ];
                return $cfg;

            case 'taxonomy':
                $cfg['settings'] = [ 'resolve' => 'term' ];
                return $cfg;

            case '':
            case 'custom-simple':
                // A custom list stores the chosen option key(s) directly in
                // meta — a plain checkbox facet, no ID resolution.
                return $cfg;

            default:
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
