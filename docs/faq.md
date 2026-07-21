# FAQ

Real questions, real answers.

## Is HOF free?

Yes — the core is free and open source, headed for WordPress.org. Free means the real thing: ten facet types (checkbox, radio, dropdown, toggle, hierarchy, range, date range, search, color swatch, pagination), the full-speed resolver, WooCommerce support, and the page-builder bridges. We don't paywall the basics, and we never throttle speed.

**Pro** adds the six signature facets — Ask (AI natural language), Visual DNA (perceptual color matching), swipe deck, spin-the-wheel, intersection matrix, and the comparison bin.

## Does it work with my theme?

If your theme uses standard WordPress / WooCommerce templates, yes. HOF doesn't fight themes. CSS variables let you re-style without ripping anything out.

## Does it work with my page builder?

Yes: Gutenberg, Bricks, Elementor, Divi, and Breakdance are all supported. See [Roadmap](Roadmap).

## How fast is it?

Fast. On a seeded 100,000-product store, the resolver returns matching IDs at roughly 54ms p95, and a full reindex completes in about 19 seconds. The brand promise is sub-50ms filter queries at scale, and real-world filters (which narrow more aggressively than our worst-case benchmark) land well under that.

## Will it break my site?

Activate it on staging first. (This is true of every plugin.) HOF is designed to be additive — it doesn't replace WP queries until you tell it to.

## What about SEO?

URL state means crawlers see real URLs. HOF ships sensible defaults (canonical hints, robots controls, structured data preservation).

## How does pricing work?

Free on WordPress.org, forever, for the core. Pro is $99/yr (1 site), $199/yr (5 sites), or $399/yr (25 sites) — every renewal is 50% off. Pro tiers open when 1.1 ships; [beta testers](https://hookedonfacets.com/#beta) get founder pricing, and active testers get offered a lifetime deal. Definitely fair. Definitely not "$249/year to unlock checkboxes" — checkboxes are free.

## Can I self-host the docs?

Yes. The `/docs` directory in the main repo mirrors this wiki. Build with any static site generator.

## Why "hooked on facets"?

Because filters in WordPress are powered by hooks. Because the brand voice is "fun." Because the URL was available. Because *we are not naming our plugin Facetastic*.

## Can I sponsor the project?

Coming. GitHub Sponsors page is in the works.

## How do I report a bug?

[Open an issue](https://github.com/Shepdesign/hooked-on-facets/issues). Include WP version, PHP version, plugin version, and a repro case. Bonus points for a screenshot.
