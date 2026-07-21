<?php
/**
 * PrettyUrls — the hof_pretty_urls option.
 *
 * Off by default. Enabling requires non-plain permalinks (rewrite rules don't
 * exist under ?p=123 permalinks), so enabled() is option AND availability.
 * Updates that change anything set a deferred-flush flag which
 * RewriteManager consumes on the next admin_init — never flush inline.
 *
 * @package HookedOnFacets
 */

declare(strict_types=1);

namespace HookedOnFacets\Routing;

defined( 'ABSPATH' ) || exit;

final class PrettyUrls {

    public const OPTION     = 'hof_pretty_urls';
    public const FLUSH_FLAG = 'hof_flush_rewrites';

    private function __construct() {}

    /**
     * @return array{enabled: bool, base: string}
     */
    public static function defaults(): array {
        return [
            'enabled' => false,
            'base'    => 'filter',
        ];
    }

    /**
     * Stored settings merged over defaults.
     *
     * @return array{enabled: bool, base: string}
     */
    public static function settings(): array {
        $stored = get_option( self::OPTION, [] );
        $stored = is_array( $stored ) ? $stored : [];
        $merged = array_merge( self::defaults(), $stored );
        $base   = (string) $merged['base'];
        return [
            'enabled' => (bool) $merged['enabled'],
            'base'    => $base !== '' ? $base : self::defaults()['base'],
        ];
    }

    /** Option on AND permalinks non-plain. */
    public static function enabled(): bool {
        return self::settings()['enabled'] && self::available();
    }

    /** The reserved path segment, e.g. "filter". */
    public static function base(): string {
        return self::settings()['base'];
    }

    /** Pretty URLs need non-plain permalinks. */
    public static function available(): bool {
        return (string) get_option( 'permalink_structure', '' ) !== '';
    }

    /**
     * Persist a partial update. Base is slug-sanitized with a 'filter'
     * fallback. Any actual change sets the deferred-flush flag.
     *
     * @param array<string, mixed> $partial
     */
    public static function update( array $partial ): void {
        $current = self::settings();
        $next    = $current;

        if ( array_key_exists( 'enabled', $partial ) ) {
            $next['enabled'] = (bool) $partial['enabled'];
        }
        if ( array_key_exists( 'base', $partial ) ) {
            $base         = sanitize_title( (string) $partial['base'] );
            $next['base'] = $base !== '' ? $base : self::defaults()['base'];
        }

        if ( $next === $current ) {
            return;
        }

        update_option( self::OPTION, $next, false );
        update_option( self::FLUSH_FLAG, 1, false );
    }

    /**
     * Warning when a store term is slugged exactly like the base segment —
     * its archive URLs would collide with the rewrite rules (nested and
     * paginated forms mis-split and 404). Slug-only lookup on purpose:
     * term_exists() matches by name first, which false-positives on a term
     * merely NAMED like the base.
     */
    public static function base_collision_warning( ?string $base = null ): ?string {
        $base = $base ?? self::base();
        if ( ! function_exists( 'get_term_by' ) ) {
            return null;
        }
        foreach ( [ 'product_cat', 'product_tag' ] as $taxonomy ) {
            if ( false !== get_term_by( 'slug', $base, $taxonomy ) ) {
                return sprintf(
                    /* translators: %s: the configured URL segment */
                    __( 'A store term is slugged "%s" — its archive URLs will collide with pretty filter URLs. Change the URL segment below.', 'hooked-on-facets' ),
                    $base
                );
            }
        }
        return null;
    }
}
