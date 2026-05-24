<?php
/**
 * PHPUnit bootstrap.
 *
 * No live WordPress here — Brain Monkey stubs WP functions per-test. We only
 * need three things in place:
 *   - ABSPATH defined so production files' "defined('ABSPATH') || exit;"
 *     guards pass without bailing out.
 *   - The composer autoloader, which also exposes the PSR-4 test namespace
 *     declared in composer.json's autoload-dev.
 *   - HOF_VERSION etc. for any production file that reads plugin constants
 *     during class load.
 */
declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', __DIR__ . '/../../' );
}

if ( ! defined( 'HOF_VERSION' ) ) {
    define( 'HOF_VERSION', 'test' );
}

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// Global WP class stubs live outside tests/php/ to avoid composer's
// autoload-dev PSR-4 scan flagging them as misplaced.
require_once dirname( __DIR__ ) . '/stubs/WP_Query.php';
