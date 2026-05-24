<?php
/**
 * Narrow contract for "resolve a filter state to a list of object IDs".
 *
 * Exists so that consumers of the filter pipeline (QueryHook, the
 * Elementor bridge, the AI NL filter, the REST controller) can depend on
 * the behavior without depending on Resolver's full concrete surface —
 * which is `final` and therefore not mockable. The production binding
 * still resolves to Resolver; the interface is purely a seam.
 *
 * @package HookedOnFacets\Filter
 */

declare(strict_types=1);

namespace HookedOnFacets\Filter;

defined( 'ABSPATH' ) || exit;

interface IdResolver {

    /**
     * Return the IDs matching the given filter state, or null if no
     * recognized filters were present and the caller should fall through.
     *
     * @param array<string, mixed> $filter_state
     * @return int[]|null
     */
    public function resolve_ids( array $filter_state ): ?array;
}
