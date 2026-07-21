<?php
/**
 * FilterState — the single source of truth for the current request's filter
 * state.
 *
 * Merges the pretty path (query var hof_path, decoded by UrlCodec) with the
 * legacy ?hof[*] query tail. The path wins for any key present in both: in
 * steady state the two never overlap (the 301 layer redirects legacy discrete
 * keys to the path form), so the merge rule only matters for hand-built URLs.
 *
 * Resolver::parse_request_filters() delegates here, which makes every
 * existing consumer (QueryHook, Renderer, SeoManager, AssetLoader, the
 * page-builder bridges) pretty-aware without call-site changes. This class
 * therefore does the raw $_GET read itself — calling back into
 * parse_request_filters() would recurse.
 *
 * Memoized per request; reset() exists for tests.
 *
 * @package HookedOnFacets
 */

declare(strict_types=1);

namespace HookedOnFacets\Routing;

use HookedOnFacets\Filter\Resolver;
use HookedOnFacets\Indexer;

defined( 'ABSPATH' ) || exit;

final class FilterState {

    /** @var array<string, mixed>|null */
    private static ?array $memo = null;

    private static bool $path_invalid = false;

    private static ?UrlCodec $codec      = null;
    private static ?SlugMapper $mapper   = null;
    private static bool $codec_resolved  = false;

    private function __construct() {}

    /**
     * @return array<string, mixed>
     */
    public static function current(): array {
        if ( self::$memo !== null ) {
            return self::$memo;
        }

        $state = self::compute();

        // A third-party opt-in WP_Query can run before the `parse_request`
        // action fires (e.g. built at `init`), at which point hof_path hasn't
        // been resolved into a query var yet — reading it now would always
        // see '' and permanently memoize a tail-only state for the rest of
        // the request, even after the real query var shows up. Skip the memo
        // in that pre-parse window; only pretty URLs are affected since the
        // legacy tail never depends on parse_request.
        if ( PrettyUrls::enabled() && function_exists( 'did_action' ) && ! did_action( 'parse_request' ) ) {
            return $state;
        }

        return self::$memo = $state;
    }

    /**
     * @return array<string, mixed>
     */
    private static function compute(): array {
        self::$path_invalid = false;

        $tail = self::legacy_tail();

        if ( ! PrettyUrls::enabled() ) {
            return $tail;
        }

        $qv   = function_exists( 'get_query_var' ) ? get_query_var( 'hof_path' ) : '';
        $path = is_string( $qv ) ? $qv : '';
        if ( $path === '' ) {
            return $tail;
        }

        $codec   = self::codec();
        $decoded = $codec?->decode( $path );
        if ( $decoded === null ) {
            self::$path_invalid = true;
            return $tail;
        }

        // Union; the decoded path wins for any key present in both. A keyed
        // loop, NOT array_merge: array_merge renumbers integer keys, and a
        // digits-only facet name ('56') decodes to an int key (PHP array-key
        // coercion) — merge would silently rename facet 56 to facet 0.
        $state = $tail;
        foreach ( $decoded as $name => $values ) {
            $state[ $name ] = $values;
        }
        return $state;
    }

    /** True when hof_path was present but didn't fully resolve → hard 404. */
    public static function is_path_invalid(): bool {
        self::current();
        return self::$path_invalid;
    }

    /**
     * Lazily built codec for the configured facets, or null when none exist.
     */
    public static function codec(): ?UrlCodec {
        self::resolve_codec();
        return self::$codec;
    }

    /**
     * The SlugMapper backing codec() — built together with it in the same
     * resolve step, so callers that need direct map access (AssetLoader's
     * client-config payload) don't have to construct a second instance from
     * the same facet defs, which would double the bounded probe query per
     * facet on every request that ships pretty-URL config.
     */
    public static function mapper(): ?SlugMapper {
        self::resolve_codec();
        return self::$mapper;
    }

    /** Drop all memoized state (tests, and anything that mutates facet config mid-request). */
    public static function reset(): void {
        self::$memo           = null;
        self::$path_invalid   = false;
        self::$codec          = null;
        self::$mapper         = null;
        self::$codec_resolved = false;
    }

    /** Builds codec() + mapper() together, once, memoized. */
    private static function resolve_codec(): void {
        if ( self::$codec_resolved ) {
            return;
        }
        self::$codec_resolved = true;

        $defs = get_option( Indexer::OPTION_FACETS, [] );
        $defs = is_array( $defs ) ? array_values( array_filter( $defs, 'is_array' ) ) : [];
        if ( $defs === [] ) {
            return;
        }

        $by_name = [];
        foreach ( $defs as $def ) {
            if ( isset( $def['name'] ) ) {
                $by_name[ (string) $def['name'] ] = $def;
            }
        }

        self::$mapper = new SlugMapper( $by_name );
        self::$codec  = new UrlCodec( $defs, self::$mapper, PrettyUrls::base() );
    }

    /**
     * The raw legacy ?hof[*] read — the one place in the plugin that touches
     * $_GET['hof'] directly.
     *
     * @return array<string, mixed>
     */
    private static function legacy_tail(): array {
        if ( ! isset( $_GET['hof'] ) || ! is_array( $_GET['hof'] ) ) {
            return [];
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, sanitized below.
        return Resolver::sanitize_filter_state( wp_unslash( $_GET['hof'] ) );
    }
}
