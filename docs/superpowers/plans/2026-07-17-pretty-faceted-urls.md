# Pretty Faceted URLs Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn `/shop/?hof[brand]=nike&hof[color][]=blue` into `/shop/filter/brand/nike/color/blue/` on WooCommerce storefront surfaces — opt-in, crawlable, canonical, with the resolver and all 16 facet types untouched.

**Architecture:** A new `HookedOnFacets\Routing` namespace supplies a pure `UrlCodec` (path ⇄ filter-state array), a `SlugMapper` (value ⇄ slug, DB-backed + version-cached), a `RewriteManager` (rewrite rules + deferred flush + 404 guard), and a `FilterState` provider that merges the pretty path with the legacy `?hof[*]` tail. `Resolver::parse_request_filters()` delegates to `FilterState::current()`, so every existing consumer (QueryHook, Renderer, SeoManager, AssetLoader, page-builder bridges) becomes pretty-aware with zero call-site changes. SeoManager grows a pretty canonical + 301 layer; Renderer emits crawlable `<a>` links; `state.js` learns the pretty path client-side.

**Tech Stack:** PHP 8.2 (PSR-4 `HookedOnFacets\` → `src/`), PHPUnit 11 + Brain Monkey (see `tests/php/SeoManagerTest.php` for the house style), Vitest + jsdom for `public/src`, React admin in `admin/src`.

**Spec:** `docs/superpowers/specs/2026-07-05-pretty-faceted-urls-design.md` (commit `91ea8af`).

---

## Verified codebase facts (read before implementing)

These were confirmed by reading the code — the spec's file table drifts slightly from reality:

- The resolver is `src/Filter/Resolver.php` (namespace `HookedOnFacets\Filter`), **not** `src/Facets/`. `Resolver::parse_request_filters()` (line ~261) and `Resolver::sanitize_filter_state()` are `public static`.
- The query hook is `includes/class-hof-query-hook.php` (classmap, namespace root `HookedOnFacets\QueryHook`). Its main-query gate reads `! empty( $_GET['hof'] ?? null )` at line ~124.
- Facet definitions live in the `hof_facets` option (`Indexer::OPTION_FACETS`), a **list** whose saved order is the canonical facet order. Each def: `{name, label, source, kind: 'taxonomy'|'meta'|'field', display, settings}`.
- Taxonomy facets index `term->slug` as `facet_value` (verified in the indexer) — forward/reverse slug mapping is identity for them.
- Cache versioning: option `hof_index_version` (`Resolver::VERSION_OPTION`), bumped by the indexer on every write. The resolver caches under group `hof_resolve` with keys `kind:v{version}:{md5}`.
- The index table is `$wpdb->prefix . Activator::TABLE` (`hof_index`).
- Services register in `src/Plugin.php`: closure bindings in `register_bindings()` (only if constructor args needed) + an entry in `core_services()`. Zero-arg services need no binding.
- SEO settings REST: `GET|POST hof/v1/seo-settings` in `src/Api/RestController.php` (~line 188, handlers ~357–385). Admin UI: `admin/src/components/SeoSettings.jsx`.
- Frontend boot blob: `AssetLoader::enqueue()` inline-injects `window.hofPublic = {restUrl, nonce, state}`.
- PHP tests: Brain Monkey, no live WP (`tests/php/bootstrap.php`). `apply_filters`/`do_action` pass through automatically; everything else is stubbed with `Functions\when(...)`. Run: `composer test` (all) or `vendor/bin/phpunit --filter Name`.
- JS tests: `npx vitest run` (config `vitest.config.js`, includes `tests/js/**/*.test.js`, jsdom, setup `tests/js/setup.js`). There is no `state.test.js` yet — Task 9 creates it.
- Activation: `Activator::activate()` → `install_for_current_site()` (`includes/class-hof-activator.php:52`). `Deactivator::deactivate()` already calls `flush_rewrite_rules()`.
- Commit style: conventional commits, no AI attribution of any kind (`feat(routing): …`, `test(routing): …`).

## File structure

| File | Status | Responsibility |
|---|---|---|
| `src/Routing/PrettyUrls.php` | create | `hof_pretty_urls` option accessor: enabled/base/available, update + deferred-flush flag |
| `src/Routing/SlugMapperInterface.php` | create | `slug()` / `value()` contract so UrlCodec is testable with a fake |
| `src/Routing/SlugMapper.php` | create | DB-backed value ⇄ slug maps, version-cached, deterministic collision suffixes, per-facet size cap |
| `src/Routing/UrlCodec.php` | create | Pure `encode(state) → {path, tail}` / `decode(path) → state|null`; path-eligibility rule; base-path stripping |
| `src/Routing/FilterState.php` | create | `current()`: pretty path ⊕ `?hof[*]` tail, memoized; `codec()` factory; `reset()` for tests |
| `src/Routing/RewriteManager.php` | create | `hof_path` query var, rewrite rules per storefront base, deferred flush, invalid-path 404 guard |
| `src/Filter/Resolver.php` | modify | `parse_request_filters()` delegates to `FilterState::current()` |
| `includes/class-hof-query-hook.php` | modify | main-query gate also fires on `hof_path` |
| `src/Plugin.php` | modify | register `RewriteManager` in `core_services()` |
| `includes/class-hof-activator.php` | modify | set flush flag on install |
| `src/Api/RestController.php` | modify | seo-settings GET/POST carries `pretty_urls {enabled, base, available}` |
| `admin/src/components/SeoSettings.jsx` | modify | "Pretty faceted URLs" section: toggle + base field + permalink guard notice |
| `src/Seo/SeoManager.php` | modify | pretty canonical target; 301 legacy→pretty and non-canonical→canonical |
| `src/Facets/Renderer.php` | modify | crawlable `<a>` per discrete value (checkbox, radio, hierarchy, swatch; hidden list for dropdown) |
| `src/Frontend/AssetLoader.php` | modify | localize `prettyUrls` config (base, basePath, ordered facets, meta slug maps) |
| `public/src/state.js` | modify | `buildUrl`/`hydrateFromUrl` learn the pretty path |
| `public/src/main.js` | modify | intercept `.hof-facet-link` clicks |
| `tests/php/Routing/PrettyUrlsTest.php` | create | option defaults, update/flush-flag, availability |
| `tests/php/Routing/SlugMapperTest.php` | create | forward/reverse, collisions, cache, cap |
| `tests/php/Routing/UrlCodecTest.php` | create | round-trip + every spec case |
| `tests/php/Routing/FilterStateTest.php` | create | merge precedence, memoization, disabled path |
| `tests/php/Routing/RewriteManagerTest.php` | create | pure rule generation |
| `tests/php/SeoManagerTest.php` | modify | pretty canonical, redirect decisions |
| `tests/php/RendererPrettyLinksTest.php` | create | anchor emission on/off |
| `tests/js/state.test.js` | create | client pretty encode/decode round-trips |
| `docs/pretty-urls.md`, `CHANGELOG.md`, `readme.txt`, `plan.md` | create/modify | docs |

**State-shape reminder** (the array everything speaks): `['brand' => ['nike','adidas'], 'color' => ['blue'], 'price' => ['min' => 10.0, 'max' => 50.0], 'search' => 'shoes', '_bin_ids' => [12, 34]]`. Discrete facets carry a list of value strings (a bare scalar string is also legal on input); ranges are `min`/`max` arrays; reserved keys start with `_`.

---

### Task 1: `PrettyUrls` option service

**Files:**
- Create: `src/Routing/PrettyUrls.php`
- Test: `tests/php/Routing/PrettyUrlsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php
/**
 * @package HookedOnFacets\Tests
 */

declare(strict_types=1);

namespace HookedOnFacets\Tests\Routing;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HookedOnFacets\Routing\PrettyUrls;
use PHPUnit\Framework\TestCase;

/**
 * Covers the hof_pretty_urls option accessor: defaults, sanitized updates,
 * the deferred-flush flag, and the plain-permalink availability guard.
 */
final class PrettyUrlsTest extends TestCase {

    /** @var array<string, mixed> In-memory option store for the stubs. */
    private array $options = [];

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->options = [];
        Functions\when( 'get_option' )->alias( fn( $name, $default = false ) => $this->options[ $name ] ?? $default );
        Functions\when( 'update_option' )->alias( function ( $name, $value ) {
            $this->options[ $name ] = $value;
            return true;
        } );
        Functions\when( 'sanitize_title' )->alias(
            static fn( $t ) => trim( preg_replace( '/[^a-z0-9-]+/', '-', strtolower( (string) $t ) ), '-' )
        );
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_defaults_disabled_with_filter_base(): void {
        $s = PrettyUrls::settings();
        self::assertFalse( $s['enabled'] );
        self::assertSame( 'filter', $s['base'] );
    }

    public function test_enabled_requires_option_and_permalinks(): void {
        $this->options['permalink_structure'] = ''; // plain permalinks
        $this->options[ PrettyUrls::OPTION ]  = [ 'enabled' => true, 'base' => 'filter' ];
        self::assertFalse( PrettyUrls::enabled() );

        $this->options['permalink_structure'] = '/%postname%/';
        self::assertTrue( PrettyUrls::enabled() );
    }

    public function test_available_reflects_permalink_structure(): void {
        $this->options['permalink_structure'] = '';
        self::assertFalse( PrettyUrls::available() );
        $this->options['permalink_structure'] = '/%postname%/';
        self::assertTrue( PrettyUrls::available() );
    }

    public function test_update_sanitizes_base_and_sets_flush_flag(): void {
        PrettyUrls::update( [ 'enabled' => true, 'base' => 'My Filter!!' ] );

        self::assertSame(
            [ 'enabled' => true, 'base' => 'my-filter' ],
            $this->options[ PrettyUrls::OPTION ]
        );
        self::assertSame( 1, $this->options[ PrettyUrls::FLUSH_FLAG ] );
    }

    public function test_update_empty_base_falls_back_to_filter(): void {
        PrettyUrls::update( [ 'enabled' => true, 'base' => '!!!' ] );
        self::assertSame( 'filter', $this->options[ PrettyUrls::OPTION ]['base'] );
    }

    public function test_update_without_change_does_not_set_flush_flag(): void {
        $this->options[ PrettyUrls::OPTION ] = [ 'enabled' => false, 'base' => 'filter' ];
        PrettyUrls::update( [ 'enabled' => false, 'base' => 'filter' ] );
        self::assertArrayNotHasKey( PrettyUrls::FLUSH_FLAG, $this->options );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter PrettyUrlsTest`
Expected: ERROR — `Class "HookedOnFacets\Routing\PrettyUrls" not found`

- [ ] **Step 3: Write the implementation**

Create `src/Routing/PrettyUrls.php`:

```php
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
        return [
            'enabled' => (bool) $merged['enabled'],
            'base'    => (string) $merged['base'],
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
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter PrettyUrlsTest`
Expected: OK, 6 tests

- [ ] **Step 5: Commit**

```bash
git add src/Routing/PrettyUrls.php tests/php/Routing/PrettyUrlsTest.php
git commit -m "feat(routing): add hof_pretty_urls option service with deferred-flush flag"
```

---

### Task 2: `SlugMapperInterface` + `SlugMapper`

**Files:**
- Create: `src/Routing/SlugMapperInterface.php`
- Create: `src/Routing/SlugMapper.php`
- Test: `tests/php/Routing/SlugMapperTest.php`

Design notes locked here so encode/decode stay bijective and the 404 rule holds:

- The map is built per facet from `SELECT DISTINCT facet_value FROM {$wpdb->prefix}hof_index WHERE facet_name = %s ORDER BY facet_value` — **for both kinds**. Taxonomy values are already slugs (identity mapping) but membership in the map is what validates a decode; an unknown slug returns `null` → hard 404 upstream.
- Meta values slugify via `sanitize_title()`; collisions get deterministic `-2`, `-3` suffixes in `ORDER BY facet_value` order.
- A facet with more distinct values than the cap (filter `hof_pretty_urls_max_values`, default 500) returns `null` maps — UrlCodec then routes that facet to the query tail on both server and client, keeping the two consistent.
- Cached in the object cache, group `hof_slugmap`, key `map:{facet}:v{index_version}` (same versioned-orphaning trick as the resolver cache), TTL filter `hof_slugmap_cache_ttl` default 3600.

- [ ] **Step 1: Write the interface** (no test — it's a contract)

Create `src/Routing/SlugMapperInterface.php`:

```php
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
```

- [ ] **Step 2: Write the failing test**

Create `tests/php/Routing/SlugMapperTest.php`:

```php
<?php
/**
 * @package HookedOnFacets\Tests
 */

declare(strict_types=1);

namespace HookedOnFacets\Tests\Routing;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HookedOnFacets\Routing\SlugMapper;
use PHPUnit\Framework\TestCase;

/**
 * Covers forward/reverse mapping, deterministic collision suffixes, the
 * version-scoped cache, and the distinct-value cap. $wpdb is a hand stub;
 * the object cache is an in-memory array.
 */
final class SlugMapperTest extends TestCase {

    /** @var array<string, mixed> */
    private array $cache = [];

    /** @var array<string, list<string>> facet_name → DISTINCT facet_value rows */
    private array $rows = [];

    private int $query_count = 0;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $this->cache       = [];
        $this->rows        = [];
        $this->query_count = 0;

        Functions\when( 'get_option' )->alias( static fn( $name, $default = false ) => $name === 'hof_index_version' ? 7 : $default );
        Functions\when( 'sanitize_title' )->alias(
            static fn( $t ) => trim( preg_replace( '/[^a-z0-9-]+/', '-', strtolower( (string) $t ) ), '-' )
        );
        Functions\when( 'wp_cache_get' )->alias( fn( $key, $group ) => $this->cache[ "$group:$key" ] ?? false );
        Functions\when( 'wp_cache_set' )->alias( function ( $key, $value, $group, $ttl = 0 ) {
            $this->cache[ "$group:$key" ] = $value;
            return true;
        } );

        // Minimal $wpdb: prepare() interpolates the one %s; get_col() returns fixture rows.
        $rows        = &$this->rows;
        $query_count = &$this->query_count;
        $GLOBALS['wpdb'] = new class( $rows, $query_count ) {
            public string $prefix = 'wp_';
            public function __construct( private array &$rows, private int &$query_count ) {}
            public function prepare( string $sql, ...$args ): string {
                return str_replace( '%s', "'" . $args[0] . "'", $sql );
            }
            public function get_col( string $sql ): array {
                $this->query_count++;
                if ( preg_match( "/facet_name = '([^']+)'/", $sql, $m ) ) {
                    return $this->rows[ $m[1] ] ?? [];
                }
                return [];
            }
        };
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wpdb'] );
        Monkey\tearDown();
        parent::tearDown();
    }

    /** @param list<array<string, mixed>> $defs */
    private function mapper( array $defs ): SlugMapper {
        $by_name = [];
        foreach ( $defs as $d ) {
            $by_name[ $d['name'] ] = $d;
        }
        return new SlugMapper( $by_name );
    }

    public function test_taxonomy_is_identity_but_validates_membership(): void {
        $this->rows['brand'] = [ 'adidas', 'nike' ];
        $m = $this->mapper( [ [ 'name' => 'brand', 'kind' => 'taxonomy' ] ] );

        self::assertSame( 'nike', $m->slug( 'brand', 'nike' ) );
        self::assertSame( 'nike', $m->value( 'brand', 'nike' ) );
        self::assertNull( $m->slug( 'brand', 'puma' ) );
        self::assertNull( $m->value( 'brand', 'puma' ) );
        self::assertNull( $m->client_map( 'brand' ) ); // identity → no client map
    }

    public function test_meta_slugifies_and_reverses(): void {
        $this->rows['material'] = [ 'Solid Oak', 'Walnut' ];
        $m = $this->mapper( [ [ 'name' => 'material', 'kind' => 'meta' ] ] );

        self::assertSame( 'solid-oak', $m->slug( 'material', 'Solid Oak' ) );
        self::assertSame( 'Solid Oak', $m->value( 'material', 'solid-oak' ) );
        self::assertSame(
            [ 'Solid Oak' => 'solid-oak', 'Walnut' => 'walnut' ],
            $m->client_map( 'material' )
        );
    }

    public function test_collisions_get_deterministic_suffixes(): void {
        // Both slugify to "12" — ORDER BY facet_value order decides who keeps it.
        $this->rows['size'] = [ '12"', '12in' ];
        $m = $this->mapper( [ [ 'name' => 'size', 'kind' => 'meta' ] ] );

        self::assertSame( '12', $m->slug( 'size', '12"' ) );
        self::assertSame( '12-2', $m->slug( 'size', '12in' ) );
        self::assertSame( '12"', $m->value( 'size', '12' ) );
        self::assertSame( '12in', $m->value( 'size', '12-2' ) );
    }

    public function test_map_is_cached_per_version(): void {
        $this->rows['brand'] = [ 'nike' ];
        $m = $this->mapper( [ [ 'name' => 'brand', 'kind' => 'taxonomy' ] ] );

        $m->slug( 'brand', 'nike' );
        $m->slug( 'brand', 'nike' );
        self::assertSame( 1, $this->query_count );
        self::assertArrayHasKey( 'hof_slugmap:map:brand:v7', $this->cache );
    }

    public function test_over_cap_facet_is_unmappable(): void {
        Functions\when( 'apply_filters' )->alias(
            static fn( $hook, $value ) => $hook === 'hof_pretty_urls_max_values' ? 2 : $value
        );
        $this->rows['sku'] = [ 'a', 'b', 'c' ];
        $m = $this->mapper( [ [ 'name' => 'sku', 'kind' => 'meta' ] ] );

        self::assertFalse( $m->is_mappable( 'sku' ) );
        self::assertNull( $m->slug( 'sku', 'a' ) );
        self::assertNull( $m->client_map( 'sku' ) );
    }

    public function test_unknown_facet_is_unmappable(): void {
        $m = $this->mapper( [] );
        self::assertFalse( $m->is_mappable( 'ghost' ) );
        self::assertNull( $m->slug( 'ghost', 'x' ) );
        self::assertNull( $m->value( 'ghost', 'x' ) );
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter SlugMapperTest`
Expected: ERROR — `Class "HookedOnFacets\Routing\SlugMapper" not found`

- [ ] **Step 4: Write the implementation**

Create `src/Routing/SlugMapper.php`:

```php
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
        if ( ! $def || ( $def['kind'] ?? '' ) === 'taxonomy' ) {
            return null; // Identity — the client needs no map.
        }
        $map = $this->map( $facet_name );
        if ( $map === null || $map['forward'] === [] ) {
            return null;
        }
        // Ship the full map — even identity pairs. A partial map would make
        // the client bail to the query tail for values the server happily
        // paths, and the two encoders must agree by construction.
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

        // ORDER BY makes collision suffixes deterministic across requests.
        $values = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT facet_value FROM {$table} WHERE facet_name = %s ORDER BY facet_value",
            $facet_name
        ) );

        $cap = max( 1, (int) apply_filters( 'hof_pretty_urls_max_values', 500 ) );
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
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter SlugMapperTest`
Expected: OK, 7 tests

- [ ] **Step 6: Commit**

```bash
git add src/Routing/SlugMapperInterface.php src/Routing/SlugMapper.php tests/php/Routing/SlugMapperTest.php
git commit -m "feat(routing): add SlugMapper with version-cached, collision-safe value/slug maps"
```

---

### Task 3: `UrlCodec` — the pure heart

**Files:**
- Create: `src/Routing/UrlCodec.php`
- Test: `tests/php/Routing/UrlCodecTest.php`

Rules locked here:

- **Path-eligible facet:** `display` ∈ `checkbox, radio, dropdown, toggle, hierarchy, swatch, swiper, spin, matrix` AND the mapper says `is_mappable()`. Everything else — ranges, `search`, `ask`, `visual_dna`, `saved_bin`, `pagination`, reserved `_*` keys, unknown keys, over-cap facets — goes to the tail.
- **Canonical ordering:** facets in `hof_facets` saved order; values sorted by slug (`strcmp`). `encode()` output IS the canonical form. The tail is canonically ordered too (configured facets in config order, then remaining keys sorted) so canonical URLs never depend on `$_GET` arrival order.
- **Decode:** odd segment count, unknown facet, non-path-eligible facet, or unresolvable slug → `null` (caller hard-404s). Duplicate name/slug pairs dedupe silently (the 301 layer then collapses `/brand/nike/brand/nike/` to the canonical single form — without dedupe that URL family is a self-canonical crawl trap). Decoded values always come back as lists; numeric facet names surface as int keys (PHP coercion) — callers must cast.
- **Range-shaped values** (`min`/`max` keys) always tail even on a discrete display (defensive).
- `strip_base_path()` lives here too: given a request path and the base segment, return the archive path with the `/{base}/…` suffix removed — shared by SeoManager and AssetLoader.

- [ ] **Step 1: Write the failing test**

Create `tests/php/Routing/UrlCodecTest.php`:

```php
<?php
/**
 * @package HookedOnFacets\Tests
 */

declare(strict_types=1);

namespace HookedOnFacets\Tests\Routing;

use HookedOnFacets\Routing\SlugMapperInterface;
use HookedOnFacets\Routing\UrlCodec;
use PHPUnit\Framework\TestCase;

/**
 * Pure round-trip coverage for the codec: ordering, repeated keys, tail
 * routing, reserved keys, collisions-by-fake, and the hard-404 null returns.
 * No WP — the mapper is a fixture fake.
 */
final class UrlCodecTest extends TestCase {

    /** Fake mapper: brand/color are identity sets; material has a slug map; sku unmappable. */
    private function mapper(): SlugMapperInterface {
        return new class() implements SlugMapperInterface {
            private const SETS = [
                'brand' => [ 'adidas' => 'adidas', 'nike' => 'nike' ],
                'color' => [ 'blue' => 'blue', 'red' => 'red' ],
                'material' => [ 'Solid Oak' => 'solid-oak', 'Walnut' => 'walnut' ],
            ];
            public function slug( string $f, string $v ): ?string {
                return self::SETS[ $f ][ $v ] ?? null;
            }
            public function value( string $f, string $s ): ?string {
                $flipped = array_flip( self::SETS[ $f ] ?? [] );
                return $flipped[ $s ] ?? null;
            }
            public function is_mappable( string $f ): bool {
                return isset( self::SETS[ $f ] );
            }
            public function client_map( string $f ): ?array {
                return null;
            }
        };
    }

    private function codec( string $base = 'filter' ): UrlCodec {
        $defs = [
            [ 'name' => 'brand', 'kind' => 'taxonomy', 'display' => 'checkbox' ],
            [ 'name' => 'color', 'kind' => 'taxonomy', 'display' => 'swatch' ],
            [ 'name' => 'material', 'kind' => 'meta', 'display' => 'dropdown' ],
            [ 'name' => 'price', 'kind' => 'meta', 'display' => 'range' ],
            [ 'name' => 'sku', 'kind' => 'meta', 'display' => 'checkbox' ], // unmappable → tail
        ];
        return new UrlCodec( $defs, $this->mapper(), $base );
    }

    // ── encode ───────────────────────────────────────────────────────────────

    public function test_encode_single_facet_single_value(): void {
        $out = $this->codec()->encode( [ 'brand' => [ 'nike' ] ] );
        self::assertSame( '/filter/brand/nike/', $out['path'] );
        self::assertSame( [], $out['tail'] );
    }

    public function test_encode_orders_facets_by_config_and_values_by_slug(): void {
        // State arrives color-first with values reversed — canonical output anyway.
        $out = $this->codec()->encode( [
            'color' => [ 'red', 'blue' ],
            'brand' => [ 'nike', 'adidas' ],
        ] );
        self::assertSame( '/filter/brand/adidas/brand/nike/color/blue/color/red/', $out['path'] );
    }

    public function test_encode_scalar_value_accepted(): void {
        $out = $this->codec()->encode( [ 'brand' => 'nike' ] );
        self::assertSame( '/filter/brand/nike/', $out['path'] );
    }

    public function test_encode_range_search_reserved_and_unmappable_go_to_tail(): void {
        $state = [
            'brand'    => [ 'nike' ],
            'price'    => [ 'min' => 10.0, 'max' => 50.0 ],
            'search'   => 'oak desk',
            'sku'      => [ 'A100' ],
            '_bin_ids' => [ 12, 34 ],
        ];
        $out = $this->codec()->encode( $state );
        self::assertSame( '/filter/brand/nike/', $out['path'] );
        self::assertSame(
            [
                'price'    => [ 'min' => 10.0, 'max' => 50.0 ],
                'search'   => 'oak desk',
                'sku'      => [ 'A100' ],
                '_bin_ids' => [ 12, 34 ],
            ],
            $out['tail']
        );
    }

    public function test_encode_meta_value_uses_slug(): void {
        $out = $this->codec()->encode( [ 'material' => [ 'Solid Oak' ] ] );
        self::assertSame( '/filter/material/solid-oak/', $out['path'] );
    }

    public function test_encode_empty_or_tail_only_state_has_empty_path(): void {
        self::assertSame( '', $this->codec()->encode( [] )['path'] );
        self::assertSame( '', $this->codec()->encode( [ 'search' => 'x' ] )['path'] );
    }

    public function test_encode_respects_custom_base(): void {
        $out = $this->codec( 'f' )->encode( [ 'brand' => [ 'nike' ] ] );
        self::assertSame( '/f/brand/nike/', $out['path'] );
    }

    // ── decode ───────────────────────────────────────────────────────────────

    public function test_decode_accumulates_repeated_keys(): void {
        self::assertSame(
            [ 'brand' => [ 'adidas', 'nike' ], 'color' => [ 'blue' ] ],
            $this->codec()->decode( 'brand/adidas/brand/nike/color/blue' )
        );
    }

    public function test_decode_resolves_meta_slug_to_value(): void {
        self::assertSame(
            [ 'material' => [ 'Solid Oak' ] ],
            $this->codec()->decode( 'material/solid-oak' )
        );
    }

    public function test_decode_unknown_facet_is_null(): void {
        self::assertNull( $this->codec()->decode( 'ghost/nike' ) );
    }

    public function test_decode_unknown_value_is_null(): void {
        self::assertNull( $this->codec()->decode( 'brand/puma' ) );
    }

    public function test_decode_non_path_facet_is_null(): void {
        self::assertNull( $this->codec()->decode( 'price/10' ) );
        self::assertNull( $this->codec()->decode( 'sku/a100' ) );
    }

    public function test_decode_odd_segments_or_empty_is_null(): void {
        self::assertNull( $this->codec()->decode( 'brand' ) );
        self::assertNull( $this->codec()->decode( '' ) );
    }

    public function test_decode_tolerates_surrounding_slashes(): void {
        self::assertSame(
            [ 'brand' => [ 'nike' ] ],
            $this->codec()->decode( '/brand/nike/' )
        );
    }

    // ── round trip + strip ──────────────────────────────────────────────────

    public function test_round_trip_discrete_state_is_stable(): void {
        $state = [ 'brand' => [ 'adidas', 'nike' ], 'material' => [ 'Walnut' ] ];
        $codec = $this->codec();
        $enc   = $codec->encode( $state );
        // '/filter/…' → codec path input strips the base segment.
        $inner = preg_replace( '#^/filter/#', '', rtrim( $enc['path'], '/' ) );
        self::assertSame( $state, $codec->decode( $inner ) );
    }

    public function test_strip_base_path_removes_filter_suffix(): void {
        $codec = $this->codec();
        self::assertSame( '/shop/', $codec->strip_base_path( '/shop/filter/brand/nike/' ) );
        self::assertSame( '/shop/', $codec->strip_base_path( '/shop/' ) );
        // Only a whole segment named "filter" triggers the strip.
        self::assertSame( '/shop-filter/x/', $codec->strip_base_path( '/shop-filter/x/' ) );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter UrlCodecTest`
Expected: ERROR — `Class "HookedOnFacets\Routing\UrlCodec" not found`

- [ ] **Step 3: Write the implementation**

Create `src/Routing/UrlCodec.php`:

```php
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
        $segments = [];
        $tail     = [];
        $handled  = [];

        foreach ( $this->facets as $def ) {
            $name = (string) ( $def['name'] ?? '' );
            if ( $name === '' || ! array_key_exists( $name, $state ) ) {
                continue;
            }
            $handled[ $name ] = true;
            $value            = $state[ $name ];

            if ( ! $this->is_path_facet( $def ) || $this->is_range_shaped( $value ) ) {
                $tail[ $name ] = $value;
                continue;
            }

            $values = is_array( $value ) ? $value : [ $value ];
            $slugs  = [];
            foreach ( $values as $v ) {
                $slug = is_scalar( $v ) ? $this->mapper->slug( $name, (string) $v ) : null;
                if ( $slug === null ) {
                    // One unmappable value sends the whole facet to the tail —
                    // a half-path/half-query facet would break round-tripping.
                    $tail[ $name ] = $value;
                    continue 2;
                }
                $slugs[] = $slug;
            }

            sort( $slugs, SORT_STRING );
            foreach ( $slugs as $slug ) {
                $segments[] = rawurlencode( $name );
                $segments[] = rawurlencode( $slug );
            }
        }

        // State keys outside the configured set (reserved _* etc.) → tail.
        foreach ( $state as $name => $value ) {
            if ( ! isset( $handled[ (string) $name ] ) ) {
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter UrlCodecTest`
Expected: OK, 16 tests

- [ ] **Step 5: Run the whole PHP suite to catch fallout**

Run: `composer test`
Expected: OK, no failures

- [ ] **Step 6: Commit**

```bash
git add src/Routing/UrlCodec.php tests/php/Routing/UrlCodecTest.php
git commit -m "feat(routing): add pure UrlCodec with canonical ordering and strict decode"
```

---

### Task 4: `FilterState` provider + Resolver delegation + QueryHook gate

**Files:**
- Create: `src/Routing/FilterState.php`
- Modify: `src/Filter/Resolver.php:256-267` (`parse_request_filters`)
- Modify: `includes/class-hof-query-hook.php:123-125` (`should_intercept` gate)
- Test: `tests/php/Routing/FilterStateTest.php`

The pivotal wiring move: `Resolver::parse_request_filters()` becomes a thin delegate to `FilterState::current()`. Every existing caller — QueryHook, SeoManager, Renderer (4 call sites), AssetLoader, Bricks, Divi — becomes pretty-path-aware with zero changes. FilterState does the raw `$_GET['hof']` read itself (it cannot call `parse_request_filters()` back — infinite recursion).

- [ ] **Step 1: Write the failing test**

Create `tests/php/Routing/FilterStateTest.php`:

```php
<?php
/**
 * @package HookedOnFacets\Tests
 */

declare(strict_types=1);

namespace HookedOnFacets\Tests\Routing;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HookedOnFacets\Routing\FilterState;
use PHPUnit\Framework\TestCase;

/**
 * Covers the merged-state provider: legacy-only, path ⊕ tail with path
 * winning per key, invalid-path flagging, memoization, and reset().
 */
final class FilterStateTest extends TestCase {

    /** @var array<string, mixed> */
    private array $options = [];

    private string $query_var = '';

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $_GET            = [];
        $this->options   = [];
        $this->query_var = '';
        FilterState::reset();

        Functions\when( 'get_option' )->alias( fn( $name, $default = false ) => $this->options[ $name ] ?? $default );
        Functions\when( 'get_query_var' )->alias( fn( $name, $default = '' ) => $name === 'hof_path' ? $this->query_var : $default );
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'sanitize_text_field' )->alias( static fn( $s ) => trim( (string) $s ) );
        Functions\when( 'sanitize_title' )->alias(
            static fn( $t ) => trim( preg_replace( '/[^a-z0-9-]+/', '-', strtolower( (string) $t ) ), '-' )
        );
        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );

        // Index rows backing the slug maps: one taxonomy facet "brand".
        $GLOBALS['wpdb'] = new class() {
            public string $prefix = 'wp_';
            public function prepare( string $sql, ...$args ): string {
                return str_replace( '%s', "'" . $args[0] . "'", $sql );
            }
            public function get_col( string $sql ): array {
                return str_contains( $sql, "'brand'" ) ? [ 'adidas', 'nike' ] : [];
            }
        };
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wpdb'] );
        $_GET = [];
        FilterState::reset();
        Monkey\tearDown();
        parent::tearDown();
    }

    private function enablePretty( bool $enabled = true ): void {
        $this->options['permalink_structure'] = '/%postname%/';
        $this->options['hof_pretty_urls']     = [ 'enabled' => $enabled, 'base' => 'filter' ];
        $this->options['hof_facets']          = [
            [ 'name' => 'brand', 'kind' => 'taxonomy', 'display' => 'checkbox' ],
        ];
    }

    public function test_legacy_query_only(): void {
        $_GET['hof'] = [ 'brand' => [ 'nike' ] ];
        self::assertSame( [ 'brand' => [ 'nike' ] ], FilterState::current() );
    }

    public function test_disabled_pretty_ignores_hof_path(): void {
        $this->enablePretty( false );
        $this->query_var = 'brand/nike';
        self::assertSame( [], FilterState::current() );
    }

    public function test_path_state_merges_over_tail(): void {
        $this->enablePretty();
        $this->query_var = 'brand/nike';
        // Tail carries a range AND a stale legacy discrete key — path wins per key.
        $_GET['hof'] = [ 'price' => [ 'min' => '10' ], 'brand' => [ 'stale' ] ];

        self::assertSame(
            [ 'price' => [ 'min' => 10.0 ], 'brand' => [ 'nike' ] ],
            FilterState::current()
        );
        self::assertFalse( FilterState::is_path_invalid() );
    }

    public function test_invalid_path_flags_and_returns_tail_only(): void {
        $this->enablePretty();
        $this->query_var = 'brand/puma'; // unknown value
        $_GET['hof']     = [ 'search' => 'desk' ];

        self::assertSame( [ 'search' => 'desk' ], FilterState::current() );
        self::assertTrue( FilterState::is_path_invalid() );
    }

    public function test_memoized_until_reset(): void {
        $_GET['hof'] = [ 'brand' => [ 'nike' ] ];
        self::assertSame( [ 'brand' => [ 'nike' ] ], FilterState::current() );

        $_GET['hof'] = [ 'brand' => [ 'adidas' ] ];
        self::assertSame( [ 'brand' => [ 'nike' ] ], FilterState::current(), 'memoized' );

        FilterState::reset();
        self::assertSame( [ 'brand' => [ 'adidas' ] ], FilterState::current() );
    }

    public function test_codec_null_without_facets(): void {
        self::assertNull( FilterState::codec() );

        $this->enablePretty();
        FilterState::reset(); // codec() memoizes — a config change needs a reset
        self::assertNotNull( FilterState::codec() );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter FilterStateTest`
Expected: ERROR — `Class "HookedOnFacets\Routing\FilterState" not found`

- [ ] **Step 3: Write the implementation**

Create `src/Routing/FilterState.php`:

```php
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
    private static bool $codec_resolved  = false;

    private function __construct() {}

    /**
     * @return array<string, mixed>
     */
    public static function current(): array {
        if ( self::$memo !== null ) {
            return self::$memo;
        }

        $tail = self::legacy_tail();

        if ( ! PrettyUrls::enabled() ) {
            return self::$memo = $tail;
        }

        $path = function_exists( 'get_query_var' ) ? (string) get_query_var( 'hof_path' ) : '';
        if ( $path === '' ) {
            return self::$memo = $tail;
        }

        $codec   = self::codec();
        $decoded = $codec?->decode( $path );
        if ( $decoded === null ) {
            self::$path_invalid = true;
            return self::$memo = $tail;
        }

        // Union; the decoded path wins for any key present in both. A keyed
        // loop, NOT array_merge: array_merge renumbers integer keys, and a
        // digits-only facet name ('56') decodes to an int key (PHP array-key
        // coercion) — merge would silently rename facet 56 to facet 0.
        $state = $tail;
        foreach ( $decoded as $name => $values ) {
            $state[ $name ] = $values;
        }
        return self::$memo = $state;
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
        if ( self::$codec_resolved ) {
            return self::$codec;
        }
        self::$codec_resolved = true;

        $defs = get_option( Indexer::OPTION_FACETS, [] );
        $defs = is_array( $defs ) ? array_values( array_filter( $defs, 'is_array' ) ) : [];
        if ( $defs === [] ) {
            return self::$codec = null;
        }

        $by_name = [];
        foreach ( $defs as $def ) {
            if ( isset( $def['name'] ) ) {
                $by_name[ (string) $def['name'] ] = $def;
            }
        }

        return self::$codec = new UrlCodec( $defs, new SlugMapper( $by_name ), PrettyUrls::base() );
    }

    /** Drop all memoized state (tests, and anything that mutates facet config mid-request). */
    public static function reset(): void {
        self::$memo           = null;
        self::$path_invalid   = false;
        self::$codec          = null;
        self::$codec_resolved = false;
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter FilterStateTest`
Expected: OK, 6 tests

- [ ] **Step 5: Delegate `Resolver::parse_request_filters()`**

In `src/Filter/Resolver.php`, replace the body of `parse_request_filters()` (lines ~256–267):

```php
    /**
     * Read the current request's filter state — pretty path ⊕ legacy ?hof[*].
     *
     * Delegates to Routing\FilterState so every caller is pretty-URL-aware.
     * Kept as the public API; FilterState owns the raw request read.
     *
     * @return array<string, mixed>
     */
    public static function parse_request_filters(): array {
        return \HookedOnFacets\Routing\FilterState::current();
    }
```

- [ ] **Step 6: Widen the QueryHook main-query gate**

In `includes/class-hof-query-hook.php`, replace line ~124 (the final `return` of `should_intercept()`):

```php
        // Only intercept main query if the URL actually carries facet state —
        // legacy ?hof[*] params or a pretty /filter/ path.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return ! empty( $_GET['hof'] ?? null ) || '' !== (string) $query->get( 'hof_path' );
```

- [ ] **Step 7: Run the full PHP suite**

Run: `composer test`
Expected: OK. Watch specifically for `ResolverTest` / `SeoManagerTest` / `RendererHierarchyTest` failures — any test that previously stubbed only `$_GET` now flows through `FilterState`, which additionally calls `get_option` (returns default `[]` → pretty disabled → legacy path) and `wp_unslash`/`sanitize_text_field`. If a test fails on an un-stubbed `get_option` or on cross-test memoization, add `FilterState::reset()` to that test class's `setUp()` and stub `get_option` as in `FilterStateTest`. Do not change production code to make old tests pass.

- [ ] **Step 8: Commit**

```bash
git add src/Routing/FilterState.php src/Filter/Resolver.php includes/class-hof-query-hook.php tests/php/Routing/FilterStateTest.php
git commit -m "feat(routing): FilterState provider merges pretty path with hof query tail"
```

---

### Task 5: `RewriteManager` + service registration + activation flag

**Files:**
- Create: `src/Routing/RewriteManager.php`
- Modify: `src/Plugin.php:209-230` (`core_services`)
- Modify: `includes/class-hof-activator.php:52-56` (`install_for_current_site`)
- Test: `tests/php/Routing/RewriteManagerTest.php`

Rule generation is pure (`build_rules()`, static) and unit-tested; base discovery (`bases()`) touches WP/WC and stays thin glue verified manually in the Docker sandbox.

- [ ] **Step 1: Write the failing test**

Create `tests/php/Routing/RewriteManagerTest.php`:

```php
<?php
/**
 * @package HookedOnFacets\Tests
 */

declare(strict_types=1);

namespace HookedOnFacets\Tests\Routing;

use Brain\Monkey;
use HookedOnFacets\Routing\RewriteManager;
use PHPUnit\Framework\TestCase;

/**
 * Covers the pure rewrite-rule generation: plain + paginated variants per
 * base, capture renumbering after taxonomy captures, custom base segment.
 */
final class RewriteManagerTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_shop_base_rules(): void {
        $rules = RewriteManager::build_rules(
            [ [ 'prefix' => 'shop', 'query' => 'post_type=product', 'captures' => 0 ] ],
            'filter'
        );

        self::assertSame(
            [
                '^shop/filter/(.+?)/page/([0-9]{1,})/?$' => 'index.php?post_type=product&hof_path=$matches[1]&paged=$matches[2]',
                '^shop/filter/(.+?)/?$'                  => 'index.php?post_type=product&hof_path=$matches[1]',
            ],
            $rules
        );
    }

    public function test_taxonomy_base_renumbers_captures(): void {
        $rules = RewriteManager::build_rules(
            [ [ 'prefix' => 'product-category/(.+?)', 'query' => 'product_cat=$matches[1]', 'captures' => 1 ] ],
            'filter'
        );

        self::assertSame(
            [
                '^product-category/(.+?)/filter/(.+?)/page/([0-9]{1,})/?$' => 'index.php?product_cat=$matches[1]&hof_path=$matches[2]&paged=$matches[3]',
                '^product-category/(.+?)/filter/(.+?)/?$'                  => 'index.php?product_cat=$matches[1]&hof_path=$matches[2]',
            ],
            $rules
        );
    }

    public function test_custom_base_segment(): void {
        $rules = RewriteManager::build_rules(
            [ [ 'prefix' => 'shop', 'query' => 'post_type=product', 'captures' => 0 ] ],
            'f'
        );
        self::assertArrayHasKey( '^shop/f/(.+?)/?$', $rules );
    }

    public function test_multiple_bases_paginated_rules_stay_grouped_first(): void {
        $rules = RewriteManager::build_rules(
            [
                [ 'prefix' => 'shop', 'query' => 'post_type=product', 'captures' => 0 ],
                [ 'prefix' => 'product-tag/([^/]+)', 'query' => 'product_tag=$matches[1]', 'captures' => 1 ],
            ],
            'filter'
        );
        // Paginated must precede plain per base so /page/2/ isn't swallowed by (.+?).
        $keys = array_keys( $rules );
        self::assertStringContainsString( '/page/', $keys[0] );
        self::assertCount( 4, $rules );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter RewriteManagerTest`
Expected: ERROR — class not found

- [ ] **Step 3: Write the implementation**

Create `src/Routing/RewriteManager.php`:

```php
<?php
/**
 * RewriteManager — pretty-URL plumbing.
 *
 *   - Registers the hof_path public query var.
 *   - When pretty URLs are enabled, adds two rewrite rules (plain +
 *     paginated) per WooCommerce storefront base: the shop page, product
 *     category/tag archives, and every product attribute archive. Bases are
 *     read from each object's registered rewrite config, never hardcoded.
 *   - Never flushes on normal loads: PrettyUrls::update() sets a flag,
 *     consumed here on the next admin_init (activation sets it too).
 *   - 404-guards invalid paths on parse_query: an unresolvable segment is a
 *     hard 404, not a soft-404 duplicate page.
 *
 * @package HookedOnFacets
 */

declare(strict_types=1);

namespace HookedOnFacets\Routing;

use HookedOnFacets\Contracts\Bootable;

defined( 'ABSPATH' ) || exit;

final class RewriteManager implements Bootable {

    public function register_hooks(): void {
        add_filter( 'query_vars', [ $this, 'register_query_var' ] );
        // Priority 20: after WooCommerce registers its taxonomies (init 5)
        // and the shop page is resolvable.
        add_action( 'init', [ $this, 'register_rules' ], 20 );
        add_action( 'admin_init', [ $this, 'maybe_flush' ] );
        add_action( 'parse_query', [ $this, 'guard_invalid_path' ] );
    }

    /**
     * @param array<int, string> $vars
     * @return array<int, string>
     */
    public function register_query_var( array $vars ): array {
        $vars[] = 'hof_path';
        return $vars;
    }

    public function register_rules(): void {
        if ( ! PrettyUrls::enabled() ) {
            return;
        }
        foreach ( self::build_rules( $this->bases(), PrettyUrls::base() ) as $regex => $target ) {
            add_rewrite_rule( $regex, $target, 'top' );
        }
    }

    /** Deferred flush — set by PrettyUrls::update() and plugin activation. */
    public function maybe_flush(): void {
        if ( ! get_option( PrettyUrls::FLUSH_FLAG ) ) {
            return;
        }
        delete_option( PrettyUrls::FLUSH_FLAG );
        flush_rewrite_rules( false );
    }

    /** Unresolvable pretty path → hard 404 on the main query. */
    public function guard_invalid_path( \WP_Query $query ): void {
        if ( ! $query->is_main_query() ) {
            return;
        }
        $path = (string) $query->get( 'hof_path' );
        if ( $path === '' || ! PrettyUrls::enabled() ) {
            return;
        }
        if ( FilterState::codec()?->decode( $path ) === null ) {
            $query->set_404();
        }
    }

    /**
     * Pure rule generation. Each base contributes a paginated rule first
     * (so /page/N/ isn't swallowed by the greedy hof_path capture), then the
     * plain rule.
     *
     * @param list<array{prefix: string, query: string, captures: int}> $bases
     * @return array<string, string> regex → target
     */
    public static function build_rules( array $bases, string $filter_base ): array {
        $rules = [];
        $base  = preg_quote( $filter_base, '#' );

        foreach ( $bases as $b ) {
            $n     = (int) $b['captures'];
            $path  = $n + 1; // hof_path capture index
            $paged = $n + 2;

            $rules[ "^{$b['prefix']}/{$base}/(.+?)/page/([0-9]{1,})/?$" ] =
                "index.php?{$b['query']}&hof_path=\$matches[{$path}]&paged=\$matches[{$paged}]";
            $rules[ "^{$b['prefix']}/{$base}/(.+?)/?$" ] =
                "index.php?{$b['query']}&hof_path=\$matches[{$path}]";
        }

        return $rules;
    }

    /**
     * Storefront bases, read from live WP/WC registration. Empty when
     * WooCommerce is absent.
     *
     * @return list<array{prefix: string, query: string, captures: int}>
     */
    private function bases(): array {
        $bases = [];

        if ( function_exists( 'wc_get_page_id' ) ) {
            $shop_id = (int) wc_get_page_id( 'shop' );
            $uri     = $shop_id > 0 ? get_page_uri( $shop_id ) : '';
            if ( is_string( $uri ) && $uri !== '' ) {
                $bases[] = [
                    'prefix'   => preg_quote( trim( $uri, '/' ), '#' ),
                    'query'    => 'post_type=product',
                    'captures' => 0,
                ];
            }
        }

        foreach ( [ 'product_cat' => '(.+?)', 'product_tag' => '([^/]+)' ] as $taxonomy => $capture ) {
            $tax = get_taxonomy( $taxonomy );
            if ( $tax && ! empty( $tax->rewrite['slug'] ) ) {
                $bases[] = [
                    'prefix'   => preg_quote( trim( (string) $tax->rewrite['slug'], '/' ), '#' ) . '/' . $capture,
                    'query'    => "{$taxonomy}=\$matches[1]",
                    'captures' => 1,
                ];
            }
        }

        if ( function_exists( 'wc_get_attribute_taxonomies' ) && function_exists( 'wc_attribute_taxonomy_name' ) ) {
            foreach ( (array) wc_get_attribute_taxonomies() as $attribute ) {
                $taxonomy = wc_attribute_taxonomy_name( (string) $attribute->attribute_name );
                $tax      = get_taxonomy( $taxonomy );
                if ( $tax && ! empty( $tax->rewrite['slug'] ) ) {
                    $bases[] = [
                        'prefix'   => preg_quote( trim( (string) $tax->rewrite['slug'], '/' ), '#' ) . '/([^/]+)',
                        'query'    => "{$taxonomy}=\$matches[1]",
                        'captures' => 1,
                    ];
                }
            }
        }

        /**
         * Filter the storefront bases pretty-URL rules are registered on.
         *
         * @param list<array{prefix: string, query: string, captures: int}> $bases
         */
        return (array) apply_filters( 'hof_pretty_urls_bases', $bases );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit --filter RewriteManagerTest`
Expected: OK, 4 tests

- [ ] **Step 5: Register the service**

In `src/Plugin.php`, add to the `core_services()` array (after the `Seo\SeoManager::class` entry, line ~223):

```php
            \HookedOnFacets\Routing\RewriteManager::class,
```

(Zero-arg constructor — no `register_bindings()` entry needed.)

- [ ] **Step 6: Set the flush flag on activation**

In `includes/class-hof-activator.php`, `install_for_current_site()` (line ~52), add one line after the `update_option( self::DB_VERSION_OPTION, ... )` call:

```php
        // Rewrite rules (if pretty URLs are enabled) need a flush after
        // (re)activation; RewriteManager consumes this on the next admin_init.
        update_option( \HookedOnFacets\Routing\PrettyUrls::FLUSH_FLAG, 1, false );
```

- [ ] **Step 7: Run the full PHP suite**

Run: `composer test`
Expected: OK

- [ ] **Step 8: Commit**

```bash
git add src/Routing/RewriteManager.php src/Plugin.php includes/class-hof-activator.php tests/php/Routing/RewriteManagerTest.php
git commit -m "feat(routing): rewrite rules on storefront bases with deferred flush and 404 guard"
```

---

### Task 6: Settings — REST + admin UI

**Files:**
- Modify: `src/Api/RestController.php:357-385` (`get_seo_settings` / `save_seo_settings`)
- Modify: `admin/src/components/SeoSettings.jsx`

The `hof_pretty_urls` option rides the existing `/seo-settings` route (its natural admin home) but stays a separate option so a rewrite flush only triggers when *these* fields change. No new PHP test file: the coercion logic lives in `PrettyUrls::update()` (Task 1, tested); the handler wiring below is thin glue.

- [ ] **Step 1: Extend the REST handlers**

In `src/Api/RestController.php`, replace `get_seo_settings()` (line ~357):

```php
    public function get_seo_settings( \WP_REST_Request $request ): \WP_REST_Response {
        if ( ! $this->seo ) {
            return new \WP_REST_Response( [ 'available' => false, 'settings' => SeoManager::defaults() ], 200 );
        }
        $pretty = \HookedOnFacets\Routing\PrettyUrls::settings();
        return new \WP_REST_Response( [
            'available'   => true,
            'settings'    => $this->seo->settings(),
            'pretty_urls' => [
                'enabled'   => $pretty['enabled'],
                'base'      => $pretty['base'],
                'available' => \HookedOnFacets\Routing\PrettyUrls::available(),
            ],
        ], 200 );
    }
```

And in `save_seo_settings()` (line ~367), insert **before** the final `return $this->get_seo_settings( $request );`:

```php
        $pretty = $request->get_param( 'pretty_urls' );
        if ( is_array( $pretty ) ) {
            \HookedOnFacets\Routing\PrettyUrls::update( [
                'enabled' => ! empty( $pretty['enabled'] ),
                'base'    => (string) ( $pretty['base'] ?? 'filter' ),
            ] );
        }
```

And extend the GET response's `pretty_urls` array with a collision warning (a term or
page slugged exactly like the base segment gets its nested/paginated URLs captured by
the rewrite rules — Task 5 review finding). Add a `warning` key computed like:

```php
        $base    = \HookedOnFacets\Routing\PrettyUrls::base();
        $warning = null;
        foreach ( [ 'product_cat', 'product_tag' ] as $collision_tax ) {
            if ( function_exists( 'term_exists' ) && term_exists( $base, $collision_tax ) ) {
                $warning = sprintf(
                    /* translators: %s: the configured URL segment */
                    __( 'A store term is slugged "%s" — its archive URLs will collide with pretty filter URLs. Change the URL segment below.', 'hooked-on-facets' ),
                    $base
                );
                break;
            }
        }
        // ...include as 'warning' => $warning in the pretty_urls response array;
        // SeoSettings.jsx renders it as an error-styled notice when non-null.
```

- [ ] **Step 2: Extend the admin screen**

In `admin/src/components/SeoSettings.jsx`:

a. Add state + wire the GET response (inside the component, after the existing `useState` calls, and in the fetch effect after `setSettings(...)`):

```jsx
    const [prettyUrls, setPrettyUrls] = useState(null);
```

```jsx
                setPrettyUrls(data.pretty_urls || null);
```

b. Add a save path for the pretty fields (below the existing `update` function):

```jsx
    // Pretty URLs persist through the same route but as their own option, so
    // a rewrite flush only fires when these two fields change.
    const updatePretty = (key, value) => {
        const next = { ...prettyUrls, [key]: value };
        setPrettyUrls(next);
        (async () => {
            setSaving(true);
            setError('');
            setSuccess('');
            try {
                const res = await fetch(`${restUrl}seo-settings`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                    body: JSON.stringify({ settings, pretty_urls: { enabled: next.enabled, base: next.base } }),
                });
                if (!res.ok) throw new Error(`HTTP ${res.status}`);
                const data = await res.json();
                setPrettyUrls(data.pretty_urls || next);
                setSuccess('Saved. Permalinks refresh on your next visit to any admin page.');
            } catch (e) {
                setError(e.message || 'Save failed.');
            } finally {
                setSaving(false);
            }
        })();
    };
```

c. Render the section (after the existing `</section>`, before the component's closing `</div>`):

```jsx
            {prettyUrls && (
                <section className="hof-ai-settings-section">
                    <h3 className="hof-ai-settings-section-title">Pretty faceted URLs</h3>
                    {!prettyUrls.available && (
                        <p className="hof-ai-settings-msg hof-ai-settings-msg--error">
                            Pretty URLs need non-plain permalinks. Set a permalink structure under
                            Settings → Permalinks first.
                        </p>
                    )}
                    <div className="hof-ai-settings-input-row">
                        <label className="hof-ai-settings-label">
                            <input
                                type="checkbox"
                                checked={!!prettyUrls.enabled}
                                disabled={saving || !prettyUrls.available}
                                onChange={(e) => updatePretty('enabled', e.target.checked)}
                            />
                            {' '}Rewrite filters into the path
                        </label>
                        <p className="hof-ai-settings-row-sub">
                            Turns <code>/shop/?hof[brand]=nike</code> into <code>/shop/filter/brand/nike/</code> on
                            the shop and product archives — clean, crawlable, shareable. Legacy query URLs
                            301-redirect to the pretty form.
                        </p>
                    </div>
                    <div className="hof-ai-settings-input-row">
                        <label className="hof-ai-settings-label">
                            URL segment
                            {' '}
                            <input
                                type="text"
                                className="hof-ai-settings-input"
                                style={{ width: '8rem', display: 'inline-block' }}
                                value={prettyUrls.base ?? 'filter'}
                                disabled={saving || !prettyUrls.available}
                                onBlur={(e) => updatePretty('base', e.target.value || 'filter')}
                                onChange={(e) => setPrettyUrls({ ...prettyUrls, base: e.target.value })}
                            />
                        </label>
                        <p className="hof-ai-settings-row-sub">
                            The reserved path segment, default <code>filter</code>. Change it only if a real
                            category or page on your store is literally named “filter”.
                        </p>
                    </div>
                </section>
            )}
```

- [ ] **Step 3: Build the admin bundle to catch JSX errors**

Run: `npm run build`
Expected: clean build, no errors

- [ ] **Step 4: Run the PHP suite**

Run: `composer test`
Expected: OK

- [ ] **Step 5: Commit**

```bash
git add src/Api/RestController.php admin/src/components/SeoSettings.jsx
git commit -m "feat(settings): pretty-URL toggle and base segment on the SEO screen"
```

---

### Task 7: SeoManager — pretty canonical + 301

**Files:**
- Modify: `src/Seo/SeoManager.php`
- Test: `tests/php/SeoManagerTest.php` (extend)

Design locked here:

- One pure workhorse: `pretty_url_for( string $current_url, array $state, UrlCodec $codec ): string`. It strips hof params and the `/{base}/…` suffix from the current URL (preserving a `/page/N/` pagination segment and all non-hof query params), then appends `encode($state)`'s canonical path and tail. Used by both the canonical tag and the redirect decision — one implementation, no drift.
- **The strip MUST also remove a bare `hof_path` query param** (the `str_starts_with( $key, 'hof' )` condition already catches it — keep that condition and pin it with a test). `hof_path` is a public query var, so `/shop/?hof_path=brand/nike` serves the same content as the pretty path, and `$_GET` takes precedence over rule-derived vars in `WP::parse_request` — both forms must 301 to the clean pretty URL, never re-emit `hof_path` in the target (Task 5 review finding).
- `redirect_target(...)` wraps it with the loop guard: returns `''` when the computed target equals the current URL (after trailing-slash normalization) or when nothing is path-eligible.
- Glue: `maybe_redirect()` on `template_redirect` (priority 1) — GET only, pretty enabled, not admin, not an invalid-path 404 (RewriteManager's guard owns that), state non-empty.
- `print_canonical()` prefers the pretty target when pretty is enabled; existing plain behavior (and SEO-plugin deference, and noindex logic) is untouched.

- [ ] **Step 1: Write the failing tests**

Append to `tests/php/SeoManagerTest.php` (inside the class). Also add these imports at the top of the file alongside the existing `use` lines:

```php
use HookedOnFacets\Routing\SlugMapperInterface;
use HookedOnFacets\Routing\UrlCodec;
```

```php
    // ── pretty canonical + redirects ────────────────────────────────────────

    /** Codec fixture: brand (taxonomy checkbox) + price (range). */
    private function prettyCodec(): UrlCodec {
        $mapper = new class() implements SlugMapperInterface {
            public function slug( string $f, string $v ): ?string {
                return $f === 'brand' && in_array( $v, [ 'nike', 'adidas' ], true ) ? $v : null;
            }
            public function value( string $f, string $s ): ?string {
                return $this->slug( $f, $s );
            }
            public function is_mappable( string $f ): bool {
                return $f === 'brand';
            }
            public function client_map( string $f ): ?array {
                return null;
            }
        };
        return new UrlCodec(
            [
                [ 'name' => 'brand', 'kind' => 'taxonomy', 'display' => 'checkbox' ],
                [ 'name' => 'price', 'kind' => 'meta', 'display' => 'range' ],
            ],
            $mapper,
            'filter'
        );
    }

    private function withTrailingSlashit(): void {
        Functions\when( 'user_trailingslashit' )->alias( static fn( $s ) => rtrim( (string) $s, '/' ) . '/' );
    }

    public function test_pretty_url_for_builds_canonical_path_and_tail(): void {
        $this->withOptions();
        $this->withTrailingSlashit();
        $seo = new SeoManager();

        $out = $seo->pretty_url_for(
            'https://shop.test/shop/?hof%5Bbrand%5D%5B%5D=nike&hof%5Bprice%5D%5Bmin%5D=10&utm=x',
            [ 'brand' => [ 'nike' ], 'price' => [ 'min' => 10 ] ],
            $this->prettyCodec()
        );

        self::assertSame(
            'https://shop.test/shop/filter/brand/nike/?utm=x&hof%5Bprice%5D%5Bmin%5D=10',
            $out
        );
    }

    public function test_pretty_url_for_canonicalizes_segment_order(): void {
        $this->withOptions();
        $this->withTrailingSlashit();
        $seo = new SeoManager();

        // Current URL has values in non-canonical order; state is what decode produced.
        $out = $seo->pretty_url_for(
            'https://shop.test/shop/filter/brand/nike/brand/adidas/',
            [ 'brand' => [ 'nike', 'adidas' ] ],
            $this->prettyCodec()
        );

        self::assertSame( 'https://shop.test/shop/filter/brand/adidas/brand/nike/', $out );
    }

    public function test_pretty_url_for_preserves_pagination(): void {
        $this->withOptions();
        $this->withTrailingSlashit();
        $seo = new SeoManager();

        $out = $seo->pretty_url_for(
            'https://shop.test/shop/page/3/?hof%5Bbrand%5D%5B%5D=nike',
            [ 'brand' => [ 'nike' ] ],
            $this->prettyCodec()
        );

        self::assertSame( 'https://shop.test/shop/filter/brand/nike/page/3/', $out );
    }

    public function test_redirect_target_legacy_to_pretty(): void {
        $this->withOptions();
        $this->withTrailingSlashit();
        $seo = new SeoManager();

        $target = $seo->redirect_target(
            'https://shop.test/shop/?hof%5Bbrand%5D%5B%5D=nike',
            [ 'brand' => [ 'nike' ] ],
            $this->prettyCodec()
        );

        self::assertSame( 'https://shop.test/shop/filter/brand/nike/', $target );
    }

    public function test_redirect_target_loop_guard_on_canonical_url(): void {
        $this->withOptions();
        $this->withTrailingSlashit();
        $seo = new SeoManager();

        self::assertSame( '', $seo->redirect_target(
            'https://shop.test/shop/filter/brand/nike/',
            [ 'brand' => [ 'nike' ] ],
            $this->prettyCodec()
        ) );
    }

    public function test_redirect_target_empty_when_nothing_path_eligible(): void {
        $this->withOptions();
        $this->withTrailingSlashit();
        $seo = new SeoManager();

        self::assertSame( '', $seo->redirect_target(
            'https://shop.test/shop/?hof%5Bprice%5D%5Bmin%5D=10',
            [ 'price' => [ 'min' => 10 ] ],
            $this->prettyCodec()
        ) );
    }

    public function test_pretty_url_for_strips_bare_hof_path_param(): void {
        $this->withOptions();
        $this->withTrailingSlashit();
        $seo = new SeoManager();

        // hof_path is a public query var: /shop/?hof_path=brand/nike serves the
        // same content as the pretty path and must canonicalize to it — the
        // target must never re-emit hof_path (else the 301 loop-guard parks a
        // permanent duplicate).
        $out = $seo->pretty_url_for(
            'https://shop.test/shop/?hof_path=brand%2Fnike&utm=x',
            [ 'brand' => [ 'nike' ] ],
            $this->prettyCodec()
        );

        self::assertSame( 'https://shop.test/shop/filter/brand/nike/?utm=x', $out );
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `vendor/bin/phpunit --filter SeoManagerTest`
Expected: FAIL — `pretty_url_for` / `redirect_target` undefined

- [ ] **Step 3: Implement in `SeoManager`**

a. Add imports at the top of `src/Seo/SeoManager.php` (after the existing `use` lines):

```php
use HookedOnFacets\Routing\FilterState;
use HookedOnFacets\Routing\PrettyUrls;
use HookedOnFacets\Routing\UrlCodec;
```

b. In `register_hooks()`, add:

```php
        // Priority 1: redirect before anything renders.
        add_action( 'template_redirect', [ $this, 'maybe_redirect' ], 1 );
```

c. In `print_canonical()`, replace the two lines computing/printing `$canonical` with:

```php
        $canonical = $this->canonical_url( $this->current_url() );
        if ( PrettyUrls::enabled() ) {
            $codec = FilterState::codec();
            $state = Resolver::parse_request_filters();
            if ( $codec && ! empty( $state ) ) {
                $pretty = $this->pretty_url_for( $this->current_url(), $state, $codec );
                if ( $pretty !== '' ) {
                    $canonical = $pretty;
                }
            }
        }
        if ( $canonical !== '' ) {
            printf( "<link rel=\"canonical\" href=\"%s\" />\n", esc_url( $canonical ) );
        }
```

d. Add the new public methods (place after `canonical_url()`, in the pure-logic section):

```php
    /**
     * The canonical pretty URL for a filter state on the current surface:
     * archive base path (pretty/hof cruft stripped, /page/N/ preserved) +
     * canonical-ordered /base/facet/slug segments + the non-path tail as
     * ?hof[*] alongside preserved non-hof params. Empty string when the URL
     * can't be parsed.
     *
     * @param array<string, mixed> $state
     */
    public function pretty_url_for( string $current_url, array $state, UrlCodec $codec ): string {
        $parts = wp_parse_url( $current_url );
        if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
            return '';
        }

        $path = (string) ( $parts['path'] ?? '/' );

        // Peel pagination off before stripping so it survives the rebuild.
        $page_suffix = '';
        if ( preg_match( '#/page/([0-9]+)/?$#', $path, $m ) ) {
            $page_suffix = '/page/' . $m[1] . '/';
            $path        = (string) preg_replace( '#/page/[0-9]+/?$#', '/', $path );
        }

        $base_path = $codec->strip_base_path( $path );
        $encoded   = $codec->encode( $state );

        $new_path = rtrim( $base_path, '/' ) . ( $encoded['path'] !== '' ? $encoded['path'] : '/' );
        $new_path = user_trailingslashit( rtrim( $new_path . ltrim( $page_suffix, '/' ), '/' ) );

        // Non-hof query params survive; the tail re-encodes as hof[*].
        $query = [];
        if ( ! empty( $parts['query'] ) ) {
            parse_str( (string) $parts['query'], $query );
            foreach ( array_keys( $query ) as $key ) {
                if ( $key === 'hof' || str_starts_with( (string) $key, 'hof' ) ) {
                    unset( $query[ $key ] );
                }
            }
        }
        if ( ! empty( $encoded['tail'] ) ) {
            $query['hof'] = $encoded['tail'];
        }

        $scheme = $parts['scheme'] ?? 'https';
        $url    = $scheme . '://' . $parts['host']
            . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' )
            . $new_path;

        $qs = http_build_query( $query );
        return $qs !== '' ? $url . '?' . $qs : $url;
    }

    /**
     * Where the current request should 301 to — '' for "don't redirect".
     * Covers legacy ?hof[*] → pretty and non-canonical segment order →
     * canonical order. Loop-guarded by comparing against the current URL.
     *
     * @param array<string, mixed> $state
     */
    public function redirect_target( string $current_url, array $state, UrlCodec $codec ): string {
        $encoded = $codec->encode( $state );
        if ( $encoded['path'] === '' ) {
            return ''; // Nothing path-eligible — no pretty form exists.
        }

        $target = $this->pretty_url_for( $current_url, $state, $codec );
        if ( $target === '' ) {
            return '';
        }

        $normalize = static fn( string $u ): string => rtrim( rawurldecode( $u ), '/' );
        return $normalize( $target ) === $normalize( $current_url ) ? '' : $target;
    }

    /**
     * template_redirect glue: 301 legacy/non-canonical faceted URLs to the
     * canonical pretty form. GET only; invalid paths are the 404 guard's
     * problem, not ours.
     */
    public function maybe_redirect(): void {
        if ( is_admin() || ! PrettyUrls::enabled() ) {
            return;
        }
        if ( ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) !== 'GET' ) {
            return;
        }
        if ( FilterState::is_path_invalid() ) {
            return;
        }
        $codec = FilterState::codec();
        $state = Resolver::parse_request_filters();
        if ( ! $codec || empty( $state ) ) {
            return;
        }
        $target = $this->redirect_target( $this->current_url(), $state, $codec );
        if ( $target !== '' ) {
            wp_safe_redirect( $target, 301 );
            exit;
        }
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `vendor/bin/phpunit --filter SeoManagerTest`
Expected: OK (all previous tests + 6 new)

- [ ] **Step 5: Run the full PHP suite**

Run: `composer test`
Expected: OK

- [ ] **Step 6: Commit**

```bash
git add src/Seo/SeoManager.php tests/php/SeoManagerTest.php
git commit -m "feat(seo): pretty canonical target and 301 legacy/non-canonical redirects"
```

---

### Task 8: Renderer — crawlable links

**Files:**
- Modify: `src/Facets/Renderer.php`
- Test: `tests/php/RendererPrettyLinksTest.php` (create)

One private helper computes the toggled-state link; the discrete list displays (checkbox `:257`, radio `:306`, hierarchy `:455`, swatch `:710`) swap their `<span class="hof-facet-name">` label for an anchor when a link exists; dropdown (`:363`) appends a hidden crawlable list. Toggle/swiper/spin/matrix keep JS-only behavior (spec scope).

- [ ] **Step 1: Write the failing test**

Create `tests/php/RendererPrettyLinksTest.php`:

```php
<?php
/**
 * @package HookedOnFacets\Tests
 */

declare(strict_types=1);

namespace HookedOnFacets\Tests;

use Brain\Monkey;
use Brain\Monkey\Functions;
use HookedOnFacets\Facets\Renderer;
use HookedOnFacets\Filter\Resolver;
use HookedOnFacets\Routing\FilterState;
use PHPUnit\Framework\TestCase;

/**
 * Covers crawlable pretty-link emission in the checkbox display: anchor when
 * pretty URLs are on, plain span when off, toggled-state hrefs.
 */
final class RendererPrettyLinksTest extends TestCase {

    /** @var array<string, mixed> */
    private array $options = [];

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        $_GET          = [];
        $this->options = [];
        FilterState::reset();

        Functions\when( 'get_option' )->alias( fn( $name, $default = false ) => $this->options[ $name ] ?? $default );
        Functions\when( 'get_query_var' )->justReturn( '' );
        Functions\when( 'wp_unslash' )->returnArg();
        Functions\when( 'sanitize_text_field' )->alias( static fn( $s ) => trim( (string) $s ) );
        Functions\when( 'sanitize_title' )->alias(
            static fn( $t ) => trim( preg_replace( '/[^a-z0-9-]+/', '-', strtolower( (string) $t ) ), '-' )
        );
        Functions\when( 'esc_attr' )->returnArg();
        Functions\when( 'esc_html' )->returnArg();
        Functions\when( 'esc_url' )->returnArg();
        Functions\when( 'esc_html_e' )->alias( static function ( $s ) { echo $s; } );
        Functions\when( 'checked' )->justReturn( '' );
        Functions\when( 'number_format_i18n' )->alias( static fn( $n ) => (string) $n );
        Functions\when( 'user_trailingslashit' )->alias( static fn( $s ) => rtrim( (string) $s, '/' ) . '/' );
        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );

        $_SERVER['HTTP_HOST']   = 'shop.test';
        $_SERVER['REQUEST_URI'] = '/shop/';
        Functions\when( 'is_ssl' )->justReturn( true );

        $GLOBALS['wpdb'] = new class() {
            public string $prefix = 'wp_';
            public function prepare( string $sql, ...$args ): string {
                return str_replace( '%s', "'" . $args[0] . "'", $sql );
            }
            public function get_col( string $sql ): array {
                return str_contains( $sql, "'brand'" ) ? [ 'adidas', 'nike' ] : [];
            }
        };
    }

    protected function tearDown(): void {
        unset( $GLOBALS['wpdb'] );
        $_GET = [];
        FilterState::reset();
        Monkey\tearDown();
        parent::tearDown();
    }

    private function withFacets( bool $pretty ): void {
        $this->options['hof_facets'] = [
            [ 'name' => 'brand', 'label' => 'Brand', 'kind' => 'taxonomy', 'display' => 'checkbox', 'settings' => [] ],
        ];
        $this->options['permalink_structure'] = '/%postname%/';
        $this->options['hof_pretty_urls']     = [ 'enabled' => $pretty, 'base' => 'filter' ];
    }

    /**
     * Invoke the private render_checkbox directly, same pattern as
     * RendererHierarchyTest — no resolver round-trip needed.
     *
     * @param list<string> $selected
     */
    private function renderCheckbox( array $selected ): string {
        $facet  = [ 'name' => 'brand', 'label' => 'Brand', 'kind' => 'taxonomy', 'display' => 'checkbox', 'settings' => [] ];
        $counts = [
            'type'    => 'values',
            'buckets' => [
                [ 'value' => 'adidas', 'display' => 'Adidas', 'count' => 4 ],
                [ 'value' => 'nike', 'display' => 'Nike', 'count' => 6 ],
            ],
        ];

        $method = new \ReflectionMethod( Renderer::class, 'render_checkbox' );
        $method->setAccessible( true );
        return (string) $method->invoke( new Renderer( new Resolver() ), $facet, $selected, $counts );
    }

    public function test_pretty_on_emits_toggle_anchors(): void {
        $this->withFacets( true );
        $html = $this->renderCheckbox( [] );

        self::assertStringContainsString( 'class="hof-facet-link hof-facet-name"', $html );
        self::assertStringContainsString( 'href="https://shop.test/shop/filter/brand/nike/"', $html );
        self::assertStringContainsString( 'href="https://shop.test/shop/filter/brand/adidas/"', $html );
    }

    public function test_pretty_on_selected_value_links_to_removal(): void {
        $this->withFacets( true );
        $_GET['hof'] = [ 'brand' => [ 'nike' ] ]; // pretty_link reads request state
        $html        = $this->renderCheckbox( [ 'nike' ] );

        // Nike is active → its link removes the filter (back to clean base).
        self::assertStringContainsString( 'href="https://shop.test/shop/"', $html );
        // Adidas link adds itself alongside nike (canonical order: adidas first).
        self::assertStringContainsString( 'href="https://shop.test/shop/filter/brand/adidas/brand/nike/"', $html );
    }

    public function test_pretty_off_keeps_plain_spans(): void {
        $this->withFacets( false );
        $html = $this->renderCheckbox( [] );

        self::assertStringNotContainsString( 'hof-facet-link', $html );
        self::assertStringContainsString( '<span class="hof-facet-name">Nike</span>', $html );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit --filter RendererPrettyLinksTest`
Expected: FAIL — no `hof-facet-link` in output

- [ ] **Step 3: Add the link helper to `Renderer`**

In `src/Facets/Renderer.php`, add imports:

```php
use HookedOnFacets\Routing\FilterState;
use HookedOnFacets\Routing\PrettyUrls;
```

Add the private helper (near `find_facet()`, ~line 1697):

```php
    /**
     * Crawlable pretty URL for toggling one discrete value — '' when pretty
     * URLs are off, the facet isn't path-eligible, or no codec exists. The
     * href is the full filter state with this value added (or removed when
     * already selected), canonically encoded.
     *
     * @param array<string, mixed> $facet
     */
    private function pretty_link( array $facet, string $value ): string {
        if ( ! PrettyUrls::enabled() ) {
            return '';
        }
        $codec = FilterState::codec();
        if ( ! $codec || ! $codec->is_path_facet( $facet ) ) {
            return '';
        }

        $name  = (string) $facet['name'];
        $state = Resolver::parse_request_filters();

        $values = array_map( 'strval', (array) ( $state[ $name ] ?? [] ) );
        $pos    = array_search( $value, $values, true );
        if ( $pos !== false ) {
            unset( $values[ $pos ] );
            $values = array_values( $values );
        } else {
            $values[] = $value;
        }
        if ( $values === [] ) {
            unset( $state[ $name ] );
        } else {
            $state[ $name ] = $values;
        }

        $encoded = $codec->encode( $state );

        $host = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
        $uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
        if ( $host === '' ) {
            return '';
        }
        $path = (string) ( wp_parse_url( $uri )['path'] ?? '/' );
        $path = (string) preg_replace( '#/page/[0-9]+/?$#', '/', $path );
        $base = $codec->strip_base_path( $path );

        $scheme = is_ssl() ? 'https' : 'http';
        $target = rtrim( $base, '/' ) . ( $encoded['path'] !== '' ? $encoded['path'] : '/' );
        $url    = $scheme . '://' . $host . user_trailingslashit( rtrim( $target, '/' ) );

        if ( ! empty( $encoded['tail'] ) ) {
            $url .= '?' . http_build_query( [ 'hof' => $encoded['tail'] ] );
        }
        return $url;
    }
```

- [ ] **Step 4: Swap the label span in the four list displays**

In `render_checkbox()` (~line 285), replace:

```php
                                    <span class="hof-facet-name"><?php echo esc_html( $bucket['display'] ); ?></span>
```

with:

```php
                                    <?php $link = $this->pretty_link( $facet, $value ); ?>
                                    <?php if ( $link !== '' ) : ?>
                                        <a class="hof-facet-link hof-facet-name" href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $bucket['display'] ); ?></a>
                                    <?php else : ?>
                                        <span class="hof-facet-name"><?php echo esc_html( $bucket['display'] ); ?></span>
                                    <?php endif; ?>
```

Apply the same replacement in `render_radio()` (~line 306), `render_hierarchy()` (~line 455), and `render_swatch()` (~line 710) — each has an equivalent `<span class="hof-facet-name">…display…</span>` (hierarchy/swatch markup differs slightly; the rule is: wherever the option's display text renders inside its `<label>`/option element, emit the anchor-or-span conditional with that display's own `$value` variable in scope). Read each method before editing; the `$value` variable name may be `$term_slug` or similar — pass whatever holds the raw facet value.

In `render_dropdown()` (~line 363), after the closing `</select>`, add:

```php
        <?php
        $seo_links = [];
        foreach ( $buckets as $bucket ) {
            $link = $this->pretty_link( $facet, (string) $bucket['value'] );
            if ( $link !== '' ) {
                $seo_links[] = '<li><a class="hof-facet-link" href="' . esc_url( $link ) . '">' . esc_html( $bucket['display'] ) . '</a></li>';
            }
        }
        if ( $seo_links !== [] ) {
            // Crawlable but invisible: bots read hrefs from source; the select
            // stays the interactive control.
            echo '<ul class="hof-facet-seo-links" hidden>' . implode( '', $seo_links ) . '</ul>';
        }
        ?>
```

- [ ] **Step 5: Run tests**

Run: `vendor/bin/phpunit --filter "RendererPrettyLinksTest|RendererHierarchyTest"`
Expected: OK

- [ ] **Step 6: Run the full PHP suite**

Run: `composer test`
Expected: OK

- [ ] **Step 7: Commit**

```bash
git add src/Facets/Renderer.php tests/php/RendererPrettyLinksTest.php
git commit -m "feat(render): crawlable pretty links on discrete facet options"
```

---

### Task 9: Client — AssetLoader config + state.js + main.js

**Files:**
- Modify: `src/Frontend/AssetLoader.php:44-51` (inline blob)
- Modify: `public/src/state.js`
- Modify: `public/src/main.js`
- Test: `tests/js/state.test.js` (create)

- [ ] **Step 1: Localize the pretty config**

In `src/Frontend/AssetLoader.php`, add imports:

```php
use HookedOnFacets\Routing\FilterState;
use HookedOnFacets\Routing\PrettyUrls;
use HookedOnFacets\Routing\SlugMapper;
use HookedOnFacets\Indexer;
```

Replace the `$inline` construction in `enqueue()`:

```php
        $inline = sprintf(
            'window.hofPublic = %s;',
            wp_json_encode( [
                'restUrl'    => esc_url_raw( rest_url( 'hof/v1/' ) ),
                'nonce'      => wp_create_nonce( 'wp_rest' ),
                'state'      => Resolver::parse_request_filters(),
                'prettyUrls' => $this->pretty_urls_config(),
            ] )
        );
```

Add the private method:

```php
    /**
     * Pretty-URL config for the client encoder, or null when disabled. Ships
     * the path-eligible facets in canonical order plus value→slug maps for
     * meta facets (taxonomy is identity; over-cap facets are simply absent,
     * so the client falls back to the query tail exactly like the server).
     *
     * @return array<string, mixed>|null
     */
    private function pretty_urls_config(): ?array {
        if ( ! PrettyUrls::enabled() ) {
            return null;
        }
        $codec = FilterState::codec();
        if ( ! $codec ) {
            return null;
        }

        $defs = (array) get_option( Indexer::OPTION_FACETS, [] );
        $by_name = [];
        foreach ( $defs as $def ) {
            if ( is_array( $def ) && isset( $def['name'] ) ) {
                $by_name[ (string) $def['name'] ] = $def;
            }
        }
        $mapper = new SlugMapper( $by_name );

        $facets   = [];
        $slug_maps = [];
        foreach ( $defs as $def ) {
            if ( ! is_array( $def ) || ! $codec->is_path_facet( $def ) ) {
                continue;
            }
            $name     = (string) $def['name'];
            $facets[] = $name;
            $map      = $mapper->client_map( $name );
            if ( $map !== null ) {
                $slug_maps[ $name ] = $map;
            }
        }

        $uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
        $path = (string) ( wp_parse_url( $uri )['path'] ?? '/' );
        $path = (string) preg_replace( '#/page/[0-9]+/?$#', '/', $path );

        return [
            'base'     => PrettyUrls::base(),
            'basePath' => $codec->strip_base_path( $path ),
            'facets'   => $facets,
            'slugMaps' => $slug_maps,
        ];
    }
```

- [ ] **Step 2: Write the failing JS test**

Create `tests/js/state.test.js`:

```js
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { Store, buildUrl } from '../../public/src/state.js';

// Pretty config fixture: brand/color identity (taxonomy), material has a slug
// map (meta). "sku" is absent from facets → always query tail, like over-cap
// facets server-side.
const PRETTY = {
    base: 'filter',
    basePath: '/shop/',
    facets: ['brand', 'color', 'material'],
    slugMaps: { material: { 'Solid Oak': 'solid-oak' } },
};

describe('query mode (pretty off)', () => {
    beforeEach(() => {
        window.hofPublic = {};
    });

    it('round-trips hof params through buildUrl/hydrateFromUrl', () => {
        const url = buildUrl(
            { brand: ['nike'], price: { min: '10' } },
            'https://shop.test/shop/?utm=x'
        );
        expect(url.pathname).toBe('/shop/');
        expect(url.searchParams.get('utm')).toBe('x');
        expect(url.searchParams.getAll('hof[brand][]')).toEqual(['nike']);
        expect(url.searchParams.get('hof[price][min]')).toBe('10');

        const store = new Store();
        store.hydrateFromUrl(url.toString());
        expect(store.get()).toEqual({ brand: ['nike'], price: { min: '10' } });
    });
});

describe('pretty mode', () => {
    beforeEach(() => {
        window.hofPublic = { prettyUrls: PRETTY };
    });
    afterEach(() => {
        window.hofPublic = {};
    });

    it('encodes discrete facets into the path, canonical order', () => {
        const url = buildUrl(
            { color: ['red', 'blue'], brand: ['nike'] },
            'https://shop.test/shop/'
        );
        expect(url.pathname).toBe('/shop/filter/brand/nike/color/blue/color/red/');
        expect([...url.searchParams.keys()]).toEqual([]);
    });

    it('uses slug maps for meta values and keeps ranges on the tail', () => {
        const url = buildUrl(
            { material: ['Solid Oak'], price: { min: '10' } },
            'https://shop.test/shop/'
        );
        expect(url.pathname).toBe('/shop/filter/material/solid-oak/');
        expect(url.searchParams.get('hof[price][min]')).toBe('10');
    });

    it('unknown facets fall back to the query tail', () => {
        const url = buildUrl({ sku: ['A100'] }, 'https://shop.test/shop/');
        expect(url.pathname).toBe('/shop/');
        expect(url.searchParams.getAll('hof[sku][]')).toEqual(['A100']);
    });

    it('resets to basePath when state is empty, preserving other params', () => {
        const url = buildUrl(
            {},
            'https://shop.test/shop/filter/brand/nike/?utm=x'
        );
        expect(url.pathname).toBe('/shop/');
        expect(url.searchParams.get('utm')).toBe('x');
    });

    it('drops the /page/N/ segment when filters change', () => {
        const url = buildUrl(
            { brand: ['nike'] },
            'https://shop.test/shop/page/3/'
        );
        expect(url.pathname).toBe('/shop/filter/brand/nike/');
    });

    it('hydrates state from a pretty path plus query tail', () => {
        const store = new Store();
        store.hydrateFromUrl(
            'https://shop.test/shop/filter/brand/nike/material/solid-oak/?hof[price][min]=10'
        );
        expect(store.get()).toEqual({
            brand: ['nike'],
            material: ['Solid Oak'],
            price: { min: '10' },
        });
    });

    it('pretty path wins over a stale query key', () => {
        const store = new Store();
        store.hydrateFromUrl(
            'https://shop.test/shop/filter/brand/nike/?hof[brand][]=stale'
        );
        expect(store.get()).toEqual({ brand: ['nike'] });
    });
});
```

- [ ] **Step 3: Run test to verify it fails**

Run: `npx vitest run tests/js/state.test.js`
Expected: pretty-mode tests FAIL (query-mode ones pass — existing behavior)

- [ ] **Step 4: Implement in `public/src/state.js`**

Replace `buildUrl` and `parseHofParams`'s call site (`hydrateFromUrl`), adding the pretty helpers:

```js
/**
 * Build a URL for the given state. In pretty mode, discrete facet values go
 * into the /filter/ path (canonical order: configured facet order, values
 * sorted by slug); everything else — ranges, search, reserved keys, facets
 * without a path mapping — stays on the ?hof[*] tail. Non-hof params are
 * preserved either way. Any /page/N/ segment is dropped: changing filters
 * returns to page 1.
 */
export function buildUrl(state, base = window.location.href) {
    const url = new URL(base);

    for (const key of [...url.searchParams.keys()]) {
        if (key.startsWith('hof[') || key === 'hof') {
            url.searchParams.delete(key);
        }
    }

    const pretty = window.hofPublic?.prettyUrls;
    if (pretty && pretty.base && pretty.basePath) {
        const { segments, tail } = encodePretty(state, pretty);
        url.pathname = segments.length
            ? joinPath(pretty.basePath, pretty.base, segments)
            : pretty.basePath;
        appendHofParams(url, tail);
        return url;
    }

    appendHofParams(url, state);
    return url;
}

/** Split state into pretty path segments + query tail per the shared rules. */
function encodePretty(state, pretty) {
    const segments = [];
    const tail = {};
    const handled = new Set();

    for (const name of pretty.facets) {
        if (!(name in state)) continue;
        handled.add(name);
        const value = state[name];

        const isRange = value !== null && typeof value === 'object' && !Array.isArray(value);
        if (isRange) {
            tail[name] = value;
            continue;
        }

        const values = Array.isArray(value) ? value : [value];
        const map = pretty.slugMaps?.[name];
        const slugs = [];
        let bail = false;
        for (const v of values) {
            const slug = map ? map[String(v)] : String(v);
            if (slug == null) { bail = true; break; }
            slugs.push(slug);
        }
        if (bail) {
            tail[name] = value; // one unmappable value → whole facet to tail
            continue;
        }
        slugs.sort();
        for (const slug of slugs) {
            segments.push(encodeURIComponent(name), encodeURIComponent(slug));
        }
    }

    for (const [name, value] of Object.entries(state)) {
        if (!handled.has(name)) tail[name] = value;
    }

    return { segments, tail };
}

function joinPath(basePath, base, segments) {
    return `${basePath.replace(/\/$/, '')}/${base}/${segments.join('/')}/`;
}

function appendHofParams(url, state) {
    for (const [name, value] of Object.entries(state)) {
        if (Array.isArray(value)) {
            for (const v of value) url.searchParams.append(`hof[${name}][]`, String(v));
        } else if (typeof value === 'object' && value !== null) {
            for (const [k, v] of Object.entries(value)) {
                url.searchParams.set(`hof[${name}][${k}]`, String(v));
            }
        } else if (value !== '' && value != null) {
            url.searchParams.set(`hof[${name}]`, String(value));
        }
    }
}
```

And extend `Store.hydrateFromUrl` + add the path parser:

```js
    /**
     * Parse the current URL — pretty /filter/ path (when configured) merged
     * with `?hof[*]` params; the path wins for any facet present in both.
     * Silent — no subscriber notification.
     */
    hydrateFromUrl(url = window.location.href) {
        const parsed = new URL(url);
        const fromQuery = parseHofParams(parsed);
        const fromPath = parsePrettyPath(parsed, window.hofPublic?.prettyUrls);
        this.state = { ...fromQuery, ...fromPath };
    }
```

```js
/**
 * Decode /filter/name/slug/... path segments into state. Unknown facets or
 * slugs are skipped client-side (the server 404s truly invalid paths before
 * we ever hydrate). Meta slugs reverse through the localized slug map.
 */
function parsePrettyPath(url, pretty) {
    if (!pretty || !pretty.base) return {};
    const marker = `/${pretty.base}/`;
    const idx = url.pathname.indexOf(marker);
    if (idx === -1) return {};

    const inner = url.pathname
        .slice(idx + marker.length)
        .replace(/\/page\/\d+\/?$/, '')
        .replace(/\/$/, '');
    if (!inner) return {};

    const reverse = {};
    for (const [facet, map] of Object.entries(pretty.slugMaps || {})) {
        reverse[facet] = Object.fromEntries(
            Object.entries(map).map(([value, slug]) => [slug, value])
        );
    }

    const parts = inner.split('/').map(decodeURIComponent);
    const out = {};
    for (let i = 0; i + 1 < parts.length; i += 2) {
        const name = parts[i];
        const slug = parts[i + 1];
        if (!pretty.facets?.includes(name)) continue;
        const value = reverse[name] ? reverse[name][slug] : slug;
        if (value == null) continue;
        if (!Array.isArray(out[name])) out[name] = [];
        out[name].push(value);
    }
    return out;
}
```

(`parseHofParams` itself is unchanged.)

- [ ] **Step 5: Intercept link clicks in `public/src/main.js`**

Add after the existing `popstate` listener:

```js
// Crawlable pretty links double as no-JS fallbacks; with JS running they
// route through the store → AJAX + pushState path via the sibling input.
document.addEventListener('click', (e) => {
    const link = e.target.closest('.hof-facet-link');
    if (!link) return;
    const input = link.closest('label')?.querySelector('input')
        || link.closest('li')?.querySelector('input');
    if (!input) return; // dropdown's hidden SEO list — let bots/no-JS navigate
    e.preventDefault();
    input.click();
});
```

- [ ] **Step 6: Run the JS suite**

Run: `npx vitest run`
Expected: all files pass, including the new `state.test.js` (8 tests)

- [ ] **Step 7: Run the PHP suite (AssetLoader changed)**

Run: `composer test`
Expected: OK

- [ ] **Step 8: Commit**

```bash
git add src/Frontend/AssetLoader.php public/src/state.js public/src/main.js tests/js/state.test.js
git commit -m "feat(frontend): client-side pretty URL encode/decode and link interception"
```

---

### Task 10: Docs, changelog, roadmap + final verification

**Files:**
- Create: `docs/pretty-urls.md`
- Modify: `CHANGELOG.md` (Unreleased section)
- Modify: `readme.txt` (feature list + changelog)
- Modify: `plan.md` (Enhancements section)
- Modify: `docs/_Sidebar.md` (add link; match existing format)

- [ ] **Step 1: Write `docs/pretty-urls.md`**

Follow the voice and structure of `docs/core-concepts.md`. Required content (write it out, not placeholders):

- What it does: `/shop/?hof[brand]=nike` → `/shop/filter/brand/nike/`; opt-in on the SEO screen; requires non-plain permalinks.
- Where it applies: shop archive, product category/tag/attribute archives; not arbitrary shortcode pages.
- URL anatomy: namespaced `/filter/name/value/` pairs, repeated keys for multi-value, ranges/search/reserved keys stay on `?hof[*]`, `/page/N/` pagination suffix.
- SEO behavior: ordered canonical, 301s (legacy → pretty, non-canonical order → canonical), unresolvable path → 404, noindex threshold unchanged.
- The base segment setting and when to change it.
- Filters reference table: `hof_pretty_urls_bases`, `hof_pretty_urls_max_values`, `hof_slugmap_cache_ttl`.
- Troubleshooting: "404 on every filter page" → flush permalinks (visit Settings → Permalinks); "links aren't pretty" → check permalink structure + enabled toggle.

- [ ] **Step 2: Update `CHANGELOG.md`**

Replace the `_No unreleased changes._` line under `## [Unreleased]`:

```markdown
### Added

- **Pretty faceted URLs** (opt-in) — filtered WooCommerce views get clean,
  crawlable paths: `/shop/?hof[brand]=nike` becomes `/shop/filter/brand/nike/`
  on the shop and product category/tag/attribute archives. Includes canonical
  URLs in a deterministic order, 301 redirects from legacy query-string and
  non-canonical URLs, hard 404s for unresolvable filter paths, crawlable
  `<a>` links in the discrete facet displays, and a client-side encoder so
  AJAX filtering pushes the same pretty URLs. Configure on the SEO screen;
  requires non-plain permalinks. New filters: `hof_pretty_urls_bases`,
  `hof_pretty_urls_max_values`, `hof_slugmap_cache_ttl`.
```

- [ ] **Step 3: Update `readme.txt` and `plan.md`**

`readme.txt`: add a feature bullet mirroring the README features list style ("Pretty faceted URLs — opt-in `/shop/filter/brand/nike/` paths with canonical + 301 handling."). `plan.md`: add a checked item under the post-1.0 Enhancements section describing the feature in one line, consistent with neighboring entries. `docs/_Sidebar.md`: add a "Pretty URLs" link where the other doc pages are listed.

- [ ] **Step 4: Full verification**

```bash
composer test && npx vitest run && npm run build
```
Expected: PHP suite green, JS suite green, admin + public bundles build clean.

Manual smoke (Docker sandbox, if running): enable the toggle on the SEO screen, visit `/shop/filter/<facet>/<value>/`, confirm filtered results, view source for the canonical tag and crawlable links, hit the legacy `?hof[...]` URL and confirm the 301.

Additional sandbox checks from the Task 5 review: a category term slugged exactly `filter` (its paginated archive must survive, or the base-collision warning must surface on the SEO screen); shop-page-as-front-page (known-unsupported — confirm no rules emitted for root, no breakage); a non-public product attribute (no rules emitted); `/shop/?hof_path=brand/nike` and `/shop/filter/brand/nike/?hof_path=brand/adidas` (both must 301 to clean pretty URLs); deactivate the plugin and confirm `/filter/` URLs 404 rather than serving unfiltered 200s.

- [ ] **Step 5: Commit**

```bash
git add docs/pretty-urls.md docs/_Sidebar.md CHANGELOG.md readme.txt plan.md
git commit -m "docs: pretty faceted URLs guide, changelog, and roadmap entries"
```

---

## Post-plan notes for the implementer

- **Branch:** work on `feat/pretty-faceted-urls-impl` (already created off spec commit `91ea8af`). PR target: `main`.
- **Order matters:** Tasks 1→4 are strictly sequential (each builds on the last). Tasks 5–9 all depend on 1–4 but are mutually independent; do them in the listed order anyway to keep the suite green at every commit. Task 10 is last.
- **When old tests break in Task 4:** the cause is almost always FilterState memoization bleeding across tests or an un-stubbed `get_option`. Fix in the test (add `FilterState::reset()` to `setUp()`/`tearDown()`, stub `get_option`), not in production code.
- **Spec assumptions to verify while implementing** (from the spec's own list): attribute-archive rewrite bases resolving from `wc_get_attribute_taxonomies()` (Task 5, manual sandbox check), and client slug-map size (handled by the 500-value cap + tail fallback — no REST lookup needed at this scope).
