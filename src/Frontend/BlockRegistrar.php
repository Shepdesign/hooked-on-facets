<?php
/**
 * BlockRegistrar — registers the `hof/facet` dynamic block.
 *
 * Block metadata + render callback live in integrations/gutenberg/blocks/facet/.
 * The render callback delegates to the same Renderer the shortcode uses, so
 * markup is identical regardless of placement surface.
 *
 * @package HookedOnFacets
 */

declare(strict_types=1);

namespace HookedOnFacets\Frontend;

use HookedOnFacets\Contracts\Bootable;
use HookedOnFacets\Facets\Renderer;

defined( 'ABSPATH' ) || exit;

final class BlockRegistrar implements Bootable {

    public function __construct( private readonly Renderer $renderer ) {}

    public function register_hooks(): void {
        add_action( 'init', [ $this, 'register_blocks' ] );
    }

    public function register_blocks(): void {
        register_block_type(
            HOF_PLUGIN_DIR . 'integrations/gutenberg/blocks/facet',
            [
                'render_callback' => [ $this, 'render_facet_block' ],
            ]
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function render_facet_block( array $attributes ): string {
        $name = isset( $attributes['facetName'] ) ? sanitize_key( (string) $attributes['facetName'] ) : '';
        if ( $name === '' ) {
            return '';
        }
        return $this->renderer->render( $name );
    }
}
