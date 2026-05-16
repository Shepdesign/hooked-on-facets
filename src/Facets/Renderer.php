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
            default  => $this->render_checkbox( $facet, (array) $current_value, $counts ),
        };
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

        ob_start();
        ?>
        <div class="hof-facet hof-facet-swiper"
             data-hof-facet="<?php echo esc_attr( $name ); ?>"
             data-hof-display="swiper">
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
                </svg>
            <?php endif; ?>

            <?php // Hidden inputs carry the same OR-list URL shape as checkbox/swatch. ?>
            <?php foreach ( $values as $v ) :
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
