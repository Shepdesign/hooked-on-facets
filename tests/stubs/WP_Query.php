<?php
/**
 * Minimal WP_Query surface — only the get/set query-var pair the
 * QueryHook + Elementor bridge actually touch in unit tests.
 *
 * Lives outside tests/php/ on purpose: composer's autoload-dev PSR-4 rule
 * scans tests/php/ for namespaced classes, and a global class declared
 * there would (correctly) trip the "doesn't comply with PSR-4" warning.
 *
 * Real WordPress installs a far heavier class; we don't need its parser,
 * its DB code, or its loop machinery for unit tests.
 */

declare(strict_types=1);

if ( class_exists( 'WP_Query' ) ) {
    return;
}

/**
 * @internal Test-only stub. Not present in production runtime.
 */
class WP_Query {

    /** @var array<string, mixed> */
    public array $query_vars = [];

    public function set( string $key, mixed $value ): void {
        $this->query_vars[ $key ] = $value;
    }

    public function get( string $key, mixed $default = '' ): mixed {
        return $this->query_vars[ $key ] ?? $default;
    }
}
