<?php
/**
 * SlugMapperInterface — value ⇄ slug translation for one facet.
 *
 * Null returns mean "cannot map": unknown facet, unknown value/slug, or a
 * facet over the distinct-value cap. Callers treat null as "not path-eligible"
 * (encode) or "hard 404" (decode).
 *
 * @package HookedOnFacets
 */

declare(strict_types=1);

namespace HookedOnFacets\Routing;

defined( 'ABSPATH' ) || exit;

interface SlugMapperInterface {

    /** Facet value → URL slug, or null when unmappable. */
    public function slug( string $facet_name, string $value ): ?string;

    /** URL slug → facet value, or null when unknown. */
    public function value( string $facet_name, string $slug ): ?string;

    /**
     * Whether the facet has a usable (under-cap, non-empty) map.
     */
    public function is_mappable( string $facet_name ): bool;

    /**
     * value → slug map for the client bundle, or null when identity
     * (taxonomy), over cap, or unknown. Only meta facets ship a map.
     *
     * @return array<string, string>|null
     */
    public function client_map( string $facet_name ): ?array;
}
