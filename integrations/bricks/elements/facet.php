<?php
/**
 * Bricks element: Hooked Facet.
 *
 * Mirror of the hof/facet Gutenberg block, the [hof_facet] shortcode, and
 * the Elementor widget — all four surfaces delegate to the same Renderer
 * service so markup is identical regardless of placement.
 *
 * This file is included by \Bricks\Elements::register_element() from the
 * Bricks bridge (on `init`). PSR-4 autoload deliberately doesn't reach it:
 * the class extends \Bricks\Element, which only exists when Bricks itself
 * is loaded.
 *
 * @package HookedOnFacets\Integrations\Bricks
 */

declare(strict_types=1);

namespace HookedOnFacets\Integrations\Bricks;

use HookedOnFacets\Facets\Renderer;
use HookedOnFacets\Plugin;

defined( 'ABSPATH' ) || exit;

final class FacetElement extends \Bricks\Element {

    /**
     * Element category in the Bricks panel.
     *
     * @var string
     */
    public $category = 'general';

    /**
     * Unique element name (matches the name passed to register_element).
     *
     * @var string
     */
    public $name = 'hof-facet';

    /**
     * Themify icon class shown in the Bricks element list.
     *
     * @var string
     */
    public $icon = 'ti-filter';

    public function get_label(): string {
        return esc_html__( 'Hooked Facet', 'hooked-on-facets' );
    }

    /**
     * @return string[]
     */
    public function get_keywords(): array {
        return [ 'facet', 'filter', 'search', 'hof' ];
    }

    public function set_controls(): void {
        $this->controls['facet_name'] = [
            'tab'         => 'content',
            'label'       => esc_html__( 'Facet slug', 'hooked-on-facets' ),
            'type'        => 'text',
            'placeholder' => 'price',
            'description' => esc_html__( 'The "Slug" of a facet you created in the HOF admin.', 'hooked-on-facets' ),
        ];
    }

    public function render(): void {
        $settings = is_array( $this->settings ) ? $this->settings : [];
        $raw_name = $settings['facet_name'] ?? '';
        $name     = sanitize_key( (string) $raw_name );

        if ( $name === '' ) {
            // Show a hint inside the Bricks builder; on the live site this
            // surface is empty (nothing to render).
            if ( $this->is_builder_context() ) {
                printf(
                    '<p style="padding:12px;border:1px dashed #c3c4c7;color:#646970;">%s</p>',
                    esc_html__( 'Set a facet slug in the element panel to preview.', 'hooked-on-facets' )
                );
            }
            return;
        }

        // Renderer escapes internally.
        echo Plugin::instance()->make( Renderer::class )->render( $name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }

    private function is_builder_context(): bool {
        return ( function_exists( 'bricks_is_builder_call' ) && bricks_is_builder_call() )
            || ( function_exists( 'bricks_is_builder' ) && bricks_is_builder() );
    }
}
