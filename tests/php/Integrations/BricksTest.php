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
use HookedOnFacets\Integrations\Bricks;
use HookedOnFacets\Telemetry\LoopHookRecorder;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class BricksTest extends TestCase {
    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // The bridge transits these WP helpers when reading ?hof[*] and when
        // matching CSS-class tokens. Pass-through is what production sees for
        // already-clean test inputs.
        Functions\when( 'sanitize_key' )->alias(
            static fn( $s ) => strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) $s ) )
        );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'get_option' )->justReturn( false );
        \HookedOnFacets\Routing\FilterState::reset();

        $_GET = [];
        \Bricks\Elements::reset();
    }

    protected function tearDown(): void {
        $_GET = [];
        \Bricks\Elements::reset();
        \HookedOnFacets\Routing\FilterState::reset();
        Monkey\tearDown();
        // MockeryPHPUnitIntegration closes Mockery and asserts expectations.
        parent::tearDown();
    }

    // ── register_hooks ───────────────────────────────────────────────────

    public function test_register_hooks_adds_passive_query_filter_and_element_init_hook(): void {
        $this->makeBridge()->register_hooks();

        self::assertTrue( Filters\has( 'bricks/posts/query_vars' ),
            'The query binding is a passive filter and must register at boot.' );
        self::assertTrue( Actions\has( 'init' ),
            'Element registration must defer to init, after the Bricks theme loads.' );
    }

    // ── register_element ───────────────────────────────────────────────────

    public function test_register_element_registers_facet_element_when_bricks_present(): void {
        $this->makeBridge()->register_element();

        self::assertCount( 1, \Bricks\Elements::$registered );

        [ $file, $name, $class ] = \Bricks\Elements::$registered[0];
        self::assertStringEndsWith( 'integrations/bricks/elements/facet.php', $file );
        self::assertSame( 'hof-facet', $name );
        self::assertSame( \HookedOnFacets\Integrations\Bricks\FacetElement::class, $class );
    }

    // ── bind_query_vars ─────────────────────────────────────────────────────

    public function test_bind_query_vars_no_ops_when_element_not_tagged(): void {
        $_GET['hof'] = [ 'color' => [ 'red' ] ];

        $resolver = Mockery::mock( IdResolver::class );
        $resolver->shouldNotReceive( 'resolve_ids' );
        $bridge = new Bricks( $resolver );

        $vars = $bridge->bind_query_vars( [ 'post_type' => 'product' ], [ '_cssClasses' => 'card shadow' ], 'abc123' );

        self::assertArrayNotHasKey( 'post__in', $vars,
            'A loop without a binding class must be left untouched, even with filters present.' );
    }

    public function test_bind_query_vars_no_ops_when_no_url_filters_present(): void {
        $resolver = Mockery::mock( IdResolver::class );
        $resolver->shouldNotReceive( 'resolve_ids' );
        $bridge = new Bricks( $resolver );

        $vars = $bridge->bind_query_vars( [ 'post_type' => 'product' ], [ '_cssClasses' => 'hof' ], 'abc123' );

        self::assertArrayNotHasKey( 'post__in', $vars );
    }

    public function test_bind_query_vars_no_ops_when_resolver_returns_null(): void {
        $_GET['hof'] = [ 'unknown_facet' => [ 'red' ] ];

        $resolver = Mockery::mock( IdResolver::class );
        $resolver->shouldReceive( 'resolve_ids' )->once()->andReturn( null );
        $bridge = new Bricks( $resolver );

        $vars = $bridge->bind_query_vars( [], [ '_cssClasses' => 'hof' ], 'abc123' );

        self::assertArrayNotHasKey( 'post__in', $vars,
            'When the resolver recognizes no facet, the loop must be left alone.' );
    }

    public function test_bind_query_vars_applies_post__in_from_resolved_ids(): void {
        $_GET['hof'] = [ 'color' => [ 'red' ] ];

        $resolver = Mockery::mock( IdResolver::class );
        $resolver->shouldReceive( 'resolve_ids' )
            ->once()
            ->with( [ 'color' => [ 'red' ] ] )
            ->andReturn( [ 12, 34, 56 ] );
        $bridge = new Bricks( $resolver );

        $vars = $bridge->bind_query_vars( [], [ '_cssClasses' => 'hof' ], 'abc123' );

        self::assertSame( [ 12, 34, 56 ], $vars['post__in'] );
    }

    public function test_bind_query_vars_matches_binding_class_among_several(): void {
        $_GET['hof'] = [ 'color' => [ 'red' ] ];

        $resolver = Mockery::mock( IdResolver::class );
        $resolver->shouldReceive( 'resolve_ids' )->once()->andReturn( [ 7 ] );
        $bridge = new Bricks( $resolver );

        $vars = $bridge->bind_query_vars( [], [ '_cssClasses' => '  card   hof shadow ' ], 'abc123' );

        self::assertSame( [ 7 ], $vars['post__in'],
            'The binding class can sit anywhere in a multi-class string.' );
    }

    public function test_bind_query_vars_tolerates_array_css_classes(): void {
        $_GET['hof'] = [ 'color' => [ 'red' ] ];

        $resolver = Mockery::mock( IdResolver::class );
        $resolver->shouldReceive( 'resolve_ids' )->once()->andReturn( [ 5 ] );
        $bridge = new Bricks( $resolver );

        // Some setups hand the field over as an array rather than a string.
        $vars = $bridge->bind_query_vars( [], [ '_cssClasses' => [ 'card', 'hof' ] ], 'abc123' );

        self::assertSame( [ 5 ], $vars['post__in'] );
    }

    public function test_bind_query_vars_applies_no_results_sentinel_when_resolver_returns_empty(): void {
        $_GET['hof'] = [ 'color' => [ 'unobtanium' ] ];

        $resolver = Mockery::mock( IdResolver::class );
        $resolver->shouldReceive( 'resolve_ids' )->once()->andReturn( [] );
        $bridge = new Bricks( $resolver );

        $vars = $bridge->bind_query_vars( [], [ '_cssClasses' => 'hof' ], 'abc123' );

        // Sentinel matches QueryHook / Elementor so the loop emits "no results"
        // instead of falling through to an unbounded query.
        self::assertSame( [ 0 ], $vars['post__in'] );
    }

    public function test_bind_query_vars_records_telemetry_when_recorder_supplied(): void {
        $_GET['hof'] = [ 'color' => [ 'red' ] ];

        $resolver = Mockery::mock( IdResolver::class );
        $resolver->shouldReceive( 'resolve_ids' )->andReturn( [ 12 ] );

        $recorder = Mockery::mock( LoopHookRecorder::class );
        $recorder->shouldReceive( 'record_loop_hook' )
            ->once()
            ->with( Mockery::pattern( '/^bricks:product$/' ) );

        $bridge = new Bricks( $resolver, $recorder );

        $bridge->bind_query_vars( [ 'post_type' => 'product' ], [ '_cssClasses' => 'hof' ], 'abc123' );
    }

    public function test_binding_class_list_is_filterable(): void {
        $_GET['hof'] = [ 'color' => [ 'red' ] ];

        Filters\expectApplied( 'hof_bricks_query_ids' )
            ->once()
            ->with( [ 'hof' ] )
            ->andReturn( [ 'shop' ] );

        $resolver = Mockery::mock( IdResolver::class );
        $resolver->shouldReceive( 'resolve_ids' )->once()->andReturn( [ 99 ] );
        $bridge = new Bricks( $resolver );

        // The default class ('hof') no longer binds; the filtered one ('shop') does.
        $vars = $bridge->bind_query_vars( [], [ '_cssClasses' => 'shop' ], 'abc123' );

        self::assertSame( [ 99 ], $vars['post__in'] );
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function makeBridge( ?IdResolver $resolver = null ): Bricks {
        return new Bricks(
            $resolver ?? Mockery::mock( IdResolver::class ),
            null,
        );
    }
}
