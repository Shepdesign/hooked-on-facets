<?php
/**
 * UrlCodec — pure path ⇄ filter-state translation.
 *
 * encode(state) walks the configured facets in their saved (canonical) order
 * and emits /base/name/slug segments for every path-eligible discrete value,
 * values sorted by slug — deterministic ordering is what makes canonical
 * URLs stable. Everything else (ranges, search, reserved _* keys, unmappable
 * facets) lands in the returned tail, which callers append as ?hof[*].
 *
 * decode(path) is strict: any segment it cannot fully resolve nulls the whole
 * request — the caller turns that into a hard 404, never a soft-404
 * duplicate page.
 *
 * Pure by construction: no WP calls, all lookups via the injected mapper.
 *
 * @package HookedOnFacets
 */

declare(strict_types=1);

namespace HookedOnFacets\Routing;

defined( 'ABSPATH' ) || exit;

final class UrlCodec {

    /** Displays whose values are discrete tokens, eligible for path segments. */
    public const DISCRETE_DISPLAYS = [
        'checkbox',
        'radio',
        'dropdown',
        'toggle',
        'hierarchy',
        'swatch',
        'swiper',
        'spin',
        'matrix',
    ];

    /** @var array<string, array<string, mixed>> */
    private array $defs_by_name;

    /**
     * @param list<array<string, mixed>> $facets Ordered facet defs (hof_facets shape).
     */
    public function __construct(
        private readonly array $facets,
        private readonly SlugMapperInterface $mapper,
        private readonly string $base = 'filter',
    ) {
        $by = [];
        foreach ( $facets as $def ) {
            if ( is_array( $def ) && isset( $def['name'] ) ) {
                $by[ (string) $def['name'] ] = $def;
            }
        }
        $this->defs_by_name = $by;
    }

    public function base(): string {
        return $this->base;
    }

    /**
     * @param array<string, mixed> $state
     * @return array{path: string, tail: array<string, mixed>}
     */
    public function encode( array $state ): array {
        $segments     = [];
        $path_handled = [];

        // Pass 1: walk facets in canonical (saved) order, emitting path
        // segments for every facet that fully resolves to slugs.
        foreach ( $this->facets as $def ) {
            $name = (string) ( $def['name'] ?? '' );
            if ( $name === '' || ! array_key_exists( $name, $state ) ) {
                continue;
            }
            $value = $state[ $name ];

            if ( ! $this->is_path_facet( $def ) || $this->is_range_shaped( $value ) ) {
                continue; // Left for the tail pass below.
            }

            $values = is_array( $value ) ? $value : [ $value ];
            $slugs  = [];
            foreach ( $values as $v ) {
                $slug = is_scalar( $v ) ? $this->mapper->slug( $name, (string) $v ) : null;
                if ( $slug === null ) {
                    // One unmappable value sends the whole facet to the tail —
                    // a half-path/half-query facet would break round-tripping.
                    continue 2;
                }
                $slugs[] = $slug;
            }

            sort( $slugs, SORT_STRING );
            foreach ( $slugs as $slug ) {
                $segments[] = rawurlencode( $name );
                $segments[] = rawurlencode( $slug );
            }
            $path_handled[ $name ] = true;
        }

        // Pass 2: the tail preserves the caller's original key order —
        // everything not consumed into a path segment above, in the order
        // it appeared in $state.
        $tail = [];
        foreach ( $state as $name => $value ) {
            if ( ! isset( $path_handled[ (string) $name ] ) ) {
                $tail[ (string) $name ] = $value;
            }
        }

        return [
            'path' => $segments === [] ? '' : '/' . $this->base . '/' . implode( '/', $segments ) . '/',
            'tail' => $tail,
        ];
    }

    /**
     * @return array<string, list<string>>|null Null = unresolvable → hard 404.
     */
    public function decode( string $hof_path ): ?array {
        $segments = array_values( array_filter( explode( '/', trim( $hof_path, '/' ) ), static fn( $s ) => $s !== '' ) );
        if ( $segments === [] || count( $segments ) % 2 !== 0 ) {
            return null;
        }

        $out = [];
        for ( $i = 0; $i < count( $segments ); $i += 2 ) {
            $name = rawurldecode( strtolower( $segments[ $i ] ) );
            $slug = rawurldecode( strtolower( $segments[ $i + 1 ] ) );

            $def = $this->defs_by_name[ $name ] ?? null;
            if ( ! $def || ! $this->is_path_facet( $def ) ) {
                return null;
            }
            $value = $this->mapper->value( $name, $slug );
            if ( $value === null ) {
                return null;
            }
            $out[ $name ][] = $value;
        }

        return $out;
    }

    /**
     * Remove the /{base}/… suffix from a request path, yielding the archive
     * base path. Whole-segment match only — /shop-filter/ is untouched.
     */
    public function strip_base_path( string $path ): string {
        $stripped = preg_replace( '#/' . preg_quote( $this->base, '#' ) . '(/.*)?$#', '/', $path );
        return $stripped ?? $path;
    }

    /**
     * @param array<string, mixed> $def
     */
    public function is_path_facet( array $def ): bool {
        $display = (string) ( $def['display'] ?? '' );
        if ( ! in_array( $display, self::DISCRETE_DISPLAYS, true ) ) {
            return false;
        }
        return $this->mapper->is_mappable( (string) ( $def['name'] ?? '' ) );
    }

    private function is_range_shaped( mixed $value ): bool {
        return is_array( $value ) && ( array_key_exists( 'min', $value ) || array_key_exists( 'max', $value ) );
    }
}
