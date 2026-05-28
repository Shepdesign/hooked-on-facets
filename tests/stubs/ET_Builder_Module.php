<?php
/**
 * Minimal \ET_Builder_Module surface — just enough for the Divi bridge's
 * module registration to construct a subclass without a live Divi builder.
 *
 * Real Divi calls init() from the base constructor as part of self-
 * registration, so the stub does the same and records each constructed
 * instance for tests to assert against.
 *
 * Lives outside tests/php/ on purpose: composer's autoload-dev PSR-4 rule
 * scans tests/php/, and a global-namespace class declared there would trip
 * the "doesn't comply with PSR-4" warning.
 *
 * @internal Test-only stub. Not present in production runtime.
 */

declare(strict_types=1);

if ( class_exists( 'ET_Builder_Module' ) ) {
    return;
}

class ET_Builder_Module {

    /**
     * Every instance constructed since the last reset().
     *
     * @var array<int, ET_Builder_Module>
     */
    public static array $instances = [];

    // Untyped to match real Divi (legacy code) — and so the subclass can
    // redeclare $slug / $vb_support with its own defaults without a typed-
    // property redeclaration fatal.
    public $name;
    public $slug = '';
    public $vb_support = 'off';

    /** @var array<string, mixed> */
    public $props = [];

    public function __construct() {
        // Mirror Divi: the base constructor drives init() so the subclass can
        // set its name/slug as part of registration.
        if ( method_exists( $this, 'init' ) ) {
            $this->init();
        }
        self::$instances[] = $this;
    }

    public static function reset(): void {
        self::$instances = [];
    }
}
