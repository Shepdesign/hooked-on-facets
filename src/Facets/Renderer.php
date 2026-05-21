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
            // Legacy 'venn' / 'upset' displays fall through to checkbox.
            // Both shipped briefly in Phase 2 but the matrix UX confused
            // users; the foundational fix (active filters bar) made the
            // checkbox list the simpler, more legible default.
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
                    // Initial glyph for tiles with no per-term color/image —
                    // gives the long tail of default tiles distinct identity.
                    $has_visual_meta = $img_url || $color !== '';
                    $initial         = $has_visual_meta
                        ? ''
                        : mb_strtoupper( mb_substr( (string) $bucket['display'], 0, 1 ) );
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
                            <span class="hof-facet-swatch-visual" aria-hidden="true">
                                <?php if ( $initial !== '' ) : ?>
                                    <span class="hof-facet-swatch-initial"><?php echo esc_html( $initial ); ?></span>
                                <?php endif; ?>
                            </span>
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
