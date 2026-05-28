<?php
/**
 * @package HookedOnFacets\Tests\Integrations
 */

declare(strict_types=1);

namespace HookedOnFacets\Tests\Integrations;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use HookedOnFacets\Filter\IdResolver;
use HookedOnFacets\Integrations\Divi;
use HookedOnFacets\Telemetry\LoopHookRecorder;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase;

final class DiviTest extends TestCase {
    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();

        // parse_request_filters() transits these WP helpers; pass-through is
        // what production sees for already-clean test inputs.
        Functions\when( 'sanitize_key' )->alias(
            static fn( $s ) => strtolower( preg_replace( '/[^a-z0-9_-]/i', '', (string) $s ) )
        );
        Functions\when( 'sanitize_text_field' )->returnArg();
        Functions\when( 'wp_unslash' )->returnArg();
        // The module's init() / get_fields() run through esc_html__ when the
        // bridge constructs it; pass-through is enough for these assertions.
        Functions\when( 'esc_html__' )->returnArg();

        $_GET = [];
        \ET_Builder_Module::reset();
    }

    protected function tearDown(): void {
        $_GET = [];
        \ET_Builder_Module::reset();
        Monkey\tearDown();
        parent::tearDown();
    }

    // ── register_hooks ───────────────────────────────────────────────────

    public function test_register_hooks_loads_the_query_helper(): void {
        $this->makeBridge()->register_hooks();

        self::assertTrue(
            function_exists( 'hof_divi_query_args' ),
            'register_hooks() must load the global Divi query template tag.'
        );
    }

    public function test_register_hooks_defers_module_registration_to_et_builder_ready(): void {
        $this->makeBridge()->register_hooks();

        self::assertTrue( Actions\has( 'et_builder_ready' ),
            'Module registration must defer to et_builder_ready (passive — only fires when Divi boots).' );
    }

    // ── register_module ─────────────────────────────────────────────────────

    public function test_register_module_registers_facet_module_when_divi_present(): void {
        $this->makeBridge()->register_module();

        self::assertCount( 1, \ET_Builder_Module::$instances,
            'Exactly one Hooked Facet module should self-register.' );

        $module = \ET_Builder_Module::$instances[0];
        self::assertInstanceOf( \HookedOnFacets\Integrations\Divi\FacetModule::class, $module );
        self::assertSame( 'et_pb_hof_facet', $module->slug,
            'Divi requires the et_pb_ slug prefix or the module never registers.' );
        self::assertSame( 'on', $module->vb_support );
        self::assertSame( 'Hooked Facet', $module->name );
    }

    public function test_facet_module_exposes_a_facet_slug_text_field(): void {
        $this->makeBridge()->register_module();

        $module = \ET_Builder_Module::$instances[0];
        $fields = $module->get_fields();

        self::assertArrayHasKey( 'facet_name', $fields );
        self::assertSame( 'text', $fields['facet_name']['type'] );
        self::assertSame( 'main_content', $fields['facet_name']['toggle_slug'] );
    }

    // ── query_args ─────────────────────────────────────────────────────────

    public function test_query_args_returns_base_args_unchanged_when_no_url_filters(): void {
        $resolver = Mockery::mock( IdResolver::class );
        $resolver->shouldNotReceive( 'resolve_ids' );
        $bridge = new Divi( $resolver );

        $base = [ 'post_type' => 'product', 'posts_per_page' => 12 ];
        $args = $bridge->query_args( $base );

        self::assertSame( $base, $args,
            'With no ?hof[*] params the loop must run its own query untouched.' );
    }

    public function test_query_args_returns_base_args_unchanged_when_resolver_returns_null(): void {
        $_GET['hof'] = [ 'unknown_facet' => [ 'red' ] ];

        $resolver = Mockery::mock( IdResolver::class );
        $resolver->shouldReceive( 'resolve_ids' )->once()->andReturn( null );
        $bridge = new Divi( $resolver );

        $base = [ 'post_type' => 'product' ];
        $args = $bridge->query_args( $base );

        self::assertArrayNotHasKey( 'post__in', $args,
            'When no facet is recognized, the loop must be left alone.' );
    }

    public function test_query_args_merges_post__in_and_preserves_base_args(): void {
        $_GET['hof'] = [ 'color' => [ 'red' ] ];

        $resolver = Mockery::mock( IdResolver::class );
        $resolver->shouldReceive( 'resolve_ids' )
            ->once()
            ->with( [ 'color' => [ 'red' ] ] )
            ->andReturn( [ 12, 34, 56 ] );
        $bridge = new Divi( $resolver );

        $args = $bridge->query_args( [ 'post_type' => 'product', 'posts_per_page' => 12 ] );

        self::assertSame( [ 12, 34, 56 ], $args['post__in'] );
        self::assertSame( 'product', $args['post_type'], 'Base args must survive the merge.' );
        self::assertSame( 12, $args['posts_per_page'] );
    }

    public function test_query_args_applies_no_results_sentinel_when_resolver_returns_empty(): void {
        $_GET['hof'] = [ 'color' => [ 'unobtanium' ] ];

        $resolver = Mockery::mock( IdResolver::class );
        $resolver->shouldReceive( 'resolve_ids' )->once()->andReturn( [] );
        $bridge = new Divi( $resolver );

        $args = $bridge->query_args( [ 'post_type' => 'product' ] );

        // Sentinel so the loop emits "no results" instead of every product.
        self::assertSame( [ 0 ], $args['post__in'] );
    }

    public function test_query_args_records_telemetry_when_recorder_supplied(): void {
        $_GET['hof'] = [ 'color' => [ 'red' ] ];

        $resolver = Mockery::mock( IdResolver::class );
        $resolver->shouldReceive( 'resolve_ids' )->andReturn( [ 12 ] );

        $recorder = Mockery::mock( LoopHookRecorder::class );
        $recorder->shouldReceive( 'record_loop_hook' )
            ->once()
            ->with( Mockery::pattern( '/^divi:product$/' ) );

        $bridge = new Divi( $resolver, $recorder );

        $bridge->query_args( [ 'post_type' => 'product' ] );
    }

    public function test_query_args_works_with_empty_base_args(): void {
        $_GET['hof'] = [ 'color' => [ 'red' ] ];

        $resolver = Mockery::mock( IdResolver::class );
        $resolver->shouldReceive( 'resolve_ids' )->once()->andReturn( [ 7 ] );
        $bridge = new Divi( $resolver );

        $args = $bridge->query_args();

        self::assertSame( [ 7 ], $args['post__in'],
            'The helper must work when called with no base args.' );
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function makeBridge( ?IdResolver $resolver = null ): Divi {
        return new Divi(
            $resolver ?? Mockery::mock( IdResolver::class ),
            null,
        );
    }
}
