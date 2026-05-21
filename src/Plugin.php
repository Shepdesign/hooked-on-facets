<?php
/**
 * Plugin — lightweight service container and boot orchestrator.
 *
 * @package HookedOnFacets
 */

declare(strict_types=1);

namespace HookedOnFacets;

use HookedOnFacets\Contracts\Bootable;

defined( 'ABSPATH' ) || exit;

/**
 * Singleton service locator.
 *
 * Why not a full PSR-11 container: at this plugin's scale it's overkill. We
 * need lazy resolution, swap-for-tests, and a guarantee that Bootable services
 * have their hooks registered exactly once. This delivers that in ~80 lines.
 */
final class Plugin {

    private static ?self $instance = null;

    /** @var array<class-string, class-string|callable> */
    private array $bindings = [];

    /** @var array<class-string, object> */
    private array $instances = [];

    private bool $booted = false;

    private function __construct() {}

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    /**
     * Bind an abstract → concrete (class name or factory closure).
     *
     * Test usage:
     *   Plugin::instance()->bind( Indexer::class, fn() => new FakeIndexer() );
     *
     * Re-binding clears any cached instance for the abstract.
     *
     * @param class-string         $abstract
     * @param class-string|callable $concrete
     */
    public function bind( string $abstract, string|callable $concrete ): void {
        $this->bindings[ $abstract ] = $concrete;
        unset( $this->instances[ $abstract ] );
    }

    /**
     * Resolve a service. Cached after first construction.
     *
     * @template T of object
     * @param class-string<T> $abstract
     * @return T
     */
    public function make( string $abstract ): object {
        if ( isset( $this->instances[ $abstract ] ) ) {
            /** @var T */
            return $this->instances[ $abstract ];
        }

        $concrete = $this->bindings[ $abstract ] ?? $abstract;
        $instance = is_callable( $concrete ) ? $concrete( $this ) : new $concrete();

        /** @var T $instance */
        $this->instances[ $abstract ] = $instance;
        return $instance;
    }

    /**
     * Instantiate every core service and register hooks for the Bootable ones.
     */
    public function boot(): void {
        if ( $this->booted ) {
            return;
        }

        $this->register_bindings();

        foreach ( $this->core_services() as $abstract ) {
            $service = $this->make( $abstract );
            if ( $service instanceof Bootable ) {
                $service->register_hooks();
            }
        }

        $this->booted = true;

        /**
         * Fires after HOF has registered its core hooks.
         *
         * @param Plugin $plugin
         */
        do_action( 'hof_booted', $this );
    }

    public function booted(): bool {
        return $this->booted;
    }

    /**
     * Wire up bindings for services that need constructor injection.
     *
     * Services with zero-arg constructors (Indexer, Resolver) auto-resolve and
     * need no entry here. Anything taking dependencies gets a closure that
     * wires those deps via the container.
     */
    private function register_bindings(): void {
        $this->bind( \HookedOnFacets\Filter\Resolver::class, static fn( self $c ) => new \HookedOnFacets\Filter\Resolver(
            $c->make( \HookedOnFacets\Telemetry\Recorder::class )
        ) );

        $this->bind( QueryHook::class, static fn( self $c ) => new QueryHook(
            $c->make( \HookedOnFacets\Filter\Resolver::class ),
            $c->make( \HookedOnFacets\Telemetry\Recorder::class )
        ) );

        $this->bind( \HookedOnFacets\Ai\Settings::class, static fn() => new \HookedOnFacets\Ai\Settings() );

        $this->bind( \HookedOnFacets\Ai\NlFilter::class, static fn( self $c ) => new \HookedOnFacets\Ai\NlFilter(
            $c->make( \HookedOnFacets\Filter\Resolver::class ),
            $c->make( \HookedOnFacets\Ai\Settings::class ),
        ) );

        $this->bind( \HookedOnFacets\Integrations\WooCommerce::class, static fn() => new \HookedOnFacets\Integrations\WooCommerce() );

        $this->bind( \HookedOnFacets\Licensing\LicenseClient::class, static fn() => new \HookedOnFacets\Licensing\LicenseClient() );

        $this->bind( \HookedOnFacets\Licensing\LicenseManager::class, static fn( self $c ) => new \HookedOnFacets\Licensing\LicenseManager(
            $c->make( \HookedOnFacets\Licensing\LicenseClient::class ),
        ) );

        $this->bind( \HookedOnFacets\Licensing\Updater::class, static fn( self $c ) => new \HookedOnFacets\Licensing\Updater(
            $c->make( \HookedOnFacets\Licensing\LicenseClient::class ),
        ) );

        $this->bind( \HookedOnFacets\Admin\LicenseNotice::class, static fn( self $c ) => new \HookedOnFacets\Admin\LicenseNotice(
            $c->make( \HookedOnFacets\Licensing\LicenseManager::class ),
        ) );

        $this->bind( \HookedOnFacets\Api\RestController::class, static fn( self $c ) => new \HookedOnFacets\Api\RestController(
            $c->make( \HookedOnFacets\Filter\Resolver::class ),
            $c->make( Indexer::class ),
            $c->make( \HookedOnFacets\Telemetry\Recorder::class ),
            $c->make( \HookedOnFacets\Ai\NlFilter::class ),
            $c->make( \HookedOnFacets\Ai\Settings::class ),
            $c->make( \HookedOnFacets\Licensing\LicenseManager::class ),
            $c->make( \HookedOnFacets\Integrations\WooCommerce::class ),
        ) );

        $this->bind( \HookedOnFacets\Facets\Renderer::class, static fn( self $c ) => new \HookedOnFacets\Facets\Renderer(
            $c->make( \HookedOnFacets\Filter\Resolver::class )
        ) );

        $this->bind( \HookedOnFacets\Frontend\Shortcodes::class, static fn( self $c ) => new \HookedOnFacets\Frontend\Shortcodes(
            $c->make( \HookedOnFacets\Facets\Renderer::class )
        ) );

        $this->bind( \HookedOnFacets\Frontend\BlockRegistrar::class, static fn( self $c ) => new \HookedOnFacets\Frontend\BlockRegistrar(
            $c->make( \HookedOnFacets\Facets\Renderer::class )
        ) );
    }

    /**
     * Services boot() will instantiate (and hook in, if Bootable).
     *
     * Append here as services land. Order doesn't matter for correctness —
     * WordPress hook priority handles ordering at fire time.
     *
     * @return array<int, class-string>
     */
    private function core_services(): array {
        return [
            \HookedOnFacets\Telemetry\Recorder::class,
            Indexer::class,
            QueryHook::class,
            \HookedOnFacets\Licensing\LicenseManager::class,
            \HookedOnFacets\Licensing\Updater::class,
            \HookedOnFacets\Api\RestController::class,
            \HookedOnFacets\Admin\MenuRegistrar::class,
            \HookedOnFacets\Admin\SwatchTermFields::class,
            \HookedOnFacets\Admin\LicenseNotice::class,
            \HookedOnFacets\Frontend\AssetLoader::class,
            \HookedOnFacets\Frontend\Shortcodes::class,
            \HookedOnFacets\Frontend\BlockRegistrar::class,
            \HookedOnFacets\Cli\Commands::class,
        ];
    }
}
