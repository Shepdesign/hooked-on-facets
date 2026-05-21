<?php
/**
 * Render callback fallback for hof/facet.
 *
 * Normally the BlockRegistrar's render_callback supersedes this file (it
 * routes through the Renderer service via the DI container). This file is
 * here as a defensive fallback in case register_block_type() resolves the
 * file-based render hint before the PHP callback override.
 *
 * @package HookedOnFacets
 *
 * @var array<string, mixed> $attributes
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

$name = isset( $attributes['facetName'] ) ? sanitize_key( (string) $attributes['facetName'] ) : '';
if ( $name === '' ) {
    return;
}

if ( ! class_exists( \HookedOnFacets\Plugin::class ) ) {
    return;
}

echo \HookedOnFacets\Plugin::instance()
    ->make( \HookedOnFacets\Facets\Renderer::class )
    ->render( $name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Renderer escapes internally.
