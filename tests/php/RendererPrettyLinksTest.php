<?php
/**
 * @package HookedOnFacets\Tests
 */

declare(strict_types=1);

namespace HookedOnFacets\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HookedOnFacets\Facets\Renderer;
use HookedOnFacets\Filter\Resolver;
use HookedOnFacets\Routing\FilterState;
use PHPUnit\Framework\TestCase;

/**
 * Covers crawlable pretty-link emission in the checkbox display: anchor when
 * pretty URLs are on, plain span when off, toggled-state hrefs.
 */
final class RendererPrettyLinksTest extends TestCase {

    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $_GET          = [];
        $this->options = [];
        FilterState::reset();

        Functions\when( 'get_option' )->alias( fn( $name, $default = false ) => $this->options[ $name ] ?? $default );
        Functions\when( 'get_query_var' )->justReturn( '' );
        Functions\when( 'did_action' )->justReturn( 1 );
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'sanitize_text_field' )->alias( static fn( $s ) => trim( (string) $s ) );
        Functions\when( 'sanitize_key' )->alias( static fn( $s ) => strtolower( preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ) ) );
        Functions\when( 'sanitize_title' )->alias(
            static fn( $t ) => trim( preg_replace( '/[^a-z0-9-]+/', '-', strtolower( (string) $t ) ), '-' )
        );
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'esc_html_e' )->alias( static function ( $s ) { echo $s; } );
        Functions\when( 'checked' )->justReturn( '' );
        Functions\when( 'number_format_i18n' )->alias( static fn( $n ) => (string) $n );
        Functions\when( 'user_trailingslashit' )->alias( static fn( $s ) => rtrim( (string) $s, '/' ) . '/' );
        Functions\when( 'wp_parse_url' )->alias( static fn( $url ) => parse_url( (string) $url ) );
        Functions\when( 'is_ssl' )->justReturn( true );
        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );

        $_SERVER['HTTP_HOST']   = 'shop.test';
        $_SERVER['REQUEST_URI'] = '/shop/';

        $GLOBALS['wpdb'] = new class() {
            public string $prefix = 'wp_';
            public function prepare( string $sql, ...$args ): string {
                return str_replace( '%s', "'" . $args[0] . "'", $sql );
            }
            public function get_col( string $sql ): array {
                return str_contains( $sql, "'brand'" ) ? [ 'adidas', 'nike' ] : [];
            }
        };
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wpdb'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
        $_GET = [];
        FilterState::reset();
        Monkey\tearDown();
        parent::tearDown();
    }

    private function withFacets( bool $pretty ): void {
        $this->options['hof_facets'] = [
            [ 'name' => 'brand', 'label' => 'Brand', 'kind' => 'taxonomy', 'display' => 'checkbox', 'settings' => [] ],
        ];
        $this->options['permalink_structure'] = '/%postname%/';
        $this->options['hof_pretty_urls']     = [ 'enabled' => $pretty, 'base' => 'filter' ];
    }

    /** Same brand facet, but a single-select display — for the radio/dropdown replace-semantics tests. */
    private function withRadioFacet(): void {
        $this->options['hof_facets'] = [
            [ 'name' => 'brand', 'label' => 'Brand', 'kind' => 'taxonomy', 'display' => 'radio', 'settings' => [] ],
        ];
        $this->options['permalink_structure'] = '/%postname%/';
        $this->options['hof_pretty_urls']     = [ 'enabled' => true, 'base' => 'filter' ];
    }

    /** Invoke the private pretty_link() helper directly via reflection. */
    private function prettyLink( array $facet, string $value ): string {
        $method = new \ReflectionMethod( Renderer::class, 'pretty_link' );
        $method->setAccessible( true );
        return (string) $method->invoke( new Renderer( new Resolver() ), $facet, $value );
    }

    /**
     * Invoke the private render_checkbox directly, same pattern as
     * RendererHierarchyTest — no resolver round-trip needed.
     *
     * @param list<string> $selected
     */
    private function renderCheckbox( array $selected ): string {
        $facet  = [ 'name' => 'brand', 'label' => 'Brand', 'kind' => 'taxonomy', 'display' => 'checkbox', 'settings' => [] ];
        $counts = [
            'type'    => 'values',
            'buckets' => [
                [ 'value' => 'adidas', 'display' => 'Adidas', 'count' => 4 ],
                [ 'value' => 'nike', 'display' => 'Nike', 'count' => 6 ],
            ],
        ];

        $method = new \ReflectionMethod( Renderer::class, 'render_checkbox' );
        $method->setAccessible( true );
        return (string) $method->invoke( new Renderer( new Resolver() ), $facet, $selected, $counts );
    }

    public function test_pretty_on_emits_toggle_anchors(): void {
        $this->withFacets( true );
        $html = $this->renderCheckbox( [] );

        self::assertStringContainsString( 'class="hof-facet-link hof-facet-name"', $html );
        self::assertStringContainsString( 'href="https://shop.test/shop/filter/brand/nike/"', $html );
        self::assertStringContainsString( 'href="https://shop.test/shop/filter/brand/adidas/"', $html );
    }

    public function test_pretty_on_selected_value_links_to_removal(): void {
        $this->withFacets( true );
        $_GET['hof'] = [ 'brand' => [ 'nike' ] ]; // pretty_link reads request state
        $html        = $this->renderCheckbox( [ 'nike' ] );

        // Nike is active → its link removes the filter (back to clean base).
        self::assertStringContainsString( 'href="https://shop.test/shop/"', $html );
        // Adidas link adds itself alongside nike (canonical order: adidas first).
        self::assertStringContainsString( 'href="https://shop.test/shop/filter/brand/adidas/brand/nike/"', $html );
    }

    public function test_pretty_off_keeps_plain_spans(): void {
        $this->withFacets( false );
        $html = $this->renderCheckbox( [] );

        self::assertStringNotContainsString( 'hof-facet-link', $html );
        self::assertStringContainsString( '<span class="hof-facet-name">Nike</span>', $html );
    }

    public function test_single_select_link_replaces_value(): void {
        $this->withRadioFacet();
        $_GET['hof'] = [ 'brand' => [ 'adidas' ] ];

        $facet = [ 'name' => 'brand', 'label' => 'Brand', 'kind' => 'taxonomy', 'display' => 'radio', 'settings' => [] ];

        // adidas is already selected; the nike link must REPLACE it, not stack.
        self::assertSame(
            'https://shop.test/shop/filter/brand/nike/',
            $this->prettyLink( $facet, 'nike' )
        );
    }

    public function test_single_select_link_clears_on_selected_value(): void {
        $this->withRadioFacet();
        $_GET['hof'] = [ 'brand' => [ 'nike' ] ];

        $facet = [ 'name' => 'brand', 'label' => 'Brand', 'kind' => 'taxonomy', 'display' => 'radio', 'settings' => [] ];

        // nike is already selected; clicking its own link clears the facet.
        self::assertSame(
            'https://shop.test/shop/',
            $this->prettyLink( $facet, 'nike' )
        );
    }
}
