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
            'range'       => $this->render_range( $facet, $current_value, $counts ),
            'date_range'  => $this->render_date_range( $facet, $current_value, $counts ),
            'search'      => $this->render_search( $facet, $current_value ),
            'swatch'      => $this->render_swatch( $facet, (array) $current_value, $counts ),
            'swiper'      => $this->render_swiper( $facet, (array) $current_value, $counts ),
            'radio'       => $this->render_radio( $facet, (array) $current_value, $counts ),
            'dropdown'    => $this->render_dropdown( $facet, (array) $current_value, $counts ),
            'toggle'      => $this->render_toggle( $facet, (array) $current_value ),
            'hierarchy'   => $this->render_hierarchy( $facet, (array) $current_value, $counts ),
            'two_d_slider' => $this->render_two_d_slider( $facet ),
            'ask'         => $this->render_ask( $facet ),
            'visual_dna'  => $this->render_visual_dna( $facet ),
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
                                          data-hof-count="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( number_format_i18n( (int) $bucket['count'] ) ); ?></span>
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
                                    <span class="hof-facet-name"><?php echo esc_html( $bucket['display'] ); ?></span>
                                    <span class="hof-facet-count"
                                          data-hof-count="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( number_format_i18n( (int) $bucket['count'] ) ); ?></span>
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

        $render_row = function ( string $slug, int $depth ) use ( &$render_row, $by_slug, $children, $selected_lookup, $name ): string {
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
                                <span class="hof-facet-name"><?php echo esc_html( $display ); ?></span>
                                <span class="hof-facet-count" data-hof-count="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
                            </label>
                        </summary>
                        <ul class="hof-hierarchy-children">
                            <?php foreach ( $kids as $child_slug ) {
                                echo $render_row( $child_slug, $depth + 1 );
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
                        <span class="hof-facet-name"><?php echo esc_html( $display ); ?></span>
                        <span class="hof-facet-count" data-hof-count="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
                    </label>
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
                            echo $render_row( $root_slug, 0 );
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
                                      data-hof-count="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
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
                                      data-hof-count="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
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

    /**
     * 2D slider — a view facet that orchestrates two underlying range facets
     * on a draggable plane. The facet itself produces no resolver filter; its
     * interactions update the URL state for the underlying x/y range facets,
     * which the existing range resolver path handles natively.
     *
     * Config shape:
     *   {
     *     "name": "explore",
     *     "kind": "view",
     *     "display": "two_d_slider",
     *     "settings": { "x_facet": "price", "y_facet": "rating" }
     *   }
     *
     * @param array<string, mixed> $facet
     */
    private function render_two_d_slider( array $facet ): string {
        $name     = $facet['name'];
        $label    = $facet['label'] ?: $name;
        $settings = (array) ( $facet['settings'] ?? [] );
        $x_name   = (string) ( $settings['x_facet'] ?? '' );
        $y_name   = (string) ( $settings['y_facet'] ?? '' );

        $x_facet = $x_name !== '' ? $this->find_facet( $x_name ) : null;
        $y_facet = $y_name !== '' ? $this->find_facet( $y_name ) : null;
        if ( ! $x_facet || ! $y_facet ) {
            return sprintf(
                '<div class="hof-facet hof-facet-2d hof-facet-empty"><span class="hof-facet-label">%s</span><p>%s</p></div>',
                esc_html( $label ),
                esc_html__( '2D slider needs valid x_facet and y_facet settings.', 'hooked-on-facets' )
            );
        }

        $x_counts = $this->counts_for( $x_name );
        $y_counts = $this->counts_for( $y_name );
        $x_min    = (float) ( $x_counts['min'] ?? 0 );
        $x_max    = (float) ( $x_counts['max'] ?? 1 );
        $y_min    = (float) ( $y_counts['min'] ?? 0 );
        $y_max    = (float) ( $y_counts['max'] ?? 1 );

        // Current selection (from URL state of the underlying range facets).
        $state    = Resolver::parse_request_filters();
        $x_state  = (array) ( $state[ $x_name ] ?? [] );
        $y_state  = (array) ( $state[ $y_name ] ?? [] );
        $x_low    = isset( $x_state['min'] ) ? (float) $x_state['min'] : $x_min;
        $x_high   = isset( $x_state['max'] ) ? (float) $x_state['max'] : $x_max;
        $y_low    = isset( $y_state['min'] ) ? (float) $y_state['min'] : $y_min;
        $y_high   = isset( $y_state['max'] ) ? (float) $y_state['max'] : $y_max;

        // Rectangle position on the plane, in percent (Y inverted so visual
        // up = higher value).
        $pct = static function ( float $v, float $lo, float $hi ): float {
            $r = $hi - $lo;
            return $r > 0 ? max( 0.0, min( 100.0, ( ( $v - $lo ) / $r ) * 100.0 ) ) : 0.0;
        };
        $left   = $pct( $x_low,  $x_min, $x_max );
        $right  = $pct( $x_high, $x_min, $x_max );
        $top    = 100.0 - $pct( $y_high, $y_min, $y_max );
        $bottom = 100.0 - $pct( $y_low,  $y_min, $y_max );

        $fmt = static fn( float $n ): string => rtrim( rtrim( sprintf( '%.2f', $n ), '0' ), '.' );

        ob_start();
        ?>
        <div class="hof-facet hof-facet-2d"
             data-hof-facet="<?php echo esc_attr( $name ); ?>"
             data-hof-display="two_d_slider"
             data-hof-x-facet="<?php echo esc_attr( $x_name ); ?>"
             data-hof-y-facet="<?php echo esc_attr( $y_name ); ?>"
             data-hof-x-min="<?php echo esc_attr( (string) $x_min ); ?>"
             data-hof-x-max="<?php echo esc_attr( (string) $x_max ); ?>"
             data-hof-y-min="<?php echo esc_attr( (string) $y_min ); ?>"
             data-hof-y-max="<?php echo esc_attr( (string) $y_max ); ?>">
            <span class="hof-facet-label"><?php echo esc_html( $label ); ?></span>
            <div class="hof-2d-grid">
                <span class="hof-2d-axis-y"><?php echo esc_html( $y_facet['label'] ?: $y_name ); ?></span>
                <div class="hof-2d-plane">
                    <div class="hof-2d-rect"
                         style="left: <?php echo esc_attr( $fmt( $left ) ); ?>%; right: <?php echo esc_attr( $fmt( 100 - $right ) ); ?>%; top: <?php echo esc_attr( $fmt( $top ) ); ?>%; bottom: <?php echo esc_attr( $fmt( 100 - $bottom ) ); ?>%;">
                        <span class="hof-2d-handle hof-2d-handle-n"  data-hof-2d-handle="n"  aria-hidden="true"></span>
                        <span class="hof-2d-handle hof-2d-handle-s"  data-hof-2d-handle="s"  aria-hidden="true"></span>
                        <span class="hof-2d-handle hof-2d-handle-e"  data-hof-2d-handle="e"  aria-hidden="true"></span>
                        <span class="hof-2d-handle hof-2d-handle-w"  data-hof-2d-handle="w"  aria-hidden="true"></span>
                        <span class="hof-2d-handle hof-2d-handle-nw" data-hof-2d-handle="nw" aria-hidden="true"></span>
                        <span class="hof-2d-handle hof-2d-handle-ne" data-hof-2d-handle="ne" aria-hidden="true"></span>
                        <span class="hof-2d-handle hof-2d-handle-sw" data-hof-2d-handle="sw" aria-hidden="true"></span>
                        <span class="hof-2d-handle hof-2d-handle-se" data-hof-2d-handle="se" aria-hidden="true"></span>
                    </div>
                </div>
                <span class="hof-2d-axis-x"><?php echo esc_html( $x_facet['label'] ?: $x_name ); ?></span>
                <p class="hof-2d-readout">
                    <span class="hof-2d-x-readout"><?php echo esc_html( $fmt( $x_low ) . ' – ' . $fmt( $x_high ) ); ?></span>
                    <span class="hof-2d-readout-sep">·</span>
                    <span class="hof-2d-y-readout"><?php echo esc_html( $fmt( $y_low ) . ' – ' . $fmt( $y_high ) ); ?></span>
                </p>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Ask — conversational, multi-turn natural-language facet. Like the 2D
     * slider, it's a view facet that produces no resolver filter of its own;
     * each turn POSTs to /wp-json/hof/v1/ask with the current chip state, and
     * the public runtime applies the returned constraints via the store.
     *
     * Renders even when no API key is configured so the public facing UI
     * doesn't disappear unpredictably for admins testing — the JS surfaces
     * "Ask isn't available right now" inline if the endpoint reports no_api_key.
     *
     * @param array<string, mixed> $facet
     */
    private function render_ask( array $facet ): string {
        $name        = (string) $facet['name'];
        $label       = (string) ( $facet['label'] ?: $name );
        $settings    = (array) ( $facet['settings'] ?? [] );
        $placeholder = (string) ( $settings['placeholder'] ?? __( 'Describe what you\'re looking for…', 'hooked-on-facets' ) );

        ob_start();
        ?>
        <div class="hof-facet hof-facet-ask"
             data-hof-facet="<?php echo esc_attr( $name ); ?>"
             data-hof-display="ask">
            <span class="hof-facet-label"><?php echo esc_html( $label ); ?></span>
            <form class="hof-ask-form" data-hof-ask-form>
                <span class="hof-ask-icon" aria-hidden="true">✦</span>
                <input type="text"
                       class="hof-ask-input"
                       name="hof-ask-query"
                       placeholder="<?php echo esc_attr( $placeholder ); ?>"
                       autocomplete="off"
                       data-hof-ask-input>
                <button type="submit"
                        class="hof-ask-submit"
                        aria-label="<?php esc_attr_e( 'Ask', 'hooked-on-facets' ); ?>"
                        data-hof-ask-submit>
                    <span aria-hidden="true">▶</span>
                </button>
            </form>
            <div class="hof-ask-heard"
                 data-hof-ask-heard
                 hidden>
                <p class="hof-ask-heard-label"><?php esc_html_e( 'I heard:', 'hooked-on-facets' ); ?></p>
                <ul class="hof-ask-chips" data-hof-ask-chips></ul>
                <button type="button"
                        class="hof-ask-reset"
                        data-hof-ask-reset>
                    <?php esc_html_e( '↺ Start over', 'hooked-on-facets' ); ?>
                </button>
            </div>
            <p class="hof-ask-status"
               data-hof-ask-status
               hidden></p>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Visual DNA — drop an image, paste a URL, or eyedrop a color, and the
     * catalog filters to products in the closest matching color term.
     *
     * A view facet: it doesn't produce a resolver filter directly. Sampling
     * resolves the input to a hex, finds the nearest term in the configured
     * `target_facet`, and applies that as a normal filter via the store.
     *
     * The color-term list is inlined as JSON on a data attribute so the
     * runtime doesn't need a network round-trip on init.
     *
     * @param array<string, mixed> $facet
     */
    private function render_visual_dna( array $facet ): string {
        $name     = (string) $facet['name'];
        $label    = (string) ( $facet['label'] ?: $name );
        $settings = (array) ( $facet['settings'] ?? [] );
        $target   = (string) ( $settings['target_facet'] ?? '' );

        $target_facet = $target !== '' ? $this->find_facet( $target ) : null;
        if ( ! $target_facet ) {
            return sprintf(
                '<div class="hof-facet hof-facet-visual-dna hof-facet-misconfigured" data-hof-facet="%s">' .
                '<span class="hof-facet-label">%s</span>' .
                '<p class="hof-facet-empty">%s</p>' .
                '</div>',
                esc_attr( $name ),
                esc_html( $label ),
                esc_html__( 'Visual DNA needs a target color facet. Pick one in the admin.', 'hooked-on-facets' )
            );
        }

        $color_map = $this->build_color_term_map( $target_facet );
        $supports_eyedropper = true; // The JS feature-detects and hides if not.

        ob_start();
        ?>
        <div class="hof-facet hof-facet-visual-dna"
             data-hof-facet="<?php echo esc_attr( $name ); ?>"
             data-hof-display="visual_dna"
             data-hof-target-facet="<?php echo esc_attr( $target ); ?>"
             data-hof-color-map="<?php echo esc_attr( wp_json_encode( $color_map ) ); ?>">
            <span class="hof-facet-label"><?php echo esc_html( $label ); ?></span>

            <div class="hof-visual-dna-drop"
                 data-hof-visual-drop
                 role="button"
                 tabindex="0">
                <input type="file"
                       class="hof-visual-dna-file"
                       accept="image/*"
                       data-hof-visual-file
                       hidden>
                <span class="hof-visual-dna-drop-icon" aria-hidden="true">⬇</span>
                <span class="hof-visual-dna-drop-text">
                    <?php esc_html_e( 'Drop an image, click to pick a file, paste a URL below, or use the eyedropper.', 'hooked-on-facets' ); ?>
                </span>
            </div>

            <div class="hof-visual-dna-actions">
                <input type="url"
                       class="hof-visual-dna-url"
                       placeholder="<?php esc_attr_e( 'Paste an image URL…', 'hooked-on-facets' ); ?>"
                       autocomplete="off"
                       data-hof-visual-url>
                <button type="button"
                        class="hof-visual-dna-eyedrop"
                        data-hof-visual-eyedrop
                        hidden
                        aria-label="<?php esc_attr_e( 'Eyedropper', 'hooked-on-facets' ); ?>">
                    <span aria-hidden="true">🎨</span>
                    <?php esc_html_e( 'Pick', 'hooked-on-facets' ); ?>
                </button>
            </div>

            <div class="hof-visual-dna-result" data-hof-visual-result hidden>
                <span class="hof-visual-dna-swatch" data-hof-visual-swatch aria-hidden="true"></span>
                <span class="hof-visual-dna-readout">
                    <span class="hof-visual-dna-hex" data-hof-visual-hex></span>
                    <span class="hof-visual-dna-match-row">
                        <span class="hof-visual-dna-match-caption"><?php esc_html_e( 'Closest match:', 'hooked-on-facets' ); ?></span>
                        <span class="hof-visual-dna-match" data-hof-visual-match></span>
                    </span>
                </span>
                <button type="button"
                        class="hof-visual-dna-clear"
                        data-hof-visual-clear>
                    <?php esc_html_e( '↺ Clear', 'hooked-on-facets' ); ?>
                </button>
            </div>

            <p class="hof-visual-dna-status" data-hof-visual-status hidden></p>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Build a {slug, hex, label}[] map for the visual_dna runtime.
     *
     * Two paths, depending on the target facet's kind:
     *   - taxonomy → walk terms, prefer term_meta `swatch_color` (the key
     *     the Color Swatch facet already uses), fall back to a built-in
     *     CSS-color-name table.
     *   - meta / field → walk distinct facet_value rows in the index for
     *     this facet, look each value up in the fallback table.
     *
     * Values that don't resolve to a hex are dropped.
     *
     * @param array<string, mixed> $target_facet
     * @return array<int, array{slug: string, hex: string, label: string}>
     */
    private function build_color_term_map( array $target_facet ): array {
        $kind = (string) ( $target_facet['kind'] ?? '' );
        if ( $kind === 'taxonomy' ) {
            return $this->build_color_term_map_taxonomy( $target_facet );
        }
        if ( $kind === 'meta' || $kind === 'field' ) {
            return $this->build_color_term_map_from_index( $target_facet );
        }
        return [];
    }

    /**
     * @param array<string, mixed> $target_facet
     * @return array<int, array{slug: string, hex: string, label: string}>
     */
    private function build_color_term_map_taxonomy( array $target_facet ): array {
        $taxonomy = (string) ( $target_facet['source'] ?? '' );
        if ( $taxonomy === '' ) {
            return [];
        }
        $terms = get_terms( [
            'taxonomy'   => $taxonomy,
            'hide_empty' => false,
        ] );
        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            return [];
        }

        $fallback = self::css_color_name_map();
        $out      = [];
        foreach ( $terms as $term ) {
            if ( ! $term instanceof \WP_Term ) {
                continue;
            }
            $hex = (string) get_term_meta( $term->term_id, 'swatch_color', true );
            if ( $hex === '' || ! preg_match( '/^#[0-9a-f]{6}$/i', $hex ) ) {
                $key = strtolower( $term->slug );
                $hex = $fallback[ $key ] ?? ( $fallback[ strtolower( $term->name ) ] ?? '' );
            }
            if ( $hex === '' ) {
                continue;
            }
            $out[] = [
                'slug'  => $term->slug,
                'hex'   => $hex,
                'label' => $term->name,
            ];
        }
        return $out;
    }

    /**
     * For meta/field facets, pull distinct facet_value rows from the index
     * and match each against the CSS-name fallback table.
     *
     * @param array<string, mixed> $target_facet
     * @return array<int, array{slug: string, hex: string, label: string}>
     */
    private function build_color_term_map_from_index( array $target_facet ): array {
        global $wpdb;
        $name = (string) ( $target_facet['name'] ?? '' );
        if ( $name === '' ) {
            return [];
        }
        $table = $wpdb->prefix . \HookedOnFacets\Activator::TABLE;
        $rows  = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT facet_value AS value, MAX(facet_display) AS display
                 FROM {$table}
                 WHERE facet_name = %s
                 GROUP BY facet_value
                 ORDER BY facet_display ASC",
                $name
            ),
            ARRAY_A
        ) ?: [];

        $fallback = self::css_color_name_map();
        $out      = [];
        foreach ( $rows as $r ) {
            $value   = (string) $r['value'];
            $display = (string) ( $r['display'] ?: $value );
            $key     = strtolower( $value );
            $hex     = $fallback[ $key ] ?? ( $fallback[ strtolower( $display ) ] ?? '' );
            if ( $hex === '' ) {
                continue;
            }
            $out[] = [
                'slug'  => $value,
                'hex'   => $hex,
                'label' => $display,
            ];
        }
        return $out;
    }

    /**
     * Common color names → hex. Lowercase keys. Used as a fallback when a
     * term has no `swatch_color` meta. Not exhaustive — designed to cover
     * the most common product-attribute colors.
     *
     * @return array<string, string>
     */
    private static function css_color_name_map(): array {
        return [
            'black'   => '#000000',
            'white'   => '#ffffff',
            'gray'    => '#808080',
            'grey'    => '#808080',
            'silver'  => '#c0c0c0',
            'red'     => '#dc2626',
            'crimson' => '#dc143c',
            'pink'    => '#ec4899',
            'fuchsia' => '#d946ef',
            'magenta' => '#d946ef',
            'orange'  => '#f97316',
            'amber'   => '#f59e0b',
            'yellow'  => '#eab308',
            'olive'   => '#65a30d',
            'lime'    => '#84cc16',
            'green'   => '#16a34a',
            'teal'    => '#14b8a6',
            'cyan'    => '#06b6d4',
            'blue'    => '#2563eb',
            'navy'    => '#1e3a8a',
            'indigo'  => '#4f46e5',
            'violet'  => '#7c3aed',
            'purple'  => '#9333ea',
            'lavender'=> '#c4b5fd',
            'brown'   => '#92400e',
            'tan'     => '#d2b48c',
            'beige'   => '#f5f5dc',
            'cream'   => '#fffdd0',
            'ivory'   => '#fffff0',
            'gold'    => '#d4af37',
            'maroon'  => '#7f1d1d',
            'burgundy'=> '#7f1d1d',
            'coral'   => '#ff7f50',
            'salmon'  => '#fa8072',
            'turquoise' => '#40e0d0',
            'mint'    => '#a7f3d0',
            'khaki'   => '#bdb76b',
            'charcoal'=> '#36454f',
        ];
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
