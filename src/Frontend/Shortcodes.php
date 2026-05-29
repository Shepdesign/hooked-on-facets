<?php
/**
 * Shortcodes — frontend embedding surface.
 *
 *   [hof_facet name="brand"]              single facet
 *   [hof_results]...loop...[/hof_results]  swappable results region
 *   [hof_reset label="Clear all"]          deselect-all link
 *   [hof_active_filters]                   chip strip + match count
 *   [hof_bin_button id="123" label="…"]    add-to-saved-bin button
 *
 * @package HookedOnFacets
 */

declare(strict_types=1);

namespace HookedOnFacets\Frontend;

use HookedOnFacets\Contracts\Bootable;
use HookedOnFacets\Facets\Renderer;

defined( 'ABSPATH' ) || exit;

final class Shortcodes implements Bootable {

    public function __construct( private readonly Renderer $renderer ) {}

    public function register_hooks(): void {
        add_shortcode( 'hof_facet',          [ $this, 'shortcode_facet' ] );
        add_shortcode( 'hof_results',        [ $this, 'shortcode_results' ] );
        add_shortcode( 'hof_reset',          [ $this, 'shortcode_reset' ] );
        add_shortcode( 'hof_active_filters', [ $this, 'shortcode_active_filters' ] );
        add_shortcode( 'hof_bin_button',     [ $this, 'shortcode_bin_button' ] );
    }

    /**
     * Add-to-saved-bin button. Drop inside a product card template (the id is
     * the post ID, the label what shows in the bin). bin.js handles the click,
     * toggling membership in localStorage. Defaults the id to the current loop
     * post so `[hof_bin_button]` works bare inside a loop.
     *
     * @param array<string, string>|string $atts
     */
    public function shortcode_bin_button( $atts ): string {
        $atts = shortcode_atts(
            [ 'id' => '', 'label' => '', 'add' => '', 'added' => '' ],
            (array) $atts,
            'hof_bin_button'
        );

        $id = (int) $atts['id'];
        if ( $id <= 0 ) {
            $id = (int) get_the_ID();
        }
        if ( $id <= 0 ) {
            return '';
        }

        $label   = $atts['label'] !== '' ? $atts['label'] : (string) get_the_title( $id );
        $add_txt = $atts['add'] !== '' ? $atts['add'] : __( 'Save to bin', 'hooked-on-facets' );

        return sprintf(
            '<button type="button" class="hof-bin-add" draggable="true" data-hof-bin-add data-hof-bin-id="%d" data-hof-bin-label="%s">%s</button>',
            $id,
            esc_attr( $label ),
            esc_html( $add_txt )
        );
    }

    /**
     * @param array<string, string>|string $atts
     */
    public function shortcode_facet( $atts ): string {
        $atts = shortcode_atts( [ 'name' => '' ], (array) $atts, 'hof_facet' );
        $name = sanitize_key( $atts['name'] );
        if ( $name === '' ) {
            return '';
        }
        return $this->renderer->render( $name );
    }

    /**
     * @param array<string, string>|string $atts
     */
    public function shortcode_results( $atts, ?string $content = null ): string {
        return sprintf(
            '<div class="hof-results" data-hof-results>%s</div>',
            do_shortcode( (string) $content )
        );
    }

    public function shortcode_active_filters( $atts ): string {
        return $this->renderer->render_active_filters();
    }

    /**
     * @param array<string, string>|string $atts
     */
    public function shortcode_reset( $atts ): string {
        $atts = shortcode_atts(
            [ 'label' => __( 'Clear all filters', 'hooked-on-facets' ) ],
            (array) $atts,
            'hof_reset'
        );

        // Build a URL with all ?hof[*] params stripped — graceful fallback for
        // no-JS users; JS hijacks the click to clear state in place.
        $url = remove_query_arg( array_keys( $this->collect_hof_query_keys() ) );

        return sprintf(
            '<a class="hof-reset" data-hof-reset href="%s">%s</a>',
            esc_url( $url ),
            esc_html( $atts['label'] )
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function collect_hof_query_keys(): array {
        if ( ! isset( $_GET ) || ! is_array( $_GET ) ) {
            return [];
        }
        $out = [];
        foreach ( $_GET as $key => $value ) {
            if ( is_string( $key ) && str_starts_with( $key, 'hof' ) ) {
                $out[ $key ] = $value;
            }
        }
        return $out;
    }
}
