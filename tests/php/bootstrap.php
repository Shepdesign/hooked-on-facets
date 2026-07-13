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

// $wpdb->get_results() output-format constant, used by the indexer's
// ID-resolution queries. Defined here since there's no live WordPress.
if ( ! defined( 'ARRAY_A' ) ) {
    define( 'ARRAY_A', 'ARRAY_A' );
}

// Time constant used by the REST rate limiter's window sizing.
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
    define( 'MINUTE_IN_SECONDS', 60 );
}

require_once dirname( __DIR__, 2 ) . '/vendor/autoload.php';

// Global WP class stubs live outside tests/php/ to avoid composer's
// autoload-dev PSR-4 scan flagging them as misplaced.
require_once dirname( __DIR__ ) . '/stubs/WP_Query.php';
require_once dirname( __DIR__ ) . '/stubs/WP_Post.php';
require_once dirname( __DIR__ ) . '/stubs/WP_REST.php';

// \Bricks\Elements stub so the Bricks bridge's element registration has a
// target to call into without a live Bricks theme.
require_once dirname( __DIR__ ) . '/stubs/Bricks.php';

// \ET_Builder_Module stub so the Divi bridge's module registration can
// construct its FacetModule subclass without a live Divi builder.
require_once dirname( __DIR__ ) . '/stubs/ET_Builder_Module.php';

// HOF_PLUGIN_DIR is read by the Bricks bridge when registering its element.
if ( ! defined( 'HOF_PLUGIN_DIR' ) ) {
    define( 'HOF_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/' );
}
