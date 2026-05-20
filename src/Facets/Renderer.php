<?php
/**
 * Renderer — produces server-rendered facet markup.
 *
 * Same Renderer instance handles all surfaces (shortcode, block, future
 * builder integrations). Counts are cached per-request via a one-shot
 * Resolver::resolve() call — five facets on a page costs one resolve, not five.
 *
 * Markup contract (what the JS runtime relies on):
 *
 *   <div class="hof-facet hof-facet-{display}"
 *        data-hof-facet="{name}"
 *        data-hof-display="{display}">
 *     ...native form controls...
 *     <span class="hof-facet-count" data-hof-count="{value}">{n}</span>
 *   </div>
 *
 * @package HookedOnFacets
 */

declare(strict_types=1);

namespace HookedOnFacets\Facets;

use HookedOnFacets\Admin\SwatchTermFields;
use HookedOnFacets\Filter\Resolver;
use HookedOnFacets\Indexer;

defined( 'ABSPATH' ) || exit;

final class Renderer {

    /** Per-request cache of the resolver result. */
    private ?array $resolve_cache = null;

    public function __construct( private readonly Resolver $resolver ) {}

    /**
     * Render one facet by slug. Returns escaped HTML or '' if unknown.
     */
    public function render( string $name ): string {
        $facet = $this->find_facet( $name );
        if ( $facet === null ) {
            return '';
        }

        $state         = Resolver::parse_request_filters();
        $current_value = $state[ $name ] ?? null;
        $counts        = $this->counts_for( $name );
        $display       = $facet['display'] ?? 'checkbox';

        return match ( $display ) {
            'range'  => $this->render_range( $facet, $current_value, $counts ),
            'search' => $this->render_search( $facet, $current_value ),
            'swatch' => $this->render_swatch( $facet, (array) $current_value, $counts ),
            'swiper' => $this->render_swiper( $facet, (array) $current_value, $counts ),
            'venn'   => $this->render_venn( $facet, (array) $current_value, $counts ),
            'upset'  => $this->render_upset( $facet, (array) $current_value, $counts ),
            default  => $this->render_checkbox( $facet, (array) $current_value, $counts ),
        };
    }

    /**
     * Render the "active filters" summary bar — one chip per applied filter,
     * a Clear-all link, and a live "N of M products match" count.
     *
     * The single feedback layer that makes the matrix facets (Venn, UpSet)
     * comprehensible: even when the dot-and-line visual is hard to read, the
     * chip strip tells the user exactly which terms are filtering the result
     * and the count tells them how many products match.
     *
     * Returns '' when no filters are active (the bar hides itself rather
     * than showing an empty card).
     */
    public function render_active_filters(): string {
        $state  = Resolver::parse_request_filters();
        $facets = $this->configured_facets();
        $defs   = [];
        foreach ( $facets as $f ) {
            if ( isset( $f['name'] ) ) {
                $defs[ (string) $f['name'] ] = $f;
            }
        }

        // Build chip rows from the active filter state.
        $chips = [];
        foreach ( $state as $facet_name => $value ) {
            if ( ! isset( $defs[ $facet_name ] ) ) {
                continue;
            }
            $facet = $defs[ $facet_name ];
            $label = (string) ( $facet['label'] ?? $facet_name );

            // Range: one chip with min – max.
            if ( is_array( $value ) && ( isset( $value['min'] ) || isset( $value['max'] ) ) ) {
                $fmt  = static function ( $n ) {
                    // Trim trailing zeros only AFTER a decimal point, then
                    // the dot if nothing's left. "10.000" → "10", "10.50" → "10.5".
                    $s = (string) (float) $n;
                    return strpos( $s, '.' ) !== false
                        ? rtrim( rtrim( $s, '0' ), '.' )
                        : $s;
                };
                $min  = isset( $value['min'] ) ? $fmt( $value['min'] ) : '';
                $max  = isset( $value['max'] ) ? $fmt( $value['max'] ) : '';
                $body = $min !== '' && $max !== '' ? "{$min} – {$max}" : ( $min !== '' ? ">= {$min}" : "<= {$max}" );
                $chips[] = [
                    'facet'        => $facet_name,
                    'facet_label'  => $label,
                    'value'        => '',           // empty means "remove the whole facet"
                    'value_label'  => $body,
                    'kind'         => 'range',
                ];
                continue;
            }

            // Multi-value: one chip per value. Look up display names from
            // the index in one batched query per facet.
            $values = is_array( $value ) ? $value : [ $value ];
            $values = array_values( array_filter(
                array_map( static fn( $v ) => is_scalar( $v ) ? (string) $v : null, $values ),
                static fn( $v ) => $v !== null && $v !== ''
            ) );
            if ( empty( $values ) ) {
                continue;
            }

            $displays = $this->display_names_for( $facet_name, $values );
            foreach ( $values as $v ) {
                $chips[] = [
                    'facet'       => $facet_name,
                    'facet_label' => $label,
                    'value'       => $v,
                    'value_label' => $displays[ $v ] ?? $v,
                    'kind'        => 'value',
                ];
            }
        }

        // Always render the wrapper so the refresh swap can find it. Inner
        // content is empty when no filters are active.
        $matched_count = null;
        $total_count   = null;
        if ( ! empty( $chips ) ) {
            $ids = $this->resolver->resolve_ids( $state );
            if ( is_array( $ids ) ) {
                $matched_count = count( $ids );
            }
            // Total = unfiltered catalog count over the indexed post types.
            global $wpdb;
            $table = $wpdb->prefix . \HookedOnFacets\Activator::TABLE;
            $total_count = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT object_id) FROM {$table}" );
        }

        ob_start();
        ?>
        <div class="hof-active-filters"
             data-hof-active-filters
             <?php if ( empty( $chips ) ) echo 'hidden'; ?>>
            <?php if ( ! empty( $chips ) ) : ?>
                <p class="hof-active-filters-count">
                    <?php if ( $matched_count !== null && $total_count !== null ) : ?>
                        <strong><?php echo number_format( $matched_count ); ?></strong>
                        of <?php echo number_format( $total_count ); ?> products match.
                    <?php else : ?>
                        Active filters applied.
                    <?php endif; ?>
                </p>
                <div class="hof-active-filters-chips" role="list">
                    <span class="hof-active-filters-eyebrow">Filtering by</span>
                    <?php foreach ( $chips as $chip ) : ?>
                        <button type="button"
                                role="listitem"
                                class="hof-active-filters-chip"
                                data-hof-active-chip
                                data-hof-active-facet="<?php echo esc_attr( $chip['facet'] ); ?>"
                                data-hof-active-value="<?php echo esc_attr( $chip['value'] ); ?>"
                                aria-label="<?php echo esc_attr( sprintf( 'Remove %s filter: %s', $chip['facet_label'], $chip['value_label'] ) ); ?>">
                            <span class="hof-active-filters-chip-label">
                                <?php echo esc_html( $chip['facet_label'] ); ?>:
                                <strong><?php echo esc_html( $chip['value_label'] ); ?></strong>
                            </span>
                            <span class="hof-active-filters-chip-x" aria-hidden="true">×</span>
                        </button>
                    <?php endforeach; ?>
                    <button type="button"
                            class="hof-active-filters-clear"
                            data-hof-reset>
                        Clear all
                    </button>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Batched lookup: facet_value → facet_display for one facet.
     *
     * @param array<int, string> $values
     * @return array<string, string>
     */
    private function display_names_for( string $facet_name, array $values ): array {
        if ( empty( $values ) ) {
            return [];
        }
        global $wpdb;
        $table = $wpdb->prefix . \HookedOnFacets\Activator::TABLE;

        $placeholders = implode( ', ', array_fill( 0, count( $values ), '%s' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT facet_value, facet_display
             FROM {$table} USE INDEX (facet_lookup)
             WHERE facet_name = %s
             AND facet_value IN ({$placeholders})",
            array_merge( [ $facet_name ], $values )
        ), ARRAY_A );

        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[ (string) $r['facet_value'] ] = (string) $r['facet_display'];
        }
        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function configured_facets(): array {
        $raw = get_option( Indexer::OPTION_FACETS, [] );
        return is_array( $raw ) ? $raw : [];
    }

    // ── Per-display renderers ───────────────────────────────────────────────

    /**
     * @param array<string, mixed>            $facet
     * @param array<int, string>              $selected_values
     * @param array<string, mixed>            $counts
     */
    private function render_checkbox( array $facet, array $selected_values, array $counts ): string {
        $name        = $facet['name'];
        $label       = $facet['label'] ?: $name;
        $buckets     = ( $counts['type'] ?? '' ) === 'values' ? $counts['buckets'] : [];
        $selected_lookup = array_fill_keys( array_map( 'strval', $selected_values ), true );

        ob_start();
        ?>
        <div class="hof-facet hof-facet-checkbox"
             data-hof-facet="<?php echo esc_attr( $name ); ?>"
             data-hof-display="checkbox">
            <fieldset class="hof-facet-fieldset">
                <legend class="hof-facet-label"><?php echo esc_html( $label ); ?></legend>
                <?php if ( empty( $buckets ) ) : ?>
                    <p class="hof-facet-empty"><?php esc_html_e( 'No options available.', 'hooked-on-facets' ); ?></p>
                <?php else : ?>
                    <ul class="hof-facet-options">
                        <?php foreach ( $buckets as $bucket ) :
                            $value   = (string) $bucket['value'];
                            $checked = isset( $selected_lookup[ $value ] );
                        ?>
                            <li class="hof-facet-option">
                                <label>
                                    <input type="checkbox"
                                           name="hof[<?php echo esc_attr( $name ); ?>][]"
                                           value="<?php echo esc_attr( $value ); ?>"
                                           <?php checked( $checked ); ?>>
                                    <span class="hof-facet-name"><?php echo esc_html( $bucket['display'] ); ?></span>
                                    <span class="hof-facet-count"
                                          data-hof-count="<?php echo esc_attr( $value ); ?>"><?php echo (int) $bucket['count']; ?></span>
                                </label>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </fieldset>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * @param array<string, mixed>      $facet
     * @param mixed                     $current_value
     * @param array<string, mixed>      $counts
     */
    private function render_range( array $facet, $current_value, array $counts ): string {
        $name      = $facet['name'];
        $label     = $facet['label'] ?: $name;
        $bound_min = ( $counts['type'] ?? '' ) === 'range' ? $counts['min'] : null;
        $bound_max = ( $counts['type'] ?? '' ) === 'range' ? $counts['max'] : null;

        $value_min = is_array( $current_value ) && isset( $current_value['min'] ) ? (string) $current_value['min'] : '';
        $value_max = is_array( $current_value ) && isset( $current_value['max'] ) ? (string) $current_value['max'] : '';

        ob_start();
        ?>
        <div class="hof-facet hof-facet-range"
             data-hof-facet="<?php echo esc_attr( $name ); ?>"
             data-hof-display="range">
            <span class="hof-facet-label"><?php echo esc_html( $label ); ?></span>
            <div class="hof-facet-range-inputs">
                <input type="number"
                       data-hof-input="min"
                       name="hof[<?php echo esc_attr( $name ); ?>][min]"
                       value="<?php echo esc_attr( $value_min ); ?>"
                       <?php if ( $bound_min !== null ) printf( 'min="%s"', esc_attr( (string) $bound_min ) ); ?>
                       <?php if ( $bound_max !== null ) printf( 'max="%s"', esc_attr( (string) $bound_max ) ); ?>
                       placeholder="<?php echo $bound_min !== null ? esc_attr( (string) $bound_min ) : esc_attr__( 'Min', 'hooked-on-facets' ); ?>"
                       inputmode="decimal">
                <span class="hof-facet-range-sep">–</span>
                <input type="number"
                       data-hof-input="max"
                       name="hof[<?php echo esc_attr( $name ); ?>][max]"
                       value="<?php echo esc_attr( $value_max ); ?>"
                       <?php if ( $bound_min !== null ) printf( 'min="%s"', esc_attr( (string) $bound_min ) ); ?>
                       <?php if ( $bound_max !== null ) printf( 'max="%s"', esc_attr( (string) $bound_max ) ); ?>
                       placeholder="<?php echo $bound_max !== null ? esc_attr( (string) $bound_max ) : esc_attr__( 'Max', 'hooked-on-facets' ); ?>"
                       inputmode="decimal">
            </div>
            <?php if ( $bound_min !== null && $bound_max !== null ) : ?>
                <p class="hof-facet-range-bounds">
                    <?php printf(
                        /* translators: 1: low bound, 2: high bound */
                        esc_html__( 'Range: %1$s – %2$s', 'hooked-on-facets' ),
                        esc_html( (string) $bound_min ),
                        esc_html( (string) $bound_max )
                    ); ?>
                </p>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Fluid swatch tiles. Tile size is driven by bucket count via a CSS
     * variable: `--hof-swatch-weight: 0..1`. CSS interpolates between
     * `--hof-swatch-min` and `--hof-swatch-max`.
     *
     * Weight formula: `(sqrt(count) - sqrt(min)) / (sqrt(max) - sqrt(min))`.
     * sqrt dampens the long-tail problem where one popular term would
     * crush everything else if we used raw counts.
     *
     * Only taxonomy-sourced facets — for anything else we bail to the
     * checkbox renderer so the user still sees something usable.
     *
     * @param array<string, mixed>  $facet
     * @param array<int, string>    $selected_values
     * @param array<string, mixed>  $counts
     */
    private function render_swatch( array $facet, array $selected_values, array $counts ): string {
        if ( ( $facet['kind'] ?? '' ) !== 'taxonomy' ) {
            return $this->render_checkbox( $facet, $selected_values, $counts );
        }

        $name     = $facet['name'];
        $label    = $facet['label'] ?: $name;
        $taxonomy = (string) ( $facet['source'] ?? '' );
        $buckets  = ( $counts['type'] ?? '' ) === 'values' ? $counts['buckets'] : [];

        if ( $taxonomy === '' || empty( $buckets ) ) {
            ob_start();
            ?>
            <div class="hof-facet hof-facet-swatch"
                 data-hof-facet="<?php echo esc_attr( $name ); ?>"
                 data-hof-display="swatch">
                <span class="hof-facet-label"><?php echo esc_html( $label ); ?></span>
                <p class="hof-facet-empty"><?php esc_html_e( 'No options available.', 'hooked-on-facets' ); ?></p>
            </div>
            <?php
            return (string) ob_get_clean();
        }

        $selected_lookup = array_fill_keys( array_map( 'strval', $selected_values ), true );

        // Hydrate term metadata in two batched calls so we don't fan out
        // one query per bucket.
        $slugs = array_map( static fn( $b ) => (string) $b['value'], $buckets );
        $terms = get_terms( [
            'taxonomy'   => $taxonomy,
            'slug'       => $slugs,
            'hide_empty' => false,
        ] );
        $by_slug   = [];
        $term_ids  = [];
        if ( ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term ) {
                $by_slug[ $term->slug ] = $term;
                $term_ids[]             = $term->term_id;
            }
        }
        if ( ! empty( $term_ids ) ) {
            update_termmeta_cache( $term_ids );
        }

        // Weight bounds from sqrt(count) so the long tail doesn't dominate.
        $counts_list = array_map( static fn( $b ) => max( 0, (int) $b['count'] ), $buckets );
        $sqrt_min    = sqrt( max( 0, min( $counts_list ) ) );
        $sqrt_max    = sqrt( max( $counts_list ) );
        $sqrt_range  = $sqrt_max - $sqrt_min;

        ob_start();
        ?>
        <div class="hof-facet hof-facet-swatch"
             data-hof-facet="<?php echo esc_attr( $name ); ?>"
             data-hof-display="swatch">
            <span class="hof-facet-label"><?php echo esc_html( $label ); ?></span>
            <ul class="hof-facet-swatches">
                <?php foreach ( $buckets as $bucket ) :
                    $value    = (string) $bucket['value'];
                    $count    = (int) $bucket['count'];
                    $checked  = isset( $selected_lookup[ $value ] );
                    $weight   = $sqrt_range > 0
                        ? ( sqrt( $count ) - $sqrt_min ) / $sqrt_range
                        : 1.0;
                    $term     = $by_slug[ $value ] ?? null;
                    $image_id = $term ? (int) get_term_meta( $term->term_id, SwatchTermFields::META_IMAGE, true ) : 0;
                    $color    = $term ? (string) get_term_meta( $term->term_id, SwatchTermFields::META_COLOR, true ) : '';
                    $img_url  = $image_id > 0 ? wp_get_attachment_image_url( $image_id, 'thumbnail' ) : '';
                    $style    = sprintf( '--hof-swatch-weight: %.4f;', $weight );
                    if ( $img_url ) {
                        $style .= sprintf( "--hof-swatch-image: url('%s');", esc_url_raw( $img_url ) );
                    }
                    if ( $color !== '' ) {
                        $style .= sprintf( '--hof-swatch-color: %s;', $color );
                    }
                ?>
                    <li class="hof-facet-swatch-item">
                        <label class="hof-facet-swatch-tile"
                               style="<?php echo esc_attr( $style ); ?>"
                               data-hof-swatch-value="<?php echo esc_attr( $value ); ?>"
                               <?php echo $checked ? 'data-hof-selected="1"' : ''; ?>>
                            <input type="checkbox"
                                   class="hof-facet-swatch-input screen-reader-text"
                                   name="hof[<?php echo esc_attr( $name ); ?>][]"
                                   value="<?php echo esc_attr( $value ); ?>"
                                   <?php checked( $checked ); ?>>
                            <span class="hof-facet-swatch-visual" aria-hidden="true"></span>
                            <span class="hof-facet-swatch-text">
                                <span class="hof-facet-swatch-name"><?php echo esc_html( $bucket['display'] ); ?></span>
                                <span class="hof-facet-count"
                                      data-hof-count="<?php echo esc_attr( $value ); ?>"><?php echo (int) $count; ?></span>
                            </span>
                        </label>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Swipe-deck facet. Cards stack; user swipes right to include a value,
     * left to skip. Multi-select OR semantics — backed by the same
     * `hof[<name>][]=value` URL shape as checkbox/swatch, so the Resolver
     * doesn't care which renderer produced the form.
     *
     * Taxonomy sources reuse SwatchTermFields meta keys (image / color) so
     * one term-meta configuration powers both swatch and swiper.
     *
     * @param array<string, mixed>  $facet
     * @param array<int, string>    $selected_values
     * @param array<string, mixed>  $counts
     */
    private function render_swiper( array $facet, array $selected_values, array $counts ): string {
        $name    = $facet['name'];
        $label   = $facet['label'] ?: $name;
        $buckets = ( $counts['type'] ?? '' ) === 'values' ? $counts['buckets'] : [];

        // Blueprint sandbox settings — read with defaults that match the
        // admin's DEFAULTS so an unconfigured facet looks the same as the
        // original Phase 2 ship. sanitize_facets allowlists these values, so
        // anything we receive here is already trusted.
        $settings   = is_array( $facet['settings'] ?? null ) ? $facet['settings'] : [];
        $variant    = (string) ( $settings['variant']   ?? 'Card' );
        $card_size  = (string) ( $settings['cardSize']  ?? 'Medium' );
        $deck_depth = (int)    ( $settings['deckDepth'] ?? 3 );
        $animation  = (string) ( $settings['animation'] ?? 'Spring' );

        ob_start();
        ?>
        <div class="hof-facet hof-facet-swiper"
             data-hof-facet="<?php echo esc_attr( $name ); ?>"
             data-hof-display="swiper"
             data-hof-swiper-variant="<?php echo esc_attr( $variant ); ?>"
             data-hof-swiper-size="<?php echo esc_attr( $card_size ); ?>"
             data-hof-swiper-depth="<?php echo (int) $deck_depth; ?>"
             data-hof-swiper-anim="<?php echo esc_attr( $animation ); ?>">
            <span class="hof-facet-label"><?php echo esc_html( $label ); ?></span>
            <?php if ( empty( $buckets ) ) : ?>
                <p class="hof-facet-empty"><?php esc_html_e( 'No options available.', 'hooked-on-facets' ); ?></p>
            <?php else : ?>
                <?php
                $selected_lookup = array_fill_keys( array_map( 'strval', $selected_values ), true );

                // Hydrate term meta for taxonomy sources (same pattern as render_swatch).
                $by_slug = [];
                if ( ( $facet['kind'] ?? '' ) === 'taxonomy' ) {
                    $taxonomy = (string) ( $facet['source'] ?? '' );
                    if ( $taxonomy !== '' ) {
                        $slugs = array_map( static fn( $b ) => (string) $b['value'], $buckets );
                        $terms = get_terms( [
                            'taxonomy'   => $taxonomy,
                            'slug'       => $slugs,
                            'hide_empty' => false,
                        ] );
                        $ids = [];
                        if ( ! is_wp_error( $terms ) ) {
                            foreach ( $terms as $term ) {
                                $by_slug[ $term->slug ] = $term;
                                $ids[]                  = $term->term_id;
                            }
                        }
                        if ( ! empty( $ids ) ) {
                            update_termmeta_cache( $ids );
                        }
                    }
                }
                ?>
                <div class="hof-swiper-deck"
                     role="group"
                     aria-label="<?php echo esc_attr( sprintf(
                         /* translators: %s: facet label */
                         __( 'Swipe through %s', 'hooked-on-facets' ),
                         $label
                     ) ); ?>">
                    <?php foreach ( $buckets as $bucket ) :
                        $value     = (string) $bucket['value'];
                        $count     = (int) $bucket['count'];
                        $included  = isset( $selected_lookup[ $value ] );
                        $term      = $by_slug[ $value ] ?? null;
                        $image_id  = $term ? (int) get_term_meta( $term->term_id, SwatchTermFields::META_IMAGE, true ) : 0;
                        $color     = $term ? (string) get_term_meta( $term->term_id, SwatchTermFields::META_COLOR, true ) : '';
                        $img_url   = $image_id > 0 ? wp_get_attachment_image_url( $image_id, 'medium' ) : '';
                        $style     = '';
                        if ( $img_url ) {
                            $style .= sprintf( "--hof-swiper-image: url('%s');", esc_url_raw( $img_url ) );
                        }
                        if ( $color !== '' ) {
                            $style .= sprintf( '--hof-swiper-color: %s;', $color );
                        }
                    ?>
                        <div class="hof-swiper-card"
                             data-hof-swiper-card
                             data-hof-swiper-value="<?php echo esc_attr( $value ); ?>"
                             <?php if ( $included ) echo 'data-hof-swiped="right" hidden'; ?>
                             <?php if ( $style !== '' ) printf( 'style="%s"', esc_attr( $style ) ); ?>>
                            <input type="checkbox"
                                   class="hof-swiper-input screen-reader-text"
                                   name="hof[<?php echo esc_attr( $name ); ?>][]"
                                   value="<?php echo esc_attr( $value ); ?>"
                                   <?php checked( $included ); ?>>
                            <span class="hof-swiper-card-visual" aria-hidden="true"></span>
                            <div class="hof-swiper-card-text">
                                <span class="hof-swiper-card-name"><?php echo esc_html( $bucket['display'] ); ?></span>
                                <span class="hof-facet-count"
                                      data-hof-count="<?php echo esc_attr( $value ); ?>"><?php echo (int) $count; ?></span>
                            </div>
                            <span class="hof-swiper-stamp hof-swiper-stamp-right" aria-hidden="true">
                                <?php esc_html_e( 'Include', 'hooked-on-facets' ); ?>
                            </span>
                            <span class="hof-swiper-stamp hof-swiper-stamp-left" aria-hidden="true">
                                <?php esc_html_e( 'Skip', 'hooked-on-facets' ); ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                    <p class="hof-swiper-done" data-hof-swiper-done hidden>
                        <span class="hof-swiper-done-text">
                            <?php esc_html_e( 'No more cards.', 'hooked-on-facets' ); ?>
                        </span>
                        <button type="button" class="hof-swiper-btn hof-swiper-reset" data-hof-swiper-reset>
                            <?php esc_html_e( 'Restart', 'hooked-on-facets' ); ?>
                        </button>
                    </p>
                </div>
                <div class="hof-swiper-controls">
                    <button type="button"
                            class="hof-swiper-btn hof-swiper-skip"
                            data-hof-swiper-action="skip"
                            aria-label="<?php esc_attr_e( 'Skip', 'hooked-on-facets' ); ?>">
                        <span aria-hidden="true">←</span>
                    </button>
                    <button type="button"
                            class="hof-swiper-btn hof-swiper-include"
                            data-hof-swiper-action="include"
                            aria-label="<?php esc_attr_e( 'Include', 'hooked-on-facets' ); ?>">
                        <span aria-hidden="true">→</span>
                    </button>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Venn-matrix facet. Auto-picks the top 2 or 3 terms by count, queries
     * pairwise / triple intersections via Resolver::count_intersection (3
     * or 4 extra queries on top of resolve), then renders an SVG with real
     * region counts. Click a circle to toggle that term as an OR filter —
     * URL shape stays `hof[<name>][]=value` so the Resolver doesn't care.
     *
     * Falls back to the checkbox renderer when fewer than 2 terms are
     * available or when the source isn't a taxonomy.
     *
     * @param array<string, mixed>  $facet
     * @param array<int, string>    $selected_values
     * @param array<string, mixed>  $counts
     */
    private function render_venn( array $facet, array $selected_values, array $counts ): string {
        if ( ( $facet['kind'] ?? '' ) !== 'taxonomy' ) {
            return $this->render_checkbox( $facet, $selected_values, $counts );
        }

        $name    = $facet['name'];
        $label   = $facet['label'] ?: $name;
        $buckets = ( $counts['type'] ?? '' ) === 'values' ? $counts['buckets'] : [];

        if ( count( $buckets ) < 2 ) {
            return $this->render_checkbox( $facet, $selected_values, $counts );
        }

        // counts come pre-sorted DESC; take top 3 (or 2 if that's all we have).
        $top    = array_slice( $buckets, 0, 3 );
        $n      = count( $top );
        $values = array_map( static fn( $b ) => (string) $b['value'], $top );
        $selected_lookup = array_fill_keys( array_map( 'strval', $selected_values ), true );

        // Pairwise / triple intersection counts. Derive the visible region
        // counts via inclusion-exclusion so we only run one query per
        // unique intersection rather than one per region.
        if ( $n === 2 ) {
            $ab = $this->resolver->count_intersection( $name, [ $values[0], $values[1] ] );
            $regions = [
                'a_only' => max( 0, (int) $top[0]['count'] - $ab ),
                'b_only' => max( 0, (int) $top[1]['count'] - $ab ),
                'ab'     => $ab,
            ];
        } else {
            $ab  = $this->resolver->count_intersection( $name, [ $values[0], $values[1] ] );
            $ac  = $this->resolver->count_intersection( $name, [ $values[0], $values[2] ] );
            $bc  = $this->resolver->count_intersection( $name, [ $values[1], $values[2] ] );
            $abc = $this->resolver->count_intersection( $name, [ $values[0], $values[1], $values[2] ] );
            $regions = [
                'a_only'  => max( 0, (int) $top[0]['count'] - $ab - $ac + $abc ),
                'b_only'  => max( 0, (int) $top[1]['count'] - $ab - $bc + $abc ),
                'c_only'  => max( 0, (int) $top[2]['count'] - $ac - $bc + $abc ),
                'ab_only' => max( 0, $ab - $abc ),
                'ac_only' => max( 0, $ac - $abc ),
                'bc_only' => max( 0, $bc - $abc ),
                'abc'     => $abc,
            ];
        }

        // Terms beyond the top 3 still need to be reachable — surface as a
        // collapsible checkbox list below the Venn so the long tail isn't
        // invisible to users.
        $overflow = array_slice( $buckets, 3 );

        ob_start();
        ?>
        <div class="hof-facet hof-facet-venn hof-facet-venn-<?php echo (int) $n; ?>"
             data-hof-facet="<?php echo esc_attr( $name ); ?>"
             data-hof-display="venn">
            <span class="hof-facet-label"><?php echo esc_html( $label ); ?></span>

            <?php if ( $n === 2 ) : ?>
                <svg class="hof-venn-svg" viewBox="0 0 240 160" xmlns="http://www.w3.org/2000/svg"
                     role="group" aria-label="<?php echo esc_attr( $label ); ?>">
                    <text class="hof-venn-term"           x="55"  y="22" text-anchor="middle"><?php echo esc_html( $top[0]['display'] ); ?></text>
                    <text class="hof-venn-term"           x="185" y="22" text-anchor="middle"><?php echo esc_html( $top[1]['display'] ); ?></text>
                    <circle class="hof-venn-circle" data-hof-venn-value="<?php echo esc_attr( $values[0] ); ?>"
                            <?php echo isset( $selected_lookup[ $values[0] ] ) ? 'data-hof-selected="1"' : ''; ?>
                            cx="80"  cy="90" r="55" />
                    <circle class="hof-venn-circle" data-hof-venn-value="<?php echo esc_attr( $values[1] ); ?>"
                            <?php echo isset( $selected_lookup[ $values[1] ] ) ? 'data-hof-selected="1"' : ''; ?>
                            cx="160" cy="90" r="55" />
                    <text class="hof-venn-count"          x="40"  y="95" text-anchor="middle"><?php echo (int) $regions['a_only']; ?></text>
                    <text class="hof-venn-count"          x="200" y="95" text-anchor="middle"><?php echo (int) $regions['b_only']; ?></text>
                    <text class="hof-venn-count hof-venn-count-overlap" x="120" y="95" text-anchor="middle"><?php echo (int) $regions['ab']; ?></text>

                    <?php // Invisible click target over the overlap; bundles both terms into the selection. ?>
                    <rect class="hof-venn-region"
                          data-hof-venn-values="<?php echo esc_attr( $values[0] . ',' . $values[1] ); ?>"
                          x="105" y="80" width="30" height="28"
                          aria-label="Include both <?php echo esc_attr( $top[0]['display'] . ' and ' . $top[1]['display'] ); ?>" />
                </svg>
            <?php else : ?>
                <svg class="hof-venn-svg" viewBox="0 0 240 220" xmlns="http://www.w3.org/2000/svg"
                     role="group" aria-label="<?php echo esc_attr( $label ); ?>">
                    <text class="hof-venn-term" x="65"  y="22"  text-anchor="middle"><?php echo esc_html( $top[0]['display'] ); ?></text>
                    <text class="hof-venn-term" x="175" y="22"  text-anchor="middle"><?php echo esc_html( $top[1]['display'] ); ?></text>
                    <text class="hof-venn-term" x="120" y="212" text-anchor="middle"><?php echo esc_html( $top[2]['display'] ); ?></text>
                    <circle class="hof-venn-circle" data-hof-venn-value="<?php echo esc_attr( $values[0] ); ?>"
                            <?php echo isset( $selected_lookup[ $values[0] ] ) ? 'data-hof-selected="1"' : ''; ?>
                            cx="90"  cy="95"  r="60" />
                    <circle class="hof-venn-circle" data-hof-venn-value="<?php echo esc_attr( $values[1] ); ?>"
                            <?php echo isset( $selected_lookup[ $values[1] ] ) ? 'data-hof-selected="1"' : ''; ?>
                            cx="150" cy="95"  r="60" />
                    <circle class="hof-venn-circle" data-hof-venn-value="<?php echo esc_attr( $values[2] ); ?>"
                            <?php echo isset( $selected_lookup[ $values[2] ] ) ? 'data-hof-selected="1"' : ''; ?>
                            cx="120" cy="147" r="60" />
                    <text class="hof-venn-count"          x="55"  y="80"  text-anchor="middle"><?php echo (int) $regions['a_only']; ?></text>
                    <text class="hof-venn-count"          x="185" y="80"  text-anchor="middle"><?php echo (int) $regions['b_only']; ?></text>
                    <text class="hof-venn-count"          x="120" y="180" text-anchor="middle"><?php echo (int) $regions['c_only']; ?></text>
                    <text class="hof-venn-count hof-venn-count-overlap" x="120" y="80"  text-anchor="middle"><?php echo (int) $regions['ab_only']; ?></text>
                    <text class="hof-venn-count hof-venn-count-overlap" x="88"  y="140" text-anchor="middle"><?php echo (int) $regions['ac_only']; ?></text>
                    <text class="hof-venn-count hof-venn-count-overlap" x="152" y="140" text-anchor="middle"><?php echo (int) $regions['bc_only']; ?></text>
                    <text class="hof-venn-count hof-venn-count-center"  x="120" y="120" text-anchor="middle"><?php echo (int) $regions['abc']; ?></text>

                    <?php // Invisible click targets over each overlap region. ?>
                    <rect class="hof-venn-region"
                          data-hof-venn-values="<?php echo esc_attr( $values[0] . ',' . $values[1] ); ?>"
                          x="105" y="66" width="30" height="22" />
                    <rect class="hof-venn-region"
                          data-hof-venn-values="<?php echo esc_attr( $values[0] . ',' . $values[2] ); ?>"
                          x="73"  y="126" width="30" height="22" />
                    <rect class="hof-venn-region"
                          data-hof-venn-values="<?php echo esc_attr( $values[1] . ',' . $values[2] ); ?>"
                          x="137" y="126" width="30" height="22" />
                    <rect class="hof-venn-region"
                          data-hof-venn-values="<?php echo esc_attr( implode( ',', $values ) ); ?>"
                          x="105" y="106" width="30" height="22" />
                </svg>
            <?php endif; ?>

            <p class="hof-venn-tip">
                <span>Click a circle to include that <?php echo esc_html( strtolower( $label ) ); ?>.</span>
                <?php if ( $n === 3 ) : ?>
                    <span>Click an overlap to include all categories in that region.</span>
                <?php else : ?>
                    <span>Click the overlap to include both at once.</span>
                <?php endif; ?>
                <span class="hof-venn-tip-meta">Numbers show how many products fall in each region.</span>
            </p>

            <?php if ( ! empty( $overflow ) ) : ?>
                <details class="hof-venn-more">
                    <summary class="hof-venn-more-toggle">
                        + <?php echo (int) count( $overflow ); ?> more <?php echo esc_html( count( $overflow ) === 1 ? 'category' : 'categories' ); ?>
                    </summary>
                    <ul class="hof-venn-more-list">
                        <?php foreach ( $overflow as $bucket ) :
                            $v       = (string) $bucket['value'];
                            $checked = isset( $selected_lookup[ $v ] ); ?>
                            <li>
                                <label>
                                    <input type="checkbox"
                                           name="hof[<?php echo esc_attr( $name ); ?>][]"
                                           value="<?php echo esc_attr( $v ); ?>"
                                           <?php checked( $checked ); ?>>
                                    <span><?php echo esc_html( $bucket['display'] ); ?></span>
                                    <span class="hof-facet-count">(<?php echo (int) $bucket['count']; ?>)</span>
                                </label>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>

            <?php // Hidden inputs carry the OR-list URL shape used by checkbox/swatch. ?>
            <?php foreach ( $values as $v ) :
                if ( isset( $selected_lookup[ $v ] ) ) : ?>
                    <input type="hidden" name="hof[<?php echo esc_attr( $name ); ?>][]" value="<?php echo esc_attr( $v ); ?>">
                <?php endif;
            endforeach; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * UpSet matrix — scales past the 3-set Venn ceiling. Renders the top N
     * terms as rows and every non-empty term-combination as a column with a
     * bar chart of intersection counts above and a dot grid below.
     *
     * Falls back to checkbox when the source isn't a taxonomy or fewer than
     * 2 buckets are available (degenerate).
     *
     * @param array<string, mixed>  $facet
     * @param array<int, string>    $selected_values
     * @param array<string, mixed>  $counts
     */
    private function render_upset( array $facet, array $selected_values, array $counts ): string {
        if ( ( $facet['kind'] ?? '' ) !== 'taxonomy' ) {
            return $this->render_checkbox( $facet, $selected_values, $counts );
        }

        $name    = $facet['name'];
        $label   = $facet['label'] ?: $name;
        $buckets = ( $counts['type'] ?? '' ) === 'values' ? $counts['buckets'] : [];

        if ( count( $buckets ) < 2 ) {
            return $this->render_checkbox( $facet, $selected_values, $counts );
        }

        // Top-N rows. 5 is dense-but-readable in a sidebar; bigger taxonomies
        // overflow to the +N more list below, same affordance as Venn.
        $row_count = 5;
        $rows      = array_slice( $buckets, 0, $row_count );
        $row_count = count( $rows ); // in case there were fewer.
        $row_values = array_map( static fn( $b ) => (string) $b['value'], $rows );
        $row_labels = array_map( static fn( $b ) => (string) $b['display'], $rows );
        $row_total  = array_map( static fn( $b ) => (int) $b['count'], $rows );

        $patterns = $this->resolver->membership_patterns( $name, $row_values, 12 );
        if ( empty( $patterns ) ) {
            // No rows in the index for these terms at all — degenerate.
            return $this->render_checkbox( $facet, $selected_values, $counts );
        }

        $max_count       = max( array_map( static fn( $p ) => $p['count'], $patterns ) );
        $selected_lookup = array_fill_keys( array_map( 'strval', $selected_values ), true );

        // SVG geometry (viewBox units; CSS scales the actual width).
        $row_h      = 22;
        $col_w      = 28;
        $bar_h      = 70;
        $left_pad   = 80;            // room for row labels.
        $top_pad    = 16;            // room above the tallest bar for the count label.
        $grid_y     = $top_pad + $bar_h + 12;
        $col_count  = count( $patterns );
        $svg_w      = $left_pad + $col_count * $col_w + 8;
        $svg_h      = $grid_y + $row_count * $row_h + 12;

        $overflow = array_slice( $buckets, $row_count );

        ob_start();
        ?>
        <div class="hof-facet hof-facet-upset"
             data-hof-facet="<?php echo esc_attr( $name ); ?>"
             data-hof-display="upset">
            <span class="hof-facet-label"><?php echo esc_html( $label ); ?></span>

            <div class="hof-upset-scroll">
                <svg class="hof-upset-svg"
                     viewBox="0 0 <?php echo (int) $svg_w; ?> <?php echo (int) $svg_h; ?>"
                     xmlns="http://www.w3.org/2000/svg"
                     role="img"
                     aria-label="<?php echo esc_attr( sprintf( 'Intersection matrix for %s', $label ) ); ?>">

                    <?php // ── Row labels ─────────────────────────────────────── ?>
                    <?php foreach ( $row_labels as $i => $rl ) :
                        $y        = $grid_y + $i * $row_h + $row_h / 2 + 4;
                        $selected = isset( $selected_lookup[ $row_values[ $i ] ] ); ?>
                        <text class="hof-upset-rowlabel <?php echo $selected ? 'is-selected' : ''; ?>"
                              data-hof-upset-row="<?php echo esc_attr( $row_values[ $i ] ); ?>"
                              x="<?php echo (int) ( $left_pad - 6 ); ?>"
                              y="<?php echo (int) $y; ?>"
                              text-anchor="end">
                            <?php echo esc_html( $rl ); ?>
                        </text>
                        <text class="hof-upset-rowcount"
                              x="<?php echo (int) ( $left_pad - 6 ); ?>"
                              y="<?php echo (int) ( $y + 9 ); ?>"
                              text-anchor="end">
                            <?php echo (int) $row_total[ $i ]; ?>
                        </text>
                    <?php endforeach; ?>

                    <?php // ── Columns: bar + dot grid ──────────────────────────── ?>
                    <?php foreach ( $patterns as $col_idx => $p ) :
                        $col_x      = $left_pad + $col_idx * $col_w + $col_w / 2;
                        $bar_height = $max_count > 0 ? round( $bar_h * ( $p['count'] / $max_count ) ) : 0;
                        $bar_y      = $top_pad + $bar_h - $bar_height;
                        $set        = array_fill_keys( $p['terms'], true );

                        // Comma-separated list of terms in this column (drives the click handler).
                        $col_values = implode( ',', $p['terms'] );

                        // A column is "active" if every term it represents is currently selected.
                        $col_active = ! empty( $p['terms'] );
                        foreach ( $p['terms'] as $t ) {
                            if ( ! isset( $selected_lookup[ $t ] ) ) { $col_active = false; break; }
                        }
                        ?>
                        <g class="hof-upset-col <?php echo $col_active ? 'is-active' : ''; ?>"
                           data-hof-upset-values="<?php echo esc_attr( $col_values ); ?>">

                            <?php // Full-height click target — slightly wider than the dots / bar. ?>
                            <rect class="hof-upset-col-hit"
                                  x="<?php echo (int) ( $col_x - $col_w / 2 + 1 ); ?>"
                                  y="<?php echo (int) $top_pad; ?>"
                                  width="<?php echo (int) ( $col_w - 2 ); ?>"
                                  height="<?php echo (int) ( $svg_h - $top_pad - 6 ); ?>"
                                  rx="3" />

                            <?php // Bar ?>
                            <rect class="hof-upset-bar"
                                  x="<?php echo (int) ( $col_x - 8 ); ?>"
                                  y="<?php echo (int) $bar_y; ?>"
                                  width="16"
                                  height="<?php echo (int) max( 2, $bar_height ); ?>"
                                  rx="2" />
                            <text class="hof-upset-bar-label"
                                  x="<?php echo (int) $col_x; ?>"
                                  y="<?php echo (int) ( $bar_y - 3 ); ?>"
                                  text-anchor="middle">
                                <?php echo (int) $p['count']; ?>
                            </text>

                            <?php // Connector line between the topmost and bottommost filled dot. ?>
                            <?php
                            $filled_indices = [];
                            foreach ( $row_values as $i => $rv ) {
                                if ( isset( $set[ $rv ] ) ) { $filled_indices[] = $i; }
                            }
                            if ( count( $filled_indices ) >= 2 ) :
                                $first = $filled_indices[0];
                                $last  = $filled_indices[ count( $filled_indices ) - 1 ]; ?>
                                <line class="hof-upset-link"
                                      x1="<?php echo (int) $col_x; ?>"
                                      y1="<?php echo (int) ( $grid_y + $first * $row_h + $row_h / 2 ); ?>"
                                      x2="<?php echo (int) $col_x; ?>"
                                      y2="<?php echo (int) ( $grid_y + $last  * $row_h + $row_h / 2 ); ?>" />
                            <?php endif; ?>

                            <?php // Dots — filled if the row's term is in this column's pattern. ?>
                            <?php foreach ( $row_values as $i => $rv ) :
                                $is_on = isset( $set[ $rv ] );
                                $cy    = $grid_y + $i * $row_h + $row_h / 2; ?>
                                <circle class="hof-upset-dot <?php echo $is_on ? 'is-on' : 'is-off'; ?>"
                                        cx="<?php echo (int) $col_x; ?>"
                                        cy="<?php echo (int) $cy; ?>"
                                        r="4.5" />
                            <?php endforeach; ?>
                        </g>
                    <?php endforeach; ?>
                </svg>
            </div>

            <p class="hof-upset-tip">
                <span>Click a column to include all categories in that combination.</span>
                <span>Click a row label to include just that category.</span>
                <span class="hof-upset-tip-meta">Bar height = intersection count.</span>
            </p>

            <?php if ( ! empty( $overflow ) ) : ?>
                <details class="hof-venn-more">
                    <summary class="hof-venn-more-toggle">
                        + <?php echo (int) count( $overflow ); ?> more <?php echo esc_html( count( $overflow ) === 1 ? 'category' : 'categories' ); ?>
                    </summary>
                    <ul class="hof-venn-more-list">
                        <?php foreach ( $overflow as $bucket ) :
                            $v       = (string) $bucket['value'];
                            $checked = isset( $selected_lookup[ $v ] ); ?>
                            <li>
                                <label>
                                    <input type="checkbox"
                                           name="hof[<?php echo esc_attr( $name ); ?>][]"
                                           value="<?php echo esc_attr( $v ); ?>"
                                           <?php checked( $checked ); ?>>
                                    <span><?php echo esc_html( $bucket['display'] ); ?></span>
                                    <span class="hof-facet-count">(<?php echo (int) $bucket['count']; ?>)</span>
                                </label>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>

            <?php // Hidden inputs carry the OR-list URL shape. ?>
            <?php foreach ( $row_values as $v ) :
                if ( isset( $selected_lookup[ $v ] ) ) : ?>
                    <input type="hidden" name="hof[<?php echo esc_attr( $name ); ?>][]" value="<?php echo esc_attr( $v ); ?>">
                <?php endif;
            endforeach; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private function render_search( array $facet, $current_value ): string {
        $name  = $facet['name'];
        $label = $facet['label'] ?: $name;
        $value = is_scalar( $current_value ) ? (string) $current_value : '';

        ob_start();
        ?>
        <div class="hof-facet hof-facet-search"
             data-hof-facet="<?php echo esc_attr( $name ); ?>"
             data-hof-display="search">
            <label class="hof-facet-label">
                <span class="hof-facet-label-text"><?php echo esc_html( $label ); ?></span>
                <input type="search"
                       name="hof[<?php echo esc_attr( $name ); ?>]"
                       value="<?php echo esc_attr( $value ); ?>"
                       placeholder="<?php esc_attr_e( 'Search…', 'hooked-on-facets' ); ?>"
                       autocomplete="off">
            </label>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    // ── Internals ───────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>|null
     */
    private function find_facet( string $name ): ?array {
        $facets = (array) get_option( Indexer::OPTION_FACETS, [] );
        foreach ( $facets as $facet ) {
            if ( is_array( $facet ) && ( $facet['name'] ?? '' ) === $name ) {
                return $facet;
            }
        }
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function counts_for( string $name ): array {
        if ( $this->resolve_cache === null ) {
            $state               = Resolver::parse_request_filters();
            $this->resolve_cache = $this->resolver->resolve( $state );
        }
        return $this->resolve_cache['counts'][ $name ] ?? [];
    }
}
