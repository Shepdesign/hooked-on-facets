<?php
/**
 * Shortcodes — frontend embedding surface.
 *
 *   [hof_facet name="brand"]            single facet
 *   [hof_results]...loop...[/hof_results] swappable results region
 *   [hof_reset label="Clear all"]        deselect-all link
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
        add_shortcode( 'hof_facet',   [ $this, 'shortcode_facet' ] );
        add_shortcode( 'hof_results', [ $this, 'shortcode_results' ] );
        add_shortcode( 'hof_reset',   [ $this, 'shortcode_reset' ] );
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
