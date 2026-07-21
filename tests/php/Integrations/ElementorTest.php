<?php
/**
 * @package HookedOnFacets\Tests\Integrations
 */

declare(strict_types=1);

namespace HookedOnFacets\Tests\Integrations;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use HookedOnFacets\Filter\IdResolver;
use HookedOnFacets\Integrations\Elementor;
use HookedOnFacets\Telemetry\LoopHookRecorder;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;
use WP_Query;

final class ElementorTest extends TestCase {
    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // The bridge transits through these WP helpers when reading
        // ?hof[*]. Pass-through behavior is what production sees too for
        // already-clean test inputs.
        Functions\when( 'sanitize_key' )->alias(
            static fn( $s ) => strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) $s ) )
        );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'get_option' )->justReturn( false );
        \HookedOnFacets\Routing\FilterState::reset();

        $_GET = [];
    }

    protected function tearDown(): void {
        $_GET = [];
        \HookedOnFacets\Routing\FilterState::reset();
        Monkey\tearDown();
        // MockeryPHPUnitIntegration closes Mockery and asserts expectations.
        parent::tearDown();
    }

    // ── register_hooks ───────────────────────────────────────────────────

    public function test_register_hooks_no_ops_when_elementor_absent(): void {
        Functions\when( 'did_action' )->justReturn( 0 );

        $this->makeBridge()->register_hooks();

        self::assertFalse( Actions\has( 'elementor/widgets/register' ) );
        self::assertFalse( Actions\has( 'elementor/query/hof' ) );
    }

    public function test_register_hooks_adds_widget_and_default_query_hooks_when_elementor_loaded(): void {
        Functions\when( 'did_action' )->justReturn( 1 );

        $this->makeBridge()->register_hooks();

        self::assertTrue( Actions\has( 'elementor/widgets/register' ) );
        self::assertTrue( Actions\has( 'elementor/query/hof' ) );
    }

    public function test_query_id_list_is_filterable(): void {
        Functions\when( 'did_action' )->justReturn( 1 );
        Filters\expectApplied( 'hof_elementor_query_ids' )
            ->once()
            ->with( [ 'hof' ] )
            ->andReturn( [ 'shop', 'category', 'shop' ] ); // intentional dupe

        $this->makeBridge()->register_hooks();

        self::assertTrue(  Actions\has( 'elementor/query/shop' ) );
        self::assertTrue(  Actions\has( 'elementor/query/category' ) );
        self::assertFalse( Actions\has( 'elementor/query/hof' ),
            'When the filter overrides the list, the default ID should drop out.' );
    }

    public function test_query_id_list_drops_empty_and_invalid_entries(): void {
        Functions\when( 'did_action' )->justReturn( 1 );
        Filters\expectApplied( 'hof_elementor_query_ids' )
            ->andReturn( [ '', '   ', 'valid_id', '!!!' ] );

        $this->makeBridge()->register_hooks();

        self::assertTrue(  Actions\has( 'elementor/query/valid_id' ) );
        self::assertFalse( Actions\has( 'elementor/query/' ) );
    }

    // ── bind_query ───────────────────────────────────────────────────────

    public function test_bind_query_no_ops_when_no_url_filters_present(): void {
        $resolver = Mockery::mock( IdResolver::class );
        $resolver->shouldNotReceive( 'resolve_ids' );
        $bridge = new Elementor( $resolver );

        $query = new WP_Query();
        $bridge->bind_query( $query );

        self::assertSame( [], $query->query_vars,
            'A request with no ?hof[*] params must leave the query untouched.' );
    }

    public function test_bind_query_no_ops_when_resolver_returns_null(): void {
        $_GET['hof'] = [ 'unknown_facet' => [ 'red' ] ];

        $resolver = Mockery::mock( IdResolver::class );
        $resolver->shouldReceive( 'resolve_ids' )->once()->andReturn( null );
        $bridge = new Elementor( $resolver );

        $query = new WP_Query();
        $bridge->bind_query( $query );

        self::assertArrayNotHasKey( 'post__in', $query->query_vars,
            'When the resolver cannot recognize any facet, the query must be left alone.' );
    }

    public function test_bind_query_applies_post__in_from_resolved_ids(): void {
        $_GET['hof'] = [ 'color' => [ 'red' ] ];

        $resolver = Mockery::mock( IdResolver::class );
        $resolver->shouldReceive( 'resolve_ids' )
            ->once()
            ->with( [ 'color' => [ 'red' ] ] )
            ->andReturn( [ 12, 34, 56 ] );
        $bridge = new Elementor( $resolver );

        $query = new WP_Query();
        $bridge->bind_query( $query );

        self::assertSame( [ 12, 34, 56 ], $query->query_vars['post__in'] );
        self::assertSame( [ 'color' => [ 'red' ] ], $query->query_vars['hof_applied_state'] );
    }

    public function test_bind_query_applies_no_results_sentinel_when_resolver_returns_empty(): void {
        $_GET['hof'] = [ 'color' => [ 'unobtanium' ] ];

        $resolver = Mockery::mock( IdResolver::class );
        $resolver->shouldReceive( 'resolve_ids' )->once()->andReturn( [] );
        $bridge = new Elementor( $resolver );

        $query = new WP_Query();
        $bridge->bind_query( $query );

        // Sentinel matches QueryHook's behavior so the loop emits "no results"
        // instead of falling through to an unbounded query.
        self::assertSame( [ 0 ], $query->query_vars['post__in'] );
    }

    public function test_bind_query_records_telemetry_when_recorder_supplied(): void {
        $_GET['hof'] = [ 'color' => [ 'red' ] ];

        $resolver = Mockery::mock( IdResolver::class );
        $resolver->shouldReceive( 'resolve_ids' )->andReturn( [ 12 ] );

        $recorder = Mockery::mock( LoopHookRecorder::class );
        $recorder->shouldReceive( 'record_loop_hook' )
            ->once()
            ->with( Mockery::pattern( '/^elementor:/' ) );

        $bridge = new Elementor( $resolver, $recorder );

        $query = new WP_Query();
        $query->set( 'post_type', 'product' );
        $bridge->bind_query( $query );
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function makeBridge( ?IdResolver $resolver = null ): Elementor {
        return new Elementor(
            $resolver ?? Mockery::mock( IdResolver::class ),
            null,
        );
    }
}
