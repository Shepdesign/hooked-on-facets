<?php
/**
 * Elementor widget: Hooked Facet.
 *
 * Mirror of the hof/facet Gutenberg block and the [hof_facet] shortcode —
 * all three surfaces delegate to the same Renderer service so markup is
 * identical regardless of placement.
 *
 * This file is require_once'd by the Elementor bridge inside the
 * `elementor/widgets/register` callback. PSR-4 autoload deliberately
 * doesn't reach it: the class extends \Elementor\Widget_Base which only
 * exists when Elementor itself is loaded.
 *
 * @package HookedOnFacets\Integrations\Elementor
 */

declare(strict_types=1);

namespace HookedOnFacets\Integrations\Elementor;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;
use HookedOnFacets\Facets\Renderer;
use HookedOnFacets\Plugin;

defined( 'ABSPATH' ) || exit;

final class FacetWidget extends Widget_Base {

    public function get_name(): string {
        return 'hof-facet';
    }

    public function get_title(): string {
        return esc_html__( 'Hooked Facet', 'hooked-on-facets' );
    }

    public function get_icon(): string {
        return 'eicon-filter';
    }

    /**
     * @return string[]
     */
    public function get_categories(): array {
        return [ 'general' ];
    }

    /**
     * @return string[]
     */
    public function get_keywords(): array {
        return [ 'facet', 'filter', 'search', 'hof' ];
    }

    protected function register_controls(): void {
        $this->start_controls_section(
            'section_facet',
            [
                'label' => esc_html__( 'Facet', 'hooked-on-facets' ),
                'tab'   => Controls_Manager::TAB_CONTENT,
            ]
        );

        $this->add_control(
            'facet_name',
            [
                'label'       => esc_html__( 'Facet slug', 'hooked-on-facets' ),
                'type'        => Controls_Manager::TEXT,
                'description' => esc_html__( 'The "Slug" of a facet you created in the HOF admin.', 'hooked-on-facets' ),
                'placeholder' => 'price',
                'dynamic'     => [ 'active' => true ],
            ]
        );

        $this->end_controls_section();
    }

    protected function render(): void {
        $settings = $this->get_settings_for_display();
        $raw_name = is_array( $settings ) ? ( $settings['facet_name'] ?? '' ) : '';
        $name     = sanitize_key( (string) $raw_name );

        if ( $name === '' ) {
            // Show a hint in the Elementor editor; on the live site this
            // surface is empty (nothing to render).
            if ( $this->is_edit_mode() ) {
                printf(
                    '<p style="padding:12px;border:1px dashed #c3c4c7;color:#646970;">%s</p>',
                    esc_html__( 'Set a facet slug in the widget panel to preview.', 'hooked-on-facets' )
                );
            }
            return;
        }

        // Renderer escapes internally.
        echo Plugin::instance()->make( Renderer::class )->render( $name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    private function is_edit_mode(): bool {
        return class_exists( '\\Elementor\\Plugin' )
            && method_exists( \Elementor\Plugin::class, 'instance' )
            && \Elementor\Plugin::instance()->editor->is_edit_mode();
    }
}
