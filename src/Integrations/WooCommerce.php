<?php
/**
 * WooCommerce first-class integration.
 *
 * Inspects the active WC store and suggests sensible facet configs:
 *   - product_cat       hierarchy
 *   - product_tag       checkbox
 *   - pa_color          swatch (if term_meta has swatch_color), else checkbox
 *   - pa_size           radio (single-select feels natural on size)
 *   - pa_* (others)     checkbox
 *   - _price            range
 *   - _stock_status     toggle (true_value: instock)
 *   - _featured         toggle (true_value: yes)
 *   - _sale_price       toggle (true_value: any non-empty value — handled at facet level)
 *
 * The detection runs against the live taxonomy / meta tables so it only
 * suggests facets the store actually has data for. Empty / unused
 * attributes are skipped so admins don't see a noisy list of zero-count
 * options.
 *
 * @package HookedOnFacets\Integrations
 */

declare(strict_types=1);

namespace HookedOnFacets\Integrations;

defined( 'ABSPATH' ) || exit;

final class WooCommerce {

    public function is_active(): bool {
        return function_exists( 'WC' ) || class_exists( \WooCommerce::class );
    }

    /**
     * Suggest a list of facet configs based on the active store.
     *
     * Existing facets keyed by `name` are skipped so the suggestion list
     * only contains net-new ones — calling code can merge directly into
     * the editor's `facets` array without duplicating.
     *
     * @param array<int, array<string, mixed>> $existing  Currently configured facets.
     * @return array<int, array<string, mixed>>
     */
    public function suggest( array $existing = [] ): array {
        if ( ! $this->is_active() ) {
            return [];
        }

        $taken    = array_fill_keys(
            array_filter( array_map( static fn( $f ) => is_array( $f ) ? (string) ( $f['name'] ?? '' ) : '', $existing ) ),
            true
        );
        $out = [];

        // Taxonomies — only suggest those that actually have terms.
        foreach ( $this->candidate_taxonomies() as $tax => $cfg ) {
            if ( isset( $taken[ $cfg['name'] ] ) ) continue;
            if ( ! taxonomy_exists( $tax ) ) continue;
            $term_count = (int) wp_count_terms( [ 'taxonomy' => $tax, 'hide_empty' => true ] );
            if ( $term_count === 0 ) continue;

            // Color attribute: prefer swatch when any term carries swatch_color meta.
            if ( $tax === 'pa_color' && $this->any_term_has_swatch_color( $tax ) ) {
                $cfg['display'] = 'swatch';
            }

            $out[] = $cfg;
        }

        // Meta facets — only suggest if at least one product has the meta.
        foreach ( $this->candidate_meta() as $meta_key => $cfg ) {
            if ( isset( $taken[ $cfg['name'] ] ) ) continue;
            if ( ! $this->meta_in_use( $meta_key ) ) continue;
            $out[] = $cfg;
        }

        return $out;
    }

    /**
     * The taxonomy → suggestion-template map. Keys are taxonomy slugs;
     * values are facet config dicts the merge step writes verbatim
     * (plus dynamic display picks per the swatch detection above).
     *
     * @return array<string, array<string, mixed>>
     */
    private function candidate_taxonomies(): array {
        $base = [
            'product_cat' => [
                'name'    => 'category',
                'label'   => 'Category',
                'kind'    => 'taxonomy',
                'source'  => 'product_cat',
                'display' => 'hierarchy',
                'settings' => [],
            ],
            'product_tag' => [
                'name'    => 'tags',
                'label'   => 'Tags',
                'kind'    => 'taxonomy',
                'source'  => 'product_tag',
                'display' => 'checkbox',
                'settings' => [],
            ],
            'pa_color' => [
                'name'    => 'color',
                'label'   => 'Color',
                'kind'    => 'taxonomy',
                'source'  => 'pa_color',
                'display' => 'checkbox', // upgraded to 'swatch' if term meta exists
                'settings' => [],
            ],
            'pa_size' => [
                'name'    => 'size',
                'label'   => 'Size',
                'kind'    => 'taxonomy',
                'source'  => 'pa_size',
                'display' => 'radio',
                'settings' => [],
            ],
            'pa_brand' => [
                'name'    => 'brand',
                'label'   => 'Brand',
                'kind'    => 'taxonomy',
                'source'  => 'pa_brand',
                'display' => 'dropdown',
                'settings' => [],
            ],
        ];

        // Auto-discover any other registered product attribute taxonomies (pa_*).
        $product_taxonomies = get_object_taxonomies( 'product', 'names' );
        foreach ( $product_taxonomies as $tax ) {
            if ( strpos( $tax, 'pa_' ) !== 0 ) continue;
            if ( isset( $base[ $tax ] ) ) continue;
            $label = ucwords( str_replace( '_', ' ', substr( $tax, 3 ) ) );
            $base[ $tax ] = [
                'name'    => sanitize_key( substr( $tax, 3 ) ),
                'label'   => $label,
                'kind'    => 'taxonomy',
                'source'  => $tax,
                'display' => 'checkbox',
                'settings' => [],
            ];
        }

        return $base;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function candidate_meta(): array {
        return [
            '_price' => [
                'name'    => 'price',
                'label'   => 'Price',
                'kind'    => 'meta',
                'source'  => '_price',
                'display' => 'range',
                'settings' => [],
            ],
            '_stock_status' => [
                'name'    => 'in_stock',
                'label'   => 'In stock only',
                'kind'    => 'meta',
                'source'  => '_stock_status',
                'display' => 'toggle',
                'settings' => [
                    'true_value' => 'instock',
                    'on_label'   => 'In stock only',
                    'off_label'  => 'All products',
                ],
            ],
            '_featured' => [
                'name'    => 'featured',
                'label'   => 'Featured',
                'kind'    => 'meta',
                'source'  => '_featured',
                'display' => 'toggle',
                'settings' => [
                    'true_value' => 'yes',
                    'on_label'   => 'Featured only',
                    'off_label'  => 'All products',
                ],
            ],
        ];
    }

    private function any_term_has_swatch_color( string $taxonomy ): bool {
        global $wpdb;
        $hit = $wpdb->get_var( $wpdb->prepare(
            "SELECT 1
             FROM {$wpdb->termmeta} tm
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id
             WHERE tt.taxonomy = %s
               AND tm.meta_key = 'swatch_color'
               AND tm.meta_value REGEXP '^#[0-9a-fA-F]{6}$'
             LIMIT 1",
            $taxonomy
        ) );
        return $hit !== null;
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
