# Hooked on Facets

Ultra-modern faceted search and filtering for WordPress + WooCommerce — built by
[shepdesign](https://shepdesign.com).

The promise: **sub-50ms filter queries on 100k+ product catalogs**, paired with
UI inventions legacy faceting plugins don't ship, and an admin where a non-PHP
developer can define and place facets without touching code.

## Features

- **16 facet types** — checkbox, radio, dropdown, toggle, hierarchy, range, date
  range, search, fluid swatches, swipe deck, spin-the-wheel, intersection
  (Venn/UpSet) matrix, saved comparison bin, AI natural-language ask, visual
  (color) DNA, and numbered pagination.
- **Fast resolver** — a narrow-EAV index resolved with a MySQL `INTERSECT`
  engine; AND-across-facets, with per-facet **OR or AND** (`any`/`all`) matching.
- **Custom-field sources** — one-click facet suggestions from WooCommerce, ACF,
  Meta Box, and Pods.
- **Page-builder bridges** — Gutenberg, Elementor, Bricks, Breakdance, and Divi.
- **Multisite-ready** — per-blog index and options, network-aware lifecycle.

## Requirements

- WordPress 6.4+
- PHP 8.2+
- MySQL 8.0.31+ (the resolver uses `INTERSECT`)

## Documentation

- **[Changelog](CHANGELOG.md)** — release history (also on the
  [GitHub Releases](https://github.com/Shepdesign/hooked-on-facets/releases) page).
- **[Roadmap & status](plan.md)** — what's shipped and what's deferred.
- **[Architecture](SHEPDESIGN.md)** — the load-bearing SQL shape and design decisions.
- **[Contributing](CONTRIBUTING.md)** · **[Security policy](SECURITY.md)**

## Development

```bash
composer install
npm install
docker compose up -d   # WP + WooCommerce + MySQL at http://localhost:8080 (admin/admin)
```

Tests: `vendor/bin/phpunit` (PHP) and `npm test` (JS, Vitest). CI gates PHP,
JavaScript, and Markdown lint on every push.

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html). © shepdesign.
