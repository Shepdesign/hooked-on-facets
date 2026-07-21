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
use HookedOnFacets\Routing\FilterState;
use HookedOnFacets\Routing\PrettySurface;
use HookedOnFacets\Routing\PrettyUrls;

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
        $display       = (string) ( $facet['display'] ?? 'checkbox' );

        /**
         * Add-on facet renderers (HOF Pro's signature facets register here).
         *
         * @param array<string, callable(array, mixed, array): string> $renderers
         *        Map of display slug => renderer( $facet, $current_value, $counts ).
         */
        $external = apply_filters( 'hof_facet_renderers', [] );
        if ( isset( $external[ $display ] ) && is_callable( $external[ $display ] ) ) {
            return (string) call_user_func( $external[ $display ], $facet, $current_value, $counts );
        }

        return match ( $display ) {
            'range'       => $this->render_range( $facet, $current_value, $counts ),
            'date_range'  => $this->render_date_range( $facet, $current_value, $counts ),
            'search'      => $this->render_search( $facet, $current_value ),
            'swatch'      => $this->render_swatch( $facet, (array) $current_value, $counts ),
            'radio'       => $this->render_radio( $facet, (array) $current_value, $counts ),
            'dropdown'    => $this->render_dropdown( $facet, (array) $current_value, $counts ),
            'toggle'      => $this->render_toggle( $facet, (array) $current_value ),
            'hierarchy'   => $this->render_hierarchy( $facet, (array) $current_value, $counts ),
            'pagination'  => $this->render_pagination( $facet ),
            // Retired displays — fall through to checkbox so stored configs
            // pointing at them don't crash the page:
            //   - 'venn' / 'upset' (matrix UX confused users; the active
            //     filters bar made the checkbox list the better default)
            //   - 'two_d_slider' (shelved during the NL search pivot, never
            //     completed; removed in v0.8)
            'checkbox', 'venn', 'upset', 'two_d_slider'
                => $this->render_checkbox( $facet, (array) $current_value, $counts ),
            // Any other display with no registered renderer — e.g. a Pro
            // facet while the add-on is inactive — renders nothing rather
            // than a broken control. The stored config is preserved.
            default => '',
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
                                </label>
                                <?php $link = $this->pretty_link( $facet, $value ); ?>
                                <?php if ( $link !== '' ) : ?>
                                    <a class="hof-facet-link hof-facet-name" href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $bucket['display'] ); ?></a>
                                <?php else : ?>
                                    <span class="hof-facet-name"><?php echo esc_html( $bucket['display'] ); ?></span>
                                <?php endif; ?>
                                <span class="hof-facet-count"
                                      data-hof-count="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( number_format_i18n( (int) $bucket['count'] ) ); ?></span>
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
     * Radio — single-select variant of checkbox. Same data shape (single-
     * element array on the wire) but enforces one-of-N at the input layer.
     *
     * @param array<string, mixed> $facet
     * @param array<int, string>   $selected_values
     * @param array<string, mixed> $counts
     */
    private function render_radio( array $facet, array $selected_values, array $counts ): string {
        $name            = $facet['name'];
        $label           = $facet['label'] ?: $name;
        $buckets         = ( $counts['type'] ?? '' ) === 'values' ? $counts['buckets'] : [];
        $selected        = isset( $selected_values[0] ) ? (string) $selected_values[0] : '';

        ob_start();
        ?>
        <div class="hof-facet hof-facet-radio"
             data-hof-facet="<?php echo esc_attr( $name ); ?>"
             data-hof-display="radio">
            <fieldset class="hof-facet-fieldset">
                <legend class="hof-facet-label"><?php echo esc_html( $label ); ?></legend>
                <?php if ( empty( $buckets ) ) : ?>
                    <p class="hof-facet-empty"><?php esc_html_e( 'No options available.', 'hooked-on-facets' ); ?></p>
                <?php else : ?>
                    <ul class="hof-facet-options">
                        <li class="hof-facet-option">
                            <label>
                                <input type="radio"
                                       name="hof[<?php echo esc_attr( $name ); ?>]"
                                       value=""
                                       <?php checked( $selected === '' ); ?>>
                                <span class="hof-facet-name"><?php esc_html_e( 'Any', 'hooked-on-facets' ); ?></span>
                            </label>
                        </li>
                        <?php foreach ( $buckets as $bucket ) :
                            $value     = (string) $bucket['value'];
                            $is_active = $selected === $value;
                        ?>
                            <li class="hof-facet-option">
                                <label>
                                    <input type="radio"
                                           name="hof[<?php echo esc_attr( $name ); ?>]"
                                           value="<?php echo esc_attr( $value ); ?>"
                                           <?php checked( $is_active ); ?>>
                                </label>
                                <?php $link = $this->pretty_link( $facet, $value ); ?>
                                <?php if ( $link !== '' ) : ?>
                                    <a class="hof-facet-link hof-facet-name" href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $bucket['display'] ); ?></a>
                                <?php else : ?>
                                    <span class="hof-facet-name"><?php echo esc_html( $bucket['display'] ); ?></span>
                                <?php endif; ?>
                                <span class="hof-facet-count"
                                      data-hof-count="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( number_format_i18n( (int) $bucket['count'] ) ); ?></span>
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
     * Dropdown — single-select via native <select>. Same wire shape as radio.
     *
     * @param array<string, mixed> $facet
     * @param array<int, string>   $selected_values
     * @param array<string, mixed> $counts
     */
    private function render_dropdown( array $facet, array $selected_values, array $counts ): string {
        $name     = $facet['name'];
        $label    = $facet['label'] ?: $name;
        $buckets  = ( $counts['type'] ?? '' ) === 'values' ? $counts['buckets'] : [];
        $selected = isset( $selected_values[0] ) ? (string) $selected_values[0] : '';

        ob_start();
        ?>
        <div class="hof-facet hof-facet-dropdown"
             data-hof-facet="<?php echo esc_attr( $name ); ?>"
             data-hof-display="dropdown">
            <label class="hof-facet-label">
                <span class="hof-facet-label-text"><?php echo esc_html( $label ); ?></span>
                <select class="hof-facet-select"
                        name="hof[<?php echo esc_attr( $name ); ?>]"
                        data-hof-select>
                    <option value=""><?php esc_html_e( 'Any', 'hooked-on-facets' ); ?></option>
                    <?php foreach ( $buckets as $bucket ) :
                        $value = (string) $bucket['value'];
                    ?>
                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $selected === $value ); ?>>
                            <?php echo esc_html( $bucket['display'] ); ?>
                            <?php echo ' (' . esc_html( number_format_i18n( (int) $bucket['count'] ) ) . ')'; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </label>
            <?php
            // Rendered visible on purpose: no-JS users need working links per
            // the design contract, so the <select> can't be the only way to
            // filter. Task 9's boot JS hides this list once it owns
            // interaction (progressive enhancement — JS off means these are
            // the real navigation). Placed outside the <label> since <ul>
            // isn't valid label content.
            $seo_links = [];
            foreach ( $buckets as $bucket ) {
                $link = $this->pretty_link( $facet, (string) $bucket['value'] );
                if ( $link !== '' ) {
                    $seo_links[] = '<li><a class="hof-facet-link" href="' . esc_url( $link ) . '">' . esc_html( $bucket['display'] ) . '</a></li>';
                }
            }
            if ( $seo_links !== [] ) {
                echo '<ul class="hof-facet-seo-links">' . implode( '', $seo_links ) . '</ul>';
            }
            ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Toggle — a boolean switch. When on, the facet value is [true_value];
     * when off, the facet is cleared. The index needs to carry the chosen
     * true_value as facet_value for the products that match — typically "1"
     * for boolean meta, but configurable so any string value works.
     *
     * @param array<string, mixed> $facet
     * @param array<int, string>   $selected_values
     */
    private function render_toggle( array $facet, array $selected_values ): string {
        $name       = $facet['name'];
        $label      = $facet['label'] ?: $name;
        $settings   = (array) ( $facet['settings'] ?? [] );
        $true_value = (string) ( $settings['true_value'] ?? '1' );
        $on_label   = (string) ( $settings['on_label']   ?? $label );
        $off_label  = (string) ( $settings['off_label']  ?? __( 'Off', 'hooked-on-facets' ) );
        $is_on      = in_array( $true_value, array_map( 'strval', $selected_values ), true );

        ob_start();
        ?>
        <div class="hof-facet hof-facet-toggle"
             data-hof-facet="<?php echo esc_attr( $name ); ?>"
             data-hof-display="toggle"
             data-hof-true-value="<?php echo esc_attr( $true_value ); ?>">
            <label class="hof-toggle-label">
                <input type="checkbox"
                       class="hof-toggle-input"
                       name="hof[<?php echo esc_attr( $name ); ?>]"
                       value="<?php echo esc_attr( $true_value ); ?>"
                       data-hof-toggle
                       <?php checked( $is_on ); ?>>
                <span class="hof-toggle-track" aria-hidden="true">
                    <span class="hof-toggle-thumb"></span>
                </span>
                <span class="hof-toggle-text">
                    <span class="hof-toggle-text-main"><?php echo esc_html( $label ); ?></span>
                    <?php if ( $on_label !== $label || $off_label !== '' ) : ?>
                        <span class="hof-toggle-text-state"><?php echo esc_html( $is_on ? $on_label : $off_label ); ?></span>
                    <?php endif; ?>
                </span>
            </label>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Hierarchy — taxonomy parent/child view. Top-level terms render as
     * collapsible <details> sections; child terms render as a nested checkbox
     * list inside. Each row is a real filter — selecting a parent filters on
     * just the parent's slug, not all of its children. Children are queryable
     * independently.
     *
     * Falls back to render_checkbox for non-taxonomy sources (since meta /
     * field sources don't carry a parent/child relationship).
     *
     * @param array<string, mixed> $facet
     * @param array<int, string>   $selected_values
     * @param array<string, mixed> $counts
     */
    private function render_hierarchy( array $facet, array $selected_values, array $counts ): string {
        if ( ( $facet['kind'] ?? '' ) !== 'taxonomy' ) {
            return $this->render_checkbox( $facet, $selected_values, $counts );
        }

        $name     = $facet['name'];
        $label    = $facet['label'] ?: $name;
        $taxonomy = (string) ( $facet['source'] ?? '' );
        $buckets  = ( $counts['type'] ?? '' ) === 'values' ? $counts['buckets'] : [];
        $selected_lookup = array_fill_keys( array_map( 'strval', $selected_values ), true );

        // Build a slug → bucket map, then a parent → children adjacency.
        $by_slug = [];
        foreach ( $buckets as $b ) {
            $by_slug[ (string) $b['value'] ] = $b;
        }

        $parent_of = [];
        $children  = [];
        foreach ( array_keys( $by_slug ) as $slug ) {
            $term = get_term_by( 'slug', $slug, $taxonomy );
            if ( ! $term instanceof \WP_Term ) {
                $parent_of[ $slug ] = '';
                continue;
            }
            if ( $term->parent ) {
                $parent_term = get_term( $term->parent, $taxonomy );
                $parent_of[ $slug ] = ( $parent_term instanceof \WP_Term ) ? $parent_term->slug : '';
            } else {
                $parent_of[ $slug ] = '';
            }
        }
        foreach ( $parent_of as $slug => $parent_slug ) {
            if ( $parent_slug !== '' && isset( $by_slug[ $parent_slug ] ) ) {
                $children[ $parent_slug ][] = $slug;
            }
        }

        $roots = [];
        foreach ( $parent_of as $slug => $parent_slug ) {
            if ( $parent_slug === '' || ! isset( $by_slug[ $parent_slug ] ) ) {
                $roots[] = $slug;
            }
        }

        $render_row = function ( string $slug, int $depth ) use ( &$render_row, $by_slug, $children, $selected_lookup, $name, $facet ): string {
            $b       = $by_slug[ $slug ];
            $value   = (string) $b['value'];
            $display = (string) $b['display'];
            $count   = (int) $b['count'];
            $checked = isset( $selected_lookup[ $value ] );
            $kids    = $children[ $slug ] ?? [];

            ob_start();
            if ( ! empty( $kids ) ) : ?>
                <li class="hof-hierarchy-row hof-hierarchy-has-children" data-hof-depth="<?php echo esc_attr( (string) $depth ); ?>">
                    <details<?php echo $checked ? ' open' : ''; ?>>
                        <summary>
                            <label class="hof-hierarchy-label">
                                <input type="checkbox"
                                       name="hof[<?php echo esc_attr( $name ); ?>][]"
                                       value="<?php echo esc_attr( $value ); ?>"
                                       <?php checked( $checked ); ?>>
                            </label>
                            <?php $link = $this->pretty_link( $facet, $value ); ?>
                            <?php if ( $link !== '' ) : ?>
                                <a class="hof-facet-link hof-facet-name" href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $display ); ?></a>
                            <?php else : ?>
                                <span class="hof-facet-name"><?php echo esc_html( $display ); ?></span>
                            <?php endif; ?>
                            <span class="hof-facet-count" data-hof-count="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
                        </summary>
                        <ul class="hof-hierarchy-children">
                            <?php foreach ( $kids as $child_slug ) {
                                echo $render_row( (string) $child_slug, $depth + 1 );
                            } ?>
                        </ul>
                    </details>
                </li>
            <?php else : ?>
                <li class="hof-hierarchy-row" data-hof-depth="<?php echo esc_attr( (string) $depth ); ?>">
                    <label class="hof-hierarchy-label">
                        <input type="checkbox"
                               name="hof[<?php echo esc_attr( $name ); ?>][]"
                               value="<?php echo esc_attr( $value ); ?>"
                               <?php checked( $checked ); ?>>
                    </label>
                    <?php $link = $this->pretty_link( $facet, $value ); ?>
                    <?php if ( $link !== '' ) : ?>
                        <a class="hof-facet-link hof-facet-name" href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $display ); ?></a>
                    <?php else : ?>
                        <span class="hof-facet-name"><?php echo esc_html( $display ); ?></span>
                    <?php endif; ?>
                    <span class="hof-facet-count" data-hof-count="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
                </li>
            <?php endif;
            return (string) ob_get_clean();
        };

        ob_start();
        ?>
        <div class="hof-facet hof-facet-hierarchy"
             data-hof-facet="<?php echo esc_attr( $name ); ?>"
             data-hof-display="hierarchy">
            <fieldset class="hof-facet-fieldset">
                <legend class="hof-facet-label"><?php echo esc_html( $label ); ?></legend>
                <?php if ( empty( $roots ) ) : ?>
                    <p class="hof-facet-empty"><?php esc_html_e( 'No options available.', 'hooked-on-facets' ); ?></p>
                <?php else : ?>
                    <ul class="hof-hierarchy-tree">
                        <?php foreach ( $roots as $root_slug ) {
                            echo $render_row( (string) $root_slug, 0 );
                        } ?>
                    </ul>
                <?php endif; ?>
            </fieldset>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Date Range — range over a date-typed numeric source. Renders HTML5 date
     * inputs; the JS layer converts ISO yyyy-mm-dd ↔ Unix epoch seconds so the
     * existing range resolver path works unchanged.
     *
     * Assumes the source meta is already stored as Unix timestamps in
     * facet_numeric. (The Indexer side of date-aware meta is a TODO.)
     *
     * @param array<string, mixed> $facet
     * @param mixed                $current_value
     * @param array<string, mixed> $counts
     */
    private function render_date_range( array $facet, $current_value, array $counts ): string {
        $name      = $facet['name'];
        $label     = $facet['label'] ?: $name;
        $bound_min = ( $counts['type'] ?? '' ) === 'range' ? $counts['min'] : null;
        $bound_max = ( $counts['type'] ?? '' ) === 'range' ? $counts['max'] : null;

        $epoch_to_iso = static function ( $v ): string {
            if ( ! is_numeric( $v ) ) return '';
            return gmdate( 'Y-m-d', (int) $v );
        };

        $value_min_iso = '';
        $value_max_iso = '';
        if ( is_array( $current_value ) ) {
            if ( isset( $current_value['min'] ) ) {
                $value_min_iso = $epoch_to_iso( $current_value['min'] );
            }
            if ( isset( $current_value['max'] ) ) {
                $value_max_iso = $epoch_to_iso( $current_value['max'] );
            }
        }
        $bound_min_iso = $bound_min !== null ? $epoch_to_iso( $bound_min ) : '';
        $bound_max_iso = $bound_max !== null ? $epoch_to_iso( $bound_max ) : '';

        ob_start();
        ?>
        <div class="hof-facet hof-facet-date-range"
             data-hof-facet="<?php echo esc_attr( $name ); ?>"
             data-hof-display="date_range">
            <span class="hof-facet-label"><?php echo esc_html( $label ); ?></span>
            <div class="hof-facet-range-inputs">
                <input type="date"
                       data-hof-input="min"
                       data-hof-date
                       name="hof[<?php echo esc_attr( $name ); ?>][min]"
                       value="<?php echo esc_attr( $value_min_iso ); ?>"
                       <?php if ( $bound_min_iso !== '' ) printf( 'min="%s"', esc_attr( $bound_min_iso ) ); ?>
                       <?php if ( $bound_max_iso !== '' ) printf( 'max="%s"', esc_attr( $bound_max_iso ) ); ?>>
                <span class="hof-facet-range-sep">→</span>
                <input type="date"
                       data-hof-input="max"
                       data-hof-date
                       name="hof[<?php echo esc_attr( $name ); ?>][max]"
                       value="<?php echo esc_attr( $value_max_iso ); ?>"
                       <?php if ( $bound_min_iso !== '' ) printf( 'min="%s"', esc_attr( $bound_min_iso ) ); ?>
                       <?php if ( $bound_max_iso !== '' ) printf( 'max="%s"', esc_attr( $bound_max_iso ) ); ?>>
            </div>
            <?php if ( $bound_min_iso !== '' && $bound_max_iso !== '' ) : ?>
                <p class="hof-facet-range-bounds">
                    <?php printf(
                        /* translators: 1: start date, 2: end date */
                        esc_html__( 'Range: %1$s – %2$s', 'hooked-on-facets' ),
                        esc_html( $bound_min_iso ),
                        esc_html( $bound_max_iso )
                    ); ?>
                </p>
            <?php endif; ?>
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
                    <?php
                    $count_fmt = number_format_i18n( $count );
                    // Tooltip surfaces full term name + count on hover/focus.
                    // Crucial for the long-tail end of the deck where the
                    // visible label below the tile truncates and the tile
                    // itself shrinks toward --hof-swatch-min.
                    $tooltip = sprintf( '%s · %s', $bucket['display'], $count_fmt );
                    // Aria-label routes the same context to screen readers;
                    // the visible <span class="hof-facet-swatch-name"> stays
                    // as the primary readable label, this just adds count.
                    $aria_label = sprintf(
                        /* translators: 1: facet value display name, 2: matching-product count */
                        _n( '%1$s, %2$s item', '%1$s, %2$s items', $count, 'hooked-on-facets' ),
                        $bucket['display'],
                        $count_fmt
                    );
                    ?>
                    <li class="hof-facet-swatch-item">
                        <label class="hof-facet-swatch-tile"
                               style="<?php echo esc_attr( $style ); ?>"
                               data-hof-swatch-value="<?php echo esc_attr( $value ); ?>"
                               data-hof-tooltip="<?php echo esc_attr( $tooltip ); ?>"
                               <?php echo $checked ? 'data-hof-selected="1"' : ''; ?>>
                            <input type="checkbox"
                                   class="hof-facet-swatch-input screen-reader-text"
                                   name="hof[<?php echo esc_attr( $name ); ?>][]"
                                   value="<?php echo esc_attr( $value ); ?>"
                                   aria-label="<?php echo esc_attr( $aria_label ); ?>"
                                   <?php checked( $checked ); ?>>
                            <span class="hof-facet-swatch-visual" aria-hidden="true">
                                <?php if ( $initial !== '' ) : ?>
                                    <span class="hof-facet-swatch-initial"><?php echo esc_html( $initial ); ?></span>
                                <?php endif; ?>
                            </span>
                        </label>
                        <span class="hof-facet-swatch-text">
                            <?php $link = $this->pretty_link( $facet, $value ); ?>
                            <?php if ( $link !== '' ) : ?>
                                <a class="hof-facet-link hof-facet-swatch-name" href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $bucket['display'] ); ?></a>
                            <?php else : ?>
                                <span class="hof-facet-swatch-name"><?php echo esc_html( $bucket['display'] ); ?></span>
                            <?php endif; ?>
                            <span class="hof-facet-count"
                                  data-hof-count="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
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

    /**
     * Pagination — numbered nav with optional first/last and prev/next.
     *
     * A view facet that doesn't filter on its own; it reads the current
     * URL's `paged` var, computes total pages from either the active HOF
     * filter result or `$wp_query->found_posts`, and renders « 1 2 … N »
     * links that preserve every other URL param via `add_query_arg`.
     *
     * Click handling lives in public/src/pagination.js — clicks get
     * intercepted, the URL is pushState'd, and refresh.js re-fetches.
     *
     * Settings:
     *   per_page         (int|null)   override; null = use WP `posts_per_page`
     *   neighbors        (int)        numbers shown around current; default 2
     *   show_first_last  (bool)       default true
     *   show_prev_next   (bool)       default true
     *
     * @param array<string, mixed> $facet
     */
    private function render_pagination( array $facet ): string {
        $settings        = is_array( $facet['settings'] ?? null ) ? $facet['settings'] : [];
        $per_page        = isset( $settings['per_page'] ) ? max( 1, (int) $settings['per_page'] ) : 0;
        if ( $per_page === 0 ) {
            $per_page = max( 1, (int) get_option( 'posts_per_page', 10 ) );
        }
        $neighbors       = isset( $settings['neighbors'] )       ? max( 0, min( 5, (int) $settings['neighbors'] ) ) : 2;
        $show_first_last = ! isset( $settings['show_first_last'] ) || (bool) $settings['show_first_last'];
        $show_prev_next  = ! isset( $settings['show_prev_next'] )  || (bool) $settings['show_prev_next'];

        // Total matched count — try HOF resolver first (it's the source of
        // truth when filters are active); fall back to the main query's
        // found_posts when no filters are applied.
        $total = $this->total_results_for_pagination();
        if ( $total <= $per_page ) {
            return ''; // No pagination needed (or one page, or no results).
        }

        $total_pages = (int) ceil( $total / $per_page );
        $current     = max( 1, (int) get_query_var( 'paged' ) );
        if ( $current === 1 && ! get_query_var( 'paged' ) ) {
            // get_query_var('paged') is 0 on page 1 of archives — normalize.
            $current = max( 1, (int) get_query_var( 'page' ) ?: 1 );
        }
        $current = max( 1, min( $total_pages, $current ) );

        $pages = $this->pagination_page_list( $current, $total_pages, $neighbors );

        ob_start();
        ?>
        <nav class="hof-facet hof-facet-pagination"
             data-hof-facet="<?php echo esc_attr( $facet['name'] ); ?>"
             data-hof-display="pagination"
             data-hof-current="<?php echo (int) $current; ?>"
             data-hof-total="<?php echo (int) $total_pages; ?>"
             aria-label="<?php esc_attr_e( 'Results pagination', 'hooked-on-facets' ); ?>">
            <ol class="hof-pagination-list">
                <?php if ( $show_first_last && $current > 2 ) : ?>
                    <li><a class="hof-pagination-btn hof-pagination-first"
                           data-hof-page="1"
                           href="<?php echo esc_url( $this->pagination_url( 1 ) ); ?>"
                           aria-label="<?php esc_attr_e( 'First page', 'hooked-on-facets' ); ?>">«</a></li>
                <?php endif; ?>

                <?php if ( $show_prev_next && $current > 1 ) : ?>
                    <li><a class="hof-pagination-btn hof-pagination-prev"
                           data-hof-page="<?php echo (int) ( $current - 1 ); ?>"
                           href="<?php echo esc_url( $this->pagination_url( $current - 1 ) ); ?>"
                           aria-label="<?php esc_attr_e( 'Previous page', 'hooked-on-facets' ); ?>">‹</a></li>
                <?php endif; ?>

                <?php foreach ( $pages as $page ) : ?>
                    <?php if ( $page === '…' ) : ?>
                        <li class="hof-pagination-gap" aria-hidden="true">…</li>
                    <?php else : ?>
                        <li>
                            <?php if ( $page === $current ) : ?>
                                <span class="hof-pagination-btn hof-pagination-num is-current"
                                      aria-current="page"><?php echo (int) $page; ?></span>
                            <?php else : ?>
                                <a class="hof-pagination-btn hof-pagination-num"
                                   data-hof-page="<?php echo (int) $page; ?>"
                                   href="<?php echo esc_url( $this->pagination_url( (int) $page ) ); ?>"
                                   aria-label="<?php echo esc_attr( sprintf( /* translators: %d: page number */ __( 'Page %d', 'hooked-on-facets' ), (int) $page ) ); ?>"><?php echo (int) $page; ?></a>
                            <?php endif; ?>
                        </li>
                    <?php endif; ?>
                <?php endforeach; ?>

                <?php if ( $show_prev_next && $current < $total_pages ) : ?>
                    <li><a class="hof-pagination-btn hof-pagination-next"
                           data-hof-page="<?php echo (int) ( $current + 1 ); ?>"
                           href="<?php echo esc_url( $this->pagination_url( $current + 1 ) ); ?>"
                           aria-label="<?php esc_attr_e( 'Next page', 'hooked-on-facets' ); ?>">›</a></li>
                <?php endif; ?>

                <?php if ( $show_first_last && $current < $total_pages - 1 ) : ?>
                    <li><a class="hof-pagination-btn hof-pagination-last"
                           data-hof-page="<?php echo (int) $total_pages; ?>"
                           href="<?php echo esc_url( $this->pagination_url( $total_pages ) ); ?>"
                           aria-label="<?php esc_attr_e( 'Last page', 'hooked-on-facets' ); ?>">»</a></li>
                <?php endif; ?>
            </ol>
        </nav>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Build the page-number list with ellipsis for gaps.
     * Example for current=5, total=10, neighbors=2 → [1, '…', 3, 4, 5, 6, 7, '…', 10]
     *
     * @return array<int, int|string>
     */
    private function pagination_page_list( int $current, int $total, int $neighbors ): array {
        $pages = [];
        $start = max( 1, $current - $neighbors );
        $end   = min( $total, $current + $neighbors );

        if ( $start > 1 ) {
            $pages[] = 1;
            if ( $start > 2 ) $pages[] = '…';
        }
        for ( $i = $start; $i <= $end; $i++ ) {
            $pages[] = $i;
        }
        if ( $end < $total ) {
            if ( $end < $total - 1 ) $pages[] = '…';
            $pages[] = $total;
        }
        return $pages;
    }

    /**
     * URL for page N, preserving every other current query arg (including
     * ?hof[*] filters). Uses WP's permalink-aware get_pagenum_link so the
     * resulting URL respects /shop/page/2/ vs ?paged=2 style.
     */
    private function pagination_url( int $page ): string {
        return (string) get_pagenum_link( $page );
    }

    private function total_results_for_pagination(): int {
        // Active HOF filters: use resolver — it's the source of truth.
        $state = Resolver::parse_request_filters();
        if ( ! empty( $state ) ) {
            $ids = $this->resolver->resolve_ids( $state );
            if ( is_array( $ids ) ) {
                return count( $ids );
            }
        }

        // No filters: fall back to the global query's found_posts. May not
        // be set in every render context (sidebar widgets that render before
        // the loop) — return 0 in that case, which suppresses the nav.
        global $wp_query;
        if ( $wp_query instanceof \WP_Query ) {
            return (int) $wp_query->found_posts;
        }
        return 0;
    }

    // ── Internals ───────────────────────────────────────────────────────────

    /**
     * Crawlable pretty URL for toggling one discrete value — '' when pretty
     * URLs are off, the facet isn't path-eligible, no codec exists, or
     * PrettySurface says the current request isn't a rule-bearing storefront
     * surface. The href is the full filter state with this value applied,
     * canonically encoded: multi-select displays (checkbox/hierarchy/swatch)
     * add/remove the value in the existing selection; single-select displays
     * (radio/dropdown) replace the selection with just this value, or clear
     * it when this value is already the active one.
     *
     * @param array<string, mixed> $facet
     */
    private function pretty_link( array $facet, string $value ): string {
        if ( ! PrettyUrls::enabled() ) {
            return '';
        }
        $codec = FilterState::codec();
        if ( ! $codec || ! $codec->is_path_facet( $facet ) ) {
            return '';
        }

        $name  = (string) $facet['name'];
        $state = Resolver::parse_request_filters();

        $single = in_array( (string) ( $facet['display'] ?? '' ), [ 'radio', 'dropdown' ], true );

        $values = array_map( 'strval', (array) ( $state[ $name ] ?? [] ) );
        if ( $single ) {
            // Single-select semantics: the link selects this value alone;
            // clicking the already-selected value clears the facet.
            $values = in_array( $value, $values, true ) ? [] : [ $value ];
        } else {
            $pos = array_search( $value, $values, true );
            if ( $pos !== false ) {
                unset( $values[ $pos ] );
                $values = array_values( $values );
            } else {
                $values[] = $value;
            }
        }
        if ( $values === [] ) {
            unset( $state[ $name ] );
        } else {
            $state[ $name ] = $values;
        }

        $encoded = $codec->encode( $state );

        // PrettySurface owns the "is this request a rule-bearing storefront
        // surface" gate — shared with SeoManager's 301/canonical layer so the
        // two never drift — plus the origin/base-path invariants, which are
        // the same for every option in a facet's bucket loop and so come back
        // memoized after the first call.
        $ctx = PrettySurface::context();
        if ( null === $ctx ) {
            return '';
        }

        $target = rtrim( $ctx['base_path'], '/' ) . ( $encoded['path'] !== '' ? $encoded['path'] : '/' );
        $url    = $ctx['origin'] . user_trailingslashit( rtrim( $target, '/' ) );

        if ( ! empty( $encoded['tail'] ) ) {
            $url .= '?' . http_build_query( [ 'hof' => $encoded['tail'] ] );
        }
        return $url;
    }

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
