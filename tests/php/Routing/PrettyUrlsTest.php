<?php
/**
 * @package HookedOnFacets\Tests
 */

declare(strict_types=1);

namespace HookedOnFacets\Tests\Routing;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HookedOnFacets\Routing\PrettyUrls;
use PHPUnit\Framework\TestCase;

/**
 * Covers the hof_pretty_urls option accessor: defaults, sanitized updates,
 * the deferred-flush flag, and the plain-permalink availability guard.
 */
final class PrettyUrlsTest extends TestCase {

    /** @var array<string, mixed> In-memory option store for the stubs. */
    private array $options = [];

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->options = [];
        Functions\when( 'get_option' )->alias( fn( $name, $default = false ) => $this->options[ $name ] ?? $default );
        Functions\when( 'update_option' )->alias( function ( $name, $value ) {
            $this->options[ $name ] = $value;
            return true;
        } );
        Functions\when( 'sanitize_title' )->alias(
            static fn( $t ) => trim( preg_replace( '/[^a-z0-9-]+/', '-', strtolower( (string) $t ) ), '-' )
        );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_defaults_disabled_with_filter_base(): void {
        $s = PrettyUrls::settings();
        self::assertFalse( $s['enabled'] );
        self::assertSame( 'filter', $s['base'] );
    }

    public function test_enabled_requires_option_and_permalinks(): void {
        $this->options['permalink_structure'] = ''; // plain permalinks
        $this->options[ PrettyUrls::OPTION ]  = [ 'enabled' => true, 'base' => 'filter' ];
        self::assertFalse( PrettyUrls::enabled() );

        $this->options['permalink_structure'] = '/%postname%/';
        self::assertTrue( PrettyUrls::enabled() );
    }

    public function test_available_reflects_permalink_structure(): void {
        $this->options['permalink_structure'] = '';
        self::assertFalse( PrettyUrls::available() );
        $this->options['permalink_structure'] = '/%postname%/';
        self::assertTrue( PrettyUrls::available() );
    }

    public function test_update_sanitizes_base_and_sets_flush_flag(): void {
        PrettyUrls::update( [ 'enabled' => true, 'base' => 'My Filter!!' ] );

        self::assertSame(
            [ 'enabled' => true, 'base' => 'my-filter' ],
            $this->options[ PrettyUrls::OPTION ]
        );
        self::assertSame( 1, $this->options[ PrettyUrls::FLUSH_FLAG ] );
    }

    public function test_update_empty_base_falls_back_to_filter(): void {
        PrettyUrls::update( [ 'enabled' => true, 'base' => '!!!' ] );
        self::assertSame( 'filter', $this->options[ PrettyUrls::OPTION ]['base'] );
    }

    public function test_update_without_change_does_not_set_flush_flag(): void {
        $this->options[ PrettyUrls::OPTION ] = [ 'enabled' => false, 'base' => 'filter' ];
        PrettyUrls::update( [ 'enabled' => false, 'base' => 'filter' ] );
        self::assertArrayNotHasKey( PrettyUrls::FLUSH_FLAG, $this->options );
    }
}
