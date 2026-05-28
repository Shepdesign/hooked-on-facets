<?php
/**
 * Divi module: Hooked Facet.
 *
 * Mirror of the hof/facet Gutenberg block, the [hof_facet] shortcode, the
 * Elementor widget, and the Bricks element — every placement surface
 * delegates to the same Renderer service so markup is identical regardless
 * of where it's dropped.
 *
 * This file is require_once'd by the Divi bridge from its `et_builder_ready`
 * callback. PSR-4 autoload deliberately doesn't reach it: the class extends
 * \ET_Builder_Module, which only exists once Divi's builder is loaded.
 *
 * Classic (D4) module API, three departures from the Elementor/Bricks
 * placement classes worth knowing:
 *
 *   - The slug MUST start with `et_pb_` or Divi's shortcode callbacks never
 *     fire and the module won't persist in saved layouts.
 *   - render() *returns* its markup; it does not echo (Elementor and Bricks
 *     echo from their render()).
 *   - Settings are read from `$this->props`, not a getter.
 *
 * `vb_support = 'on'` opts the module into the Visual Builder via Divi's
 * server-side render-over-AJAX path (no companion .jsx). That path, and the
 * module showing up correctly in the VB element list, is the part that needs
 * validation against a live Divi install — the live-site frontend render is
 * the plain `render()` return below and doesn't depend on it.
 *
 * @package HookedOnFacets\Integrations\Divi
 */

declare(strict_types=1);

namespace HookedOnFacets\Integrations\Divi;

use HookedOnFacets\Facets\Renderer;
use HookedOnFacets\Plugin;

defined( 'ABSPATH' ) || exit;

final class FacetModule extends \ET_Builder_Module {

    /**
     * Module slug. Divi requires the `et_pb_` prefix for its shortcode
     * callbacks to register and for the module to save into layouts.
     *
     * @var string
     */
    public $slug = 'et_pb_hof_facet';

    /**
     * Opt into the Visual Builder (server-side render path; no JSX).
     *
     * @var string
     */
    public $vb_support = 'on';

    public function init(): void {
        $this->name = esc_html__( 'Hooked Facet', 'hooked-on-facets' );
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function get_fields(): array {
        return [
            'facet_name' => [
                'label'           => esc_html__( 'Facet slug', 'hooked-on-facets' ),
                'type'            => 'text',
                'option_category' => 'basic_option',
                'description'     => esc_html__( 'The "Slug" of a facet you created in the HOF admin.', 'hooked-on-facets' ),
                'toggle_slug'     => 'main_content',
            ],
        ];
    }

    /**
     * Group the facet field under a "Facet" toggle in the settings modal.
     *
     * @return array<string, array<string, mixed>>
     */
    public function get_settings_modal_toggles(): array {
        return [
            'general' => [
                'toggles' => [
                    'main_content' => esc_html__( 'Facet', 'hooked-on-facets' ),
                ],
            ],
        ];
    }

    /**
     * Divi calls render() and prints what it returns — so unlike the
     * Elementor / Bricks placement classes, we return the markup here.
     *
     * @param array<string, mixed> $attrs       Unprocessed shortcode atts (unused; read $this->props).
     * @param string|null          $content     Inner content (unused).
     * @param string               $render_slug Slug used for rendering (unused).
     * @return string
     */
    public function render( $attrs, $content, $render_slug ): string {
        $props    = is_array( $this->props ) ? $this->props : [];
        $raw_name = $props['facet_name'] ?? '';
        $name     = sanitize_key( (string) $raw_name );

        if ( $name === '' ) {
            return '';
        }

        // Renderer escapes internally.
        return Plugin::instance()->make( Renderer::class )->render( $name );
    }
}
