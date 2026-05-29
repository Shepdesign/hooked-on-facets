<?php
/**
 * @package HookedOnFacets\Tests\Integrations
 */

declare(strict_types=1);

namespace HookedOnFacets\Tests\Integrations;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HookedOnFacets\Integrations\Pods;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class PodsTest extends TestCase {
    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        Functions\when( 'sanitize_key' )->alias(
            static fn( $s ) => strtolower( preg_replace( '/[^a-z0-9_]/i', '', (string) $s ) )
        );

        // Defining pods makes function_exists() true; pods_api is fed per-test.
        Functions\when( 'pods' )->justReturn( null );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ── mapping ──────────────────────────────────────────────────────────

    public function test_maps_scalar_field_types_to_expected_displays(): void {
        $this->mockWpdb();
        $this->feedFields( [
            'blurb'   => [ 'label' => 'Blurb',   'type' => 'paragraph' ],
            'price'   => [ 'label' => 'Price',   'type' => 'currency' ],
            'in_demo' => [ 'label' => 'In Demo', 'type' => 'boolean' ],
            'site'    => [ 'label' => 'Site',    'type' => 'website' ],
        ] );

        $byName = [];
        foreach ( ( new Pods() )->suggest() as $f ) {
            $byName[ $f['name'] ] = $f;
        }

        self::assertSame( 'search', $byName['blurb']['display'] );
        self::assertSame( 'range',  $byName['price']['display'] );
        self::assertSame( 'toggle', $byName['in_demo']['display'] );
        self::assertSame( 'search', $byName['site']['display'] );

        self::assertSame( 'meta',  $byName['blurb']['kind'] );
        self::assertSame( 'blurb', $byName['blurb']['source'] );
    }

    public function test_maps_date_fields_to_date_range(): void {
        $this->mockWpdb();
        $this->feedFields( [
            'released' => [ 'label' => 'Released', 'type' => 'date' ],
            'starts'   => [ 'label' => 'Starts',   'type' => 'datetime' ],
        ] );

        $byName = [];
        foreach ( ( new Pods() )->suggest() as $f ) {
            $byName[ $f['name'] ] = $f;
        }

        self::assertSame( 'date_range', $byName['released']['display'] );
        self::assertSame( 'date_range', $byName['starts']['display'] );
    }

    public function test_maps_pick_relationships_by_object_to_resolve_kinds(): void {
        $this->mockWpdb();
        $this->feedFields( [
            'related' => [ 'label' => 'Related', 'type' => 'pick', 'pick_object' => 'post_type' ],
            'owner'   => [ 'label' => 'Owner',   'type' => 'pick', 'pick_object' => 'user' ],
            'topics'  => [ 'label' => 'Topics',  'type' => 'pick', 'pick_object' => 'taxonomy' ],
        ] );

        $byName = [];
        foreach ( ( new Pods() )->suggest() as $f ) {
            $byName[ $f['name'] ] = $f;
        }

        self::assertSame( 'post', $byName['related']['settings']['resolve'] );
        self::assertSame( 'user', $byName['owner']['settings']['resolve'] );
        self::assertSame( 'term', $byName['topics']['settings']['resolve'] );
        self::assertSame( 'meta', $byName['related']['kind'] );
    }

    public function test_reads_pick_object_from_options_array(): void {
        // Some Pods versions nest the pick target under 'options'.
        $this->mockWpdb();
        $this->feedFields( [
            'author' => [ 'label' => 'Author', 'type' => 'pick', 'options' => [ 'pick_object' => 'user' ] ],
        ] );

        $facets = ( new Pods() )->suggest();

        self::assertSame( 'user', $facets[0]['settings']['resolve'] );
    }

    public function test_custom_simple_pick_is_a_plain_checkbox(): void {
        $this->mockWpdb();
        $this->feedFields( [
            'size' => [ 'label' => 'Size', 'type' => 'pick', 'pick_object' => 'custom-simple' ],
        ] );

        $facets = ( new Pods() )->suggest();

        self::assertCount( 1, $facets );
        self::assertSame( 'checkbox', $facets[0]['display'] );
        self::assertSame( [], $facets[0]['settings'], 'A custom list stores its values directly — no ID resolution.' );
    }

    public function test_skips_unsupported_pick_targets_and_structural_types(): void {
        $this->mockWpdb();
        $this->feedFields( [
            'commenter' => [ 'label' => 'Commenter', 'type' => 'pick', 'pick_object' => 'comment' ],
            'doc'       => [ 'label' => 'Doc',       'type' => 'file' ],
            'body'      => [ 'label' => 'Body',      'type' => 'wysiwyg' ],
            'swatch'    => [ 'label' => 'Swatch',    'type' => 'color' ],
        ] );

        self::assertSame( [], ( new Pods() )->suggest(),
            'Non-post/user/taxonomy relationships and structural / media types are deferred.' );
    }

    public function test_skips_fields_already_configured(): void {
        $this->mockWpdb();
        $this->feedFields( [
            'price' => [ 'label' => 'Price', 'type' => 'number' ],
        ] );

        self::assertSame( [], ( new Pods() )->suggest( [ [ 'name' => 'price' ] ] ) );
    }

    public function test_skips_fields_whose_meta_is_not_in_use(): void {
        $this->mockWpdb( false );
        $this->feedFields( [
            'price' => [ 'label' => 'Price', 'type' => 'number' ],
        ] );

        self::assertSame( [], ( new Pods() )->suggest() );
    }

    // ── helpers ────────────────────────────────────────────────────────────

    /**
     * Feed a single post-type Pod whose `fields` map is keyed by field name.
     *
     * @param array<string, array<string, mixed>> $fields
     */
    private function feedFields( array $fields ): void {
        $api = Mockery::mock();
        $api->shouldReceive( 'load_pods' )->andReturn( [
            [ 'name' => 'book', 'fields' => $fields ],
        ] );
        Functions\when( 'pods_api' )->justReturn( $api );
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
