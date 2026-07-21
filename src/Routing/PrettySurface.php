<?php
/**
 * PrettySurface — the shared "is this request a surface pretty links may
 * render on" gate, plus the origin/base-path context they need.
 *
 * SeoManager's 301/canonical layer and Renderer's crawlable pretty-link
 * helper both need the identical answer to "does the current request sit on
 * a surface RewriteManager actually registered rewrite rules for" — the shop
 * archive or a product taxonomy, with a non-root base path (shop-as-front-page
 * has no root rules; a shortcode/builder page has none at all). Duplicating
 * that gate risks the two call sites drifting; this class is the one place it
 * lives.
 *
 * Memoized per request, same pattern as FilterState::codec() — deliberately
 * NOT reset by FilterState::reset(), since the two caches are independent and
 * a caller that mutates only filter state shouldn't have to know this exists.
 * Tests that seed a different surface across cases must call reset()
 * themselves.
 *
 * @package HookedOnFacets
 */

declare(strict_types=1);

namespace HookedOnFacets\Routing;

defined( 'ABSPATH' ) || exit;

final class PrettySurface {

    /** @var array{base_path: string, origin: string}|null */
    private static ?array $memo = null;

    private static bool $resolved = false;

    private function __construct() {}

    /**
     * Per-request pretty-link context, or null when pretty links must not
     * render here: pretty URLs disabled, no codec, or the current request
     * isn't a rule-bearing storefront surface (shop archive or product
     * taxonomy with a non-root base path — shop-as-front-page has no root
     * rules; builder/shortcode pages have none at all). Third-party bases
     * added via the `hof_pretty_urls_bases` filter deliberately don't count
     * either: this gate only recognizes the shop archive and product
     * taxonomies, so those extra surfaces get neither a 301/canonical from
     * SeoManager nor a crawlable link from Renderer — conservative by design,
     * since we can't verify a third-party surface actually has rewrite rules
     * registered for it.
     *
     * @return array{base_path: string, origin: string}|null
     */
    public static function context(): ?array {
        if ( self::$resolved ) {
            return self::$memo;
        }
        self::$resolved = true;

        return self::$memo = self::compute();
    }

    /** Drop the memoized context (tests, and anything that changes surface mid-request). */
    public static function reset(): void {
        self::$memo     = null;
        self::$resolved = false;
    }

    /**
     * @return array{base_path: string, origin: string}|null
     */
    private static function compute(): ?array {
        if ( ! PrettyUrls::enabled() ) {
            return null;
        }
        $codec = FilterState::codec();
        if ( ! $codec ) {
            return null;
        }

        $host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
        // REQUEST_URI is deliberately raw — no sanitize_text_field(). That
        // function strips `%xx` octets, which would decanonicalize a pretty
        // path with an encoded slug; see SeoManager::current_url()'s docblock
        // for the full rationale (this mirrors it exactly).
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw on purpose, see comment above.
        $uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
        if ( $host === '' || $uri === '' ) {
            return null;
        }

        $path = (string) ( wp_parse_url( $uri )['path'] ?? '/' );
        $path = (string) preg_replace( '#/page/[0-9]+/?$#', '/', $path );
        $base_path = $codec->strip_base_path( $path );

        if ( '/' === $base_path || '' === $base_path ) {
            return null;
        }

        $is_shop = function_exists( 'is_shop' ) && is_shop();
        $is_tax  = function_exists( 'is_product_taxonomy' ) && is_product_taxonomy();
        if ( ! $is_shop && ! $is_tax ) {
            return null;
        }

        return [
            'base_path' => $base_path,
            'origin'    => ( is_ssl() ? 'https' : 'http' ) . '://' . $host,
        ];
    }
}
