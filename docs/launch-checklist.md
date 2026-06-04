# Commercial Launch Checklist

Internal runway for taking Hooked on Facets to a paid product. Status reflects the
`1.0.0` codebase.

## ✅ Done (code side)

- [x] Feature-complete: 16 facet types, INTERSECT resolver, AND/OR matching, cache.
- [x] Performance gates met (~54ms p95 ids / ~63ms full / ~19s reindex on 100k).
- [x] Sources: WooCommerce, ACF, Meta Box, Pods. Builders: Gutenberg, Elementor,
      Bricks, Breakdance, Divi.
- [x] EDD licensing scaffold (`src/Licensing/`): activate/deactivate, daily
      revalidation, soft enforcement, dev-mode bypass, plugin updater.
- [x] Tests + CI gating (PHP / JS / Markdown). Versions consistent at 1.0.0.
- [x] `readme.txt`, translation template (`languages/hooked-on-facets.pot`), docs.
- [x] Authorship/brand consolidated under SHEPDESIGN.

## 🟧 Must-do before charging money

- [ ] **Stand up the store.** EDD + EDD Software Licensing on `hookedonfacets.com`.
- [ ] **Create the EDD Download** for HOF; record its item ID.
- [ ] **Wire `HOF_LICENSE_ITEM_ID`** into the shipped build (it currently defaults
      to `0` → activation can't succeed until set).
- [ ] **Upload the release ZIP** to the Download so the updater can serve updates.
- [ ] **Build `hookedonfacets.com`** — landing page, feature/benefit sections,
      pricing, checkout, account/license area, docs, support contact.
- [ ] **Tag & publish `v1.0.0`** (GitHub release + store), matching this changelog.

## 🟨 Strongly recommended

- [ ] **Legal:** license EULA/terms, refund policy, privacy policy. The AI "ask"
      facet sends the typed query + facet schema to Anthropic — disclose this for
      GDPR (already noted in `readme.txt` FAQ).
- [ ] **Support channel:** ticket/email + response expectations.
- [ ] **Build pipeline** for the distributable ZIP: `composer install --no-dev`,
      `npm ci && npm run build`, regenerate the `.pot`, exclude dev files
      (`tests/`, `bin/`, `docker-compose.yml`, `node_modules/`, `.github/`).
- [ ] **Admin-UI translations:** the React admin uses plain React, so its strings
      are not in the PHP `.pot`. If admin translation matters, move admin strings
      onto `@wordpress/i18n` and add a JS string-extraction step.

## 🟦 Known limitations to document (not blockers)

- [ ] Breakdance native placement element (deferred; shortcode placement works).
- [ ] Divi Visual Builder render validation (needs a live Divi install).
- [ ] Pods table / `wp_podsrel` relationship storage (indexer reads postmeta).
- [ ] No time-of-day facet (intentional product decision).

## Website content outline (for hookedonfacets.com)

1. **Hero** — "Sub-50ms faceted filtering for WooCommerce at 100k+ products." CTA.
2. **The problem** — slow meta-query filtering / crawl bloat / clunky builders.
3. **Speed proof** — the benchmark table (`resolve_ids` 54ms, reindex 19s).
4. **Facet showcase** — the standout types (swipe deck, visual DNA, matrix, AI ask).
5. **Builder support** — logos: Gutenberg, Elementor, Bricks, Breakdance, Divi.
6. **Sources** — WooCommerce, ACF, Meta Box, Pods.
7. **Pricing** — tiers (e.g. single-site / 5-site / unlimited; annual).
8. **FAQ** — MySQL 8.0.31 requirement, AI key, GPL, support.
9. **Docs + support links.** Footer: © SHEPDESIGN, legal links.
