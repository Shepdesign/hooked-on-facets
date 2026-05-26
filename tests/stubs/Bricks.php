<?php
/**
 * Minimal \Bricks\Elements surface — only the static register_element()
 * entry point the Bricks bridge calls. Records each registration so tests
 * can assert what the bridge handed Bricks.
 *
 * Lives outside tests/php/ on purpose: composer's autoload-dev PSR-4 rule
 * scans tests/php/ for namespaced classes, and the Bricks namespace
 * declared there would trip the "doesn't comply with PSR-4" warning.
 *
 * Real Bricks installs a far heavier class; the bridge only ever calls
 * register_element(), so that's all the stub provides.
 */

declare(strict_types=1);

namespace Bricks;

if ( class_exists( \Bricks\Elements::class ) ) {
    return;
}

/**
 * @internal Test-only stub. Not present in production runtime.
 */
class Elements {

    /**
     * Captured register_element() calls: [ file, name, class ].
     *
     * @var array<int, array{0:string,1:?string,2:?string}>
     */
    public static array $registered = [];

    public static function register_element( string $file, ?string $name = null, ?string $class = null ): void {
        self::$registered[] = [ $file, $name, $class ];
    }

    public static function reset(): void {
        self::$registered = [];
    }
}
