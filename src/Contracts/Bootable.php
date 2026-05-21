<?php
/**
 * Contract for services that register WordPress hooks during plugin boot.
 *
 * @package HookedOnFacets
 */

declare(strict_types=1);

namespace HookedOnFacets\Contracts;

defined( 'ABSPATH' ) || exit;

interface Bootable {

    /**
     * Wire up add_action / add_filter calls. Called once by Plugin::boot().
     */
    public function register_hooks(): void;
}
