<?php
/**
 * SlugMapper — value ⇄ slug maps for path-eligible facets.
 *
 * One map per facet, built from SELECT DISTINCT facet_value on the index
 * table and cached in the object cache under the current hof_index_version
 * (a reindex bumps the version and orphans every stale map — same trick as
 * the resolver's result cache).
 *
 * Taxonomy facets index term slugs as facet_value, so mapping is identity —
 * but membership in the map still validates decode (unknown slug → null →
 * hard 404 upstream, no soft-404 duplicate pages).
 *
 * Meta values slugify via sanitize_title(); two values collapsing to the
 * same slug get deterministic -2/-3 suffixes in ORDER BY facet_value order,
 * keeping encode/decode bijective across requests.
 *
 * Facets with more distinct values than the cap (filter
 * hof_pretty_urls_max_values, default 500) are declared unmappable: they
 * stay on the ?hof[*] query tail on both server and client, which keeps the
 * two encoders consistent by construction.
 *
 * @package HookedOnFacets
 */

declare(strict_types=1);

namespace HookedOnFacets\Routing;

use HookedOnFacets\Activator;
use HookedOnFacets\Filter\Resolver;

defined( 'ABSPATH' ) || exit;

final class SlugMapper implements SlugMapperInterface {

    public const CACHE_GROUP = 'hof_slugmap';

    /** Sentinel cached for over-cap facets so we don't re-query every request. */
    private const OVER_CAP = 'over_cap';

    /** @var array<string, array{forward: array<string, string>, reverse: array<string, string>}|null> */
    private array $memo = [];

    /**
     * @param array<string, array<string, mixed>> $defs_by_name Facet defs keyed by name.
     */
    public function __construct( private readonly array $defs_by_name ) {}

    public function slug( string $facet_name, string $value ): ?string {
        $map = $this->map( $facet_name );
        return $map['forward'][ $value ] ?? null;
    }

    public function value( string $facet_name, string $slug ): ?string {
        $map = $this->map( $facet_name );
        return $map['reverse'][ $slug ] ?? null;
    }

    public function is_mappable( string $facet_name ): bool {
        $map = $this->map( $facet_name );
        return $map !== null && ! empty( $map['forward'] );
    }

    public function client_map( string $facet_name ): ?array {
        $def = $this->defs_by_name[ $facet_name ] ?? null;
        if ( ! $def ) {
            return null;
        }
        $map = $this->map( $facet_name );
        if ( $map === null || $map['forward'] === [] ) {
            return null;
        }
        // Ship the full map for EVERY facet — taxonomy included, even though
        // its pairs are identity. The client must reject the same values the
        // server would: UrlCodec::decode() hard-404s a slug that isn't in the
        // mapper's membership set, so a client that identity-paths an
        // unmapped taxonomy value (e.g. a stale term from an old page load)
        // would build a link the server refuses. A partial/missing map would
        // also make the client bail to the query tail for values the server
        // happily paths — the two encoders must agree by construction.
        return $map['forward'];
    }

    // ── Internals ───────────────────────────────────────────────────────────

    /**
     * @return array{forward: array<string, string>, reverse: array<string, string>}|null
     */
    private function map( string $facet_name ): ?array {
        if ( array_key_exists( $facet_name, $this->memo ) ) {
            return $this->memo[ $facet_name ];
        }

        $def = $this->defs_by_name[ $facet_name ] ?? null;
        if ( ! $def ) {
            return $this->memo[ $facet_name ] = null;
        }

        $version = (int) get_option( Resolver::VERSION_OPTION, 0 );
        $key     = sprintf( 'map:%s:v%d', $facet_name, $version );

        $cached = function_exists( 'wp_cache_get' ) ? wp_cache_get( $key, self::CACHE_GROUP ) : false;
        if ( $cached === self::OVER_CAP ) {
            return $this->memo[ $facet_name ] = null;
        }
        if ( is_array( $cached ) && isset( $cached['forward'], $cached['reverse'] ) ) {
            return $this->memo[ $facet_name ] = $cached;
        }

        $map = $this->build_map( $facet_name, (string) ( $def['kind'] ?? '' ) );

        if ( function_exists( 'wp_cache_set' ) ) {
            $ttl = (int) apply_filters( 'hof_slugmap_cache_ttl', 3600 );
            wp_cache_set( $key, $map ?? self::OVER_CAP, self::CACHE_GROUP, $ttl );
        }

        return $this->memo[ $facet_name ] = $map;
    }

    /**
     * @return array{forward: array<string, string>, reverse: array<string, string>}|null
     */
    private function build_map( string $facet_name, string $kind ): ?array {
        global $wpdb;
        $table = $wpdb->prefix . Activator::TABLE;

        $cap = max( 1, (int) apply_filters( 'hof_pretty_urls_max_values', 500 ) );

        // Bounded probe: LIMIT cap+1 is enough rows to tell "over cap" from
        // "at or under cap" without hydrating a facet with tens of thousands
        // of distinct values on every call. is_mappable() runs on every
        // front-end render (Renderer::pretty_link() calls it once per
        // discrete-facet option), and there's no guarantee the object cache
        // is anything but a per-request no-op, so an unbounded SELECT here
        // would mean re-reading the whole distinct-value set on every page
        // view for any facet that ends up over the cap. ORDER BY still makes
        // collision suffixes deterministic across requests for the values
        // actually returned.
        $values = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT facet_value FROM {$table} WHERE facet_name = %s ORDER BY facet_value LIMIT " . ( $cap + 1 ),
            $facet_name
        ) );

        if ( count( $values ) > $cap ) {
            return null;
        }

        $forward = [];
        $reverse = [];
        foreach ( $values as $value ) {
            $value = (string) $value;
            $slug  = $kind === 'taxonomy' ? $value : sanitize_title( $value );
            if ( $slug === '' ) {
                continue;
            }
            // Deterministic collision suffix: first taker keeps the bare slug.
            if ( isset( $reverse[ $slug ] ) ) {
                $n = 2;
                while ( isset( $reverse[ "{$slug}-{$n}" ] ) ) {
                    $n++;
                }
                $slug = "{$slug}-{$n}";
            }
            $forward[ $value ] = $slug;
            $reverse[ $slug ]  = $value;
        }

        return [ 'forward' => $forward, 'reverse' => $reverse ];
    }
}
