<?php
/**
 * Narrow contract for "record that we intercepted a loop."
 *
 * Same justification as {@see \HookedOnFacets\Filter\IdResolver}: Recorder
 * is `final` and therefore not mockable. Consumers that only need the
 * single record_loop_hook method depend on this interface instead.
 *
 * @package HookedOnFacets\Telemetry
 */

declare(strict_types=1);

namespace HookedOnFacets\Telemetry;

defined( 'ABSPATH' ) || exit;

interface LoopHookRecorder {

    /**
     * Record one loop-intercept event for the admin telemetry counter.
     *
     * Signature is intentionally low-cardinality: callers should pass a
     * stable string like `archive:product` or `elementor:product` rather
     * than per-request identifiers.
     */
    public function record_loop_hook( string $signature ): void;
}
