<?php
/**
 * @package HookedOnFacets\Tests\Integrations
 */

declare(strict_types=1);

namespace HookedOnFacets\Tests\Integrations;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HookedOnFacets\Integrations\MetaBox;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class MetaBoxTest extends TestCase {
    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( 'sanitize_key' )->alias(
            static fn( $s ) => strtolower( preg_replace( '/[^a-z0-9_]/i', '', (string) $s ) )
        );

        // Defining rwmb_meta makes function_exists() true, so is_active() can
        // pass once a registry is fed (rwmb_get_registry, via feedFields).
        Functions\when( 'rwmb_meta' )->justReturn( null );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ── mapping ──────────────────────────────────────────────────────────

    public function test_maps_scalar_field_types_to_expected_displays(): void {
        $this->mockWpdb();
        $this->feedFields( [
            [ 'id' => 'material', 'name' => 'Material', 'type' => 'select' ],
            [ 'id' => 'finish',   'name' => 'Finish',   'type' => 'radio' ],
            [ 'id' => 'on_sale',  'name' => 'On Sale',  'type' => 'switch' ],
            [ 'id' => 'weight',   'name' => 'Weight',   'type' => 'number' ],
            [ 'id' => 'note',     'name' => 'Note',     'type' => 'textarea' ],
            [ 'id' => 'tags',     'name' => 'Tags',     'type' => 'select', 'multiple' => 1 ],
        ] );

        $byName = [];
        foreach ( ( new MetaBox() )->suggest() as $f ) {
            $byName[ $f['name'] ] = $f;
        }

        self::assertSame( 'dropdown', $byName['material']['display'] );
        self::assertSame( 'radio',    $byName['finish']['display'] );
        self::assertSame( 'toggle',   $byName['on_sale']['display'] );
        self::assertSame( 'range',    $byName['weight']['display'] );
        self::assertSame( 'search',   $byName['note']['display'] );
        self::assertSame( 'checkbox', $byName['tags']['display'],
            'A multiple select is a multi-value field → checkbox.' );

        self::assertSame( 'meta',     $byName['material']['kind'] );
        self::assertSame( 'material', $byName['material']['source'],
            'Meta Box keys the meta by the field id.' );
    }

    public function test_maps_date_fields_to_date_range(): void {
        $this->mockWpdb();
        $this->feedFields( [
            [ 'id' => 'published', 'name' => 'Published', 'type' => 'date' ],
            [ 'id' => 'event_at',  'name' => 'Event',     'type' => 'datetime' ],
        ] );

        $byName = [];
        foreach ( ( new MetaBox() )->suggest() as $f ) {
            $byName[ $f['name'] ] = $f;
        }

        self::assertSame( 'date_range', $byName['published']['display'] );
        self::assertSame( 'date_range', $byName['event_at']['display'] );
    }

    public function test_maps_post_and_user_to_resolve_facets(): void {
        $this->mockWpdb();
        $this->feedFields( [
            [ 'id' => 'related', 'name' => 'Related', 'type' => 'post' ],
            [ 'id' => 'owner',   'name' => 'Owner',   'type' => 'user' ],
        ] );

        $byName = [];
        foreach ( ( new MetaBox() )->suggest() as $f ) {
            $byName[ $f['name'] ] = $f;
        }

        self::assertSame( 'post', $byName['related']['settings']['resolve'] );
        self::assertSame( 'user', $byName['owner']['settings']['resolve'] );
        self::assertSame( 'meta', $byName['related']['kind'] );
    }

    public function test_maps_taxonomy_field_to_taxonomy_facet(): void {
        $this->mockWpdb();
        Functions\when( 'taxonomy_exists' )->justReturn( true );
        Functions\when( 'wp_count_terms' )->justReturn( 5 );
        $this->feedFields( [
            [ 'id' => 'genre', 'name' => 'Genre', 'type' => 'taxonomy', 'taxonomy' => 'genre' ],
        ] );

        $facets = ( new MetaBox() )->suggest();

        self::assertCount( 1, $facets );
        self::assertSame( 'taxonomy', $facets[0]['kind'],
            'Meta Box assigns terms to the post, so the taxonomy index path serves it.' );
        self::assertSame( 'genre', $facets[0]['source'], 'Source is the taxonomy slug, not the field id.' );
    }

    public function test_taxonomy_field_accepts_array_of_taxonomies(): void {
        $this->mockWpdb();
        Functions\when( 'taxonomy_exists' )->justReturn( true );
        Functions\when( 'wp_count_terms' )->justReturn( 5 );
        $this->feedFields( [
            [ 'id' => 'genre', 'name' => 'Genre', 'type' => 'taxonomy', 'taxonomy' => [ 'genre', 'mood' ] ],
        ] );

        $facets = ( new MetaBox() )->suggest();

        self::assertSame( 'genre', $facets[0]['source'], 'The first taxonomy binds the facet.' );
    }

    public function test_maps_taxonomy_advanced_to_term_resolve_facet(): void {
        $this->mockWpdb();
        $this->feedFields( [
            [ 'id' => 'cats', 'name' => 'Cats', 'type' => 'taxonomy_advanced', 'taxonomy' => 'cats' ],
        ] );

        $facets = ( new MetaBox() )->suggest();

        self::assertCount( 1, $facets );
        self::assertSame( 'meta', $facets[0]['kind'],
            'taxonomy_advanced stores term IDs in meta (no relationships), so it is a meta facet.' );
        self::assertSame( 'term', $facets[0]['settings']['resolve'] );
        self::assertSame( 'cats', $facets[0]['source'], 'Source is the meta key (field id).' );
    }

    public function test_skips_time_and_structural_types(): void {
        $this->mockWpdb();
        $this->feedFields( [
            [ 'id' => 'opens',   'name' => 'Opens',   'type' => 'time' ],
            [ 'id' => 'gallery', 'name' => 'Gallery', 'type' => 'image_advanced' ],
            [ 'id' => 'body',    'name' => 'Body',    'type' => 'wysiwyg' ],
        ] );

        self::assertSame( [], ( new MetaBox() )->suggest(),
            'time and structural / media types are deferred.' );
    }

    // ── enumeration shape ─────────────────────────────────────────────────

    public function test_reads_fields_from_normalized_fields_property(): void {
        // Newer Meta Box exposes a normalized ->fields rather than ->meta_box['fields'].
        $this->mockWpdb();
        $registry = Mockery::mock();
        $registry->shouldReceive( 'all' )->andReturn( [
            (object) [ 'fields' => [ [ 'id' => 'weight', 'name' => 'Weight', 'type' => 'number' ] ] ],
        ] );
        Functions\when( 'rwmb_get_registry' )->justReturn( $registry );

        $facets = ( new MetaBox() )->suggest();

        self::assertCount( 1, $facets );
        self::assertSame( 'range', $facets[0]['display'] );
    }

    public function test_skips_fields_already_configured(): void {
        $this->mockWpdb();
        $this->feedFields( [
            [ 'id' => 'material', 'name' => 'Material', 'type' => 'select' ],
        ] );

        self::assertSame( [], ( new MetaBox() )->suggest( [ [ 'name' => 'material' ] ] ) );
    }

    public function test_skips_fields_whose_meta_is_not_in_use(): void {
        $this->mockWpdb( false );
        $this->feedFields( [
            [ 'id' => 'weight', 'name' => 'Weight', 'type' => 'number' ],
        ] );

        self::assertSame( [], ( new MetaBox() )->suggest() );
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /**
     * @param array<int, array<string, mixed>> $fields
     */
    private function feedFields( array $fields ): void {
        $registry = Mockery::mock();
        $registry->shouldReceive( 'all' )->andReturn( [
            (object) [ 'meta_box' => [ 'fields' => $fields ] ],
        ] );
        Functions\when( 'rwmb_get_registry' )->justReturn( $registry );
    }

    private function mockWpdb( bool $metaInUse = true ): void {
        $wpdb = Mockery::mock();
        $wpdb->postmeta = 'wp_postmeta';
        $wpdb->shouldReceive( 'prepare' )->andReturnUsing(
            static fn( $query, ...$args ) => $query
        );
        $wpdb->shouldReceive( 'get_var' )->andReturn( $metaInUse ? '1' : null );
        $GLOBALS['wpdb'] = $wpdb;
    }
}
