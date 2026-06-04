# Hooked on Facets — Documentation

Ultra-modern faceted search and filtering for WordPress + WooCommerce, by
[SHEPDESIGN](https://shepdesign.com). Sub-50ms filter queries on 100k+ product
catalogs, with a no-code facet builder.

These docs live in the repository (versioned alongside the code) rather than the
GitHub Wiki, so they travel with each release and are reviewed in pull requests.

## Contents

- **[Getting Started](getting-started.md)** — install, index, and place your first facet.
- **[Facet Types](facet-types.md)** — the 16 facet types and when to use each.
- **[Page Builders](page-builders.md)** — Gutenberg, Elementor, Bricks, Breakdance, Divi.
- **[Architecture](architecture.md)** — how the index and resolver deliver the speed.
- **[Licensing & Updates](licensing-and-updates.md)** — license keys, activation, auto-updates.
- **[Launch Checklist](launch-checklist.md)** — commercial readiness runway (internal).

## Requirements

- WordPress 6.4+
- PHP 8.2+
- MySQL 8.0.31+ (the resolver uses the `INTERSECT` operator)

## Links

- Product site: <https://hookedonfacets.com>
- Changelog: [CHANGELOG.md](../CHANGELOG.md)
- Roadmap & status: [plan.md](../plan.md)
