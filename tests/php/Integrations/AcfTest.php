<?php
/**
 * @package HookedOnFacets\Tests\Integrations
 */

declare(strict_types=1);

namespace HookedOnFacets\Tests\Integrations;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HookedOnFacets\Integrations\Acf;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class AcfTest extends TestCase {
    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( 'sanitize_key' )->alias(
            static fn( $s ) => strtolower( preg_replace( '/[^a-z0-9_]/i', '', (string) $s ) )
        );

        // Defining these via Brain Monkey makes function_exists() true, so
        // is_active() passes — ACF looks "present" for the suggestion logic.
        Functions\when( 'acf_get_field_groups' )->justReturn(
            [ [ 'key' => 'group_1', 'title' => 'Details' ] ]
        );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ── mapping ──────────────────────────────────────────────────────────

    public function test_maps_scalar_field_types_to_expected_displays(): void {
        $this->mockWpdb();
        $this->feedFields( [
            [ 'name' => 'material',  'label' => 'Material',  'type' => 'select' ],
            [ 'name' => 'finish',    'label' => 'Finish',    'type' => 'radio' ],
            [ 'name' => 'on_sale',   'label' => 'On Sale',   'type' => 'true_false' ],
            [ 'name' => 'weight',    'label' => 'Weight',    'type' => 'number' ],
            [ 'name' => 'sku_note',  'label' => 'SKU Note',  'type' => 'text' ],
        ] );

        $facets = ( new Acf() )->suggest();

        $byName = [];
        foreach ( $facets as $f ) {
            $byName[ $f['name'] ] = $f;
        }

        self::assertSame( 'dropdown', $byName['material']['display'] );
        self::assertSame( 'radio',    $byName['finish']['display'] );
        self::assertSame( 'toggle',   $byName['on_sale']['display'] );
        self::assertSame( 'range',    $byName['weight']['display'] );
        self::assertSame( 'search',   $byName['sku_note']['display'] );

        // Every suggestion is a meta-kind facet pointed at the field's name.
        self::assertSame( 'meta', $byName['material']['kind'] );
        self::assertSame( 'material', $byName['material']['source'] );
        self::assertSame( 'Material', $byName['material']['label'] );
    }

    public function test_true_false_carries_toggle_settings(): void {
        $this->mockWpdb();
        $this->feedFields( [
            [ 'name' => 'featured', 'label' => 'Featured', 'type' => 'true_false' ],
        ] );

        $facets = ( new Acf() )->suggest();

        self::assertCount( 1, $facets );
        self::assertSame( '1', $facets[0]['settings']['true_value'] );
        self::assertSame( 'Featured', $facets[0]['settings']['on_label'] );
    }

    public function test_derives_a_label_from_the_name_when_acf_label_missing(): void {
        $this->mockWpdb();
        $this->feedFields( [
            [ 'name' => 'shell_color', 'label' => '', 'type' => 'radio' ],
        ] );

        $facets = ( new Acf() )->suggest();

        self::assertSame( 'Shell Color', $facets[0]['label'] );
    }

    // ── skips ────────────────────────────────────────────────────────────

    public function test_maps_multi_value_string_fields_to_checkbox(): void {
        $this->mockWpdb();
        $this->feedFields( [
            [ 'name' => 'tags_multi', 'label' => 'Tags',   'type' => 'select', 'multiple' => 1 ],
            [ 'name' => 'colors',     'label' => 'Colors', 'type' => 'checkbox' ],
        ] );

        $byName = [];
        foreach ( ( new Acf() )->suggest() as $f ) {
            $byName[ $f['name'] ] = $f;
        }

        // The indexer's serialized-array adapter explodes these into buckets,
        // so a checkbox facet is the right fit.
        self::assertSame( 'checkbox', $byName['tags_multi']['display'] );
        self::assertSame( 'checkbox', $byName['colors']['display'] );
        self::assertSame( 'meta',     $byName['colors']['kind'] );
    }

    public function test_maps_taxonomy_field_with_save_terms_to_taxonomy_facet(): void {
        $this->mockWpdb();
        // Taxonomy-kind data check goes through taxonomy_in_use(), not meta.
        Functions\when( 'taxonomy_exists' )->justReturn( true );
        Functions\when( 'wp_count_terms' )->justReturn( 7 );

        $this->feedFields( [
            [ 'name' => 'genre', 'label' => 'Genre', 'type' => 'taxonomy', 'taxonomy' => 'genre', 'save_terms' => 1 ],
        ] );

        $facets = ( new Acf() )->suggest();

        self::assertCount( 1, $facets );
        self::assertSame( 'taxonomy', $facets[0]['kind'],
            'A Save-Terms taxonomy field is served by the existing taxonomy index path.' );
        self::assertSame( 'genre',    $facets[0]['source'], 'Source is the taxonomy slug, not the meta key.' );
        self::assertSame( 'checkbox', $facets[0]['display'] );
    }

    public function test_maps_relationship_and_post_object_to_post_resolve_facets(): void {
        $this->mockWpdb();
        $this->feedFields( [
            [ 'name' => 'related', 'label' => 'Related', 'type' => 'relationship' ],
            [ 'name' => 'author',  'label' => 'Author',  'type' => 'post_object' ],
        ] );

        $byName = [];
        foreach ( ( new Acf() )->suggest() as $f ) {
            $byName[ $f['name'] ] = $f;
        }

        // post-ID arrays — the indexer resolves IDs → titles at index time.
        self::assertSame( 'meta',     $byName['related']['kind'] );
        self::assertSame( 'checkbox', $byName['related']['display'] );
        self::assertSame( 'post',     $byName['related']['settings']['resolve'] );
        self::assertSame( 'post',     $byName['author']['settings']['resolve'] );
    }

    public function test_maps_user_field_to_user_resolve_facet(): void {
        $this->mockWpdb();
        $this->feedFields( [
            [ 'name' => 'owner', 'label' => 'Owner', 'type' => 'user' ],
        ] );

        $facets = ( new Acf() )->suggest();

        self::assertCount( 1, $facets );
        self::assertSame( 'meta',     $facets[0]['kind'] );
        self::assertSame( 'checkbox', $facets[0]['display'] );
        self::assertSame( 'user',     $facets[0]['settings']['resolve'],
            'A user field is resolved from user IDs to display names at index time.' );
        self::assertSame( 'owner',    $facets[0]['source'] );
    }

    public function test_maps_save_terms_off_taxonomy_to_term_resolve_facet(): void {
        $this->mockWpdb();
        // Save Terms OFF (no save_terms key) — term IDs live in meta only.
        $this->feedFields( [
            [ 'name' => 'cats', 'label' => 'Cats', 'type' => 'taxonomy', 'taxonomy' => 'cats_tax' ],
        ] );

        $facets = ( new Acf() )->suggest();

        self::assertCount( 1, $facets );
        self::assertSame( 'meta',     $facets[0]['kind'],
            'Save-Terms-off stores term IDs in meta, so it is a meta facet, not taxonomy.' );
        self::assertSame( 'checkbox', $facets[0]['display'] );
        self::assertSame( 'term',     $facets[0]['settings']['resolve'] );
        self::assertSame( 'cats',     $facets[0]['source'],
            'Source is the meta key (field name), not the taxonomy slug.' );
    }

    public function test_maps_date_fields_to_date_range(): void {
        $this->mockWpdb();
        $this->feedFields( [
            [ 'name' => 'launch',  'label' => 'Launch',  'type' => 'date_picker' ],
            [ 'name' => 'expires', 'label' => 'Expires', 'type' => 'date_time_picker' ],
        ] );

        $byName = [];
        foreach ( ( new Acf() )->suggest() as $f ) {
            $byName[ $f['name'] ] = $f;
        }

        self::assertSame( 'date_range', $byName['launch']['display'] );
        self::assertSame( 'date_range', $byName['expires']['display'] );
        self::assertSame( 'meta',       $byName['launch']['kind'] );
        self::assertSame( 'launch',     $byName['launch']['source'] );
    }

    public function test_skips_time_and_structural_types(): void {
        $this->mockWpdb();
        $this->feedFields( [
            // time_picker is a time-of-day, not a calendar date.
            [ 'name' => 'opens', 'label' => 'Opens', 'type' => 'time_picker' ],
            [ 'name' => 'specs', 'label' => 'Specs', 'type' => 'repeater' ],
            [ 'name' => 'hero',  'label' => 'Hero',  'type' => 'image' ],
        ] );

        self::assertSame( [], ( new Acf() )->suggest(),
            'time_picker and structural ACF types are deferred.' );
    }

    public function test_skips_fields_already_configured(): void {
        $this->mockWpdb();
        $this->feedFields( [
            [ 'name' => 'material', 'label' => 'Material', 'type' => 'select' ],
        ] );

        $existing = [ [ 'name' => 'material' ] ];
        self::assertSame( [], ( new Acf() )->suggest( $existing ),
            'A field already covered by a configured facet must not be re-suggested.' );
    }

    public function test_skips_fields_whose_meta_is_not_in_use(): void {
        $this->mockWpdb( false ); // no post carries the meta
        $this->feedFields( [
            [ 'name' => 'material', 'label' => 'Material', 'type' => 'select' ],
        ] );

        self::assertSame( [], ( new Acf() )->suggest(),
            'Fields with no stored values must not be suggested.' );
    }

    public function test_dedupes_a_field_shared_across_groups(): void {
        $this->mockWpdb();
        // Same field name returned for whatever group key is passed.
        $this->feedFields( [
            [ 'name' => 'material', 'label' => 'Material', 'type' => 'select' ],
        ] );
        Functions\when( 'acf_get_field_groups' )->justReturn( [
            [ 'key' => 'group_1' ],
            [ 'key' => 'group_2' ],
        ] );

        $facets = ( new Acf() )->suggest();

        self::assertCount( 1, $facets, 'A field appearing in two groups should be suggested once.' );
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /**
     * @param array<int, array<string, mixed>> $fields
     */
    private function feedFields( array $fields ): void {
        Functions\when( 'acf_get_fields' )->justReturn( $fields );
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
