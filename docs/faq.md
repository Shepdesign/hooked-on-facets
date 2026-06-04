# FAQ

Real questions, real answers.

## Is HOF free?

The core will be open source. A "pro" tier with extras is likely but not finalized. We won't paywall the basics.

## Does it work with my theme?

If your theme uses standard WordPress / WooCommerce templates, yes. HOF doesn't fight themes. CSS variables let you re-style without ripping anything out.

## Does it work with my page builder?

Planned: Gutenberg, Bricks, Elementor, Oxygen. See [Roadmap](Roadmap).

## How fast is it?

Faster than the alternatives, ideally. We'll publish real benchmarks once we have a beta worth benchmarking. Goal: filter response under 100ms on a 10,000-product Woo store.

## Will it break my site?

Activate it on staging first. (This is true of every plugin.) HOF is designed to be additive — it doesn't replace WP queries until you tell it to.

## What about SEO?

URL state means crawlers see real URLs. We're shipping with sensible defaults (canonical hints, robots controls, structured data preservation). More in the docs as we land 1.0.

## How does pricing work?

TBD. Probably open core + paid pro. Definitely fair. Definitely not "$249/year to unlock checkboxes."

## Can I self-host the docs?

Yes. The `/docs` directory in the main repo mirrors this wiki. Build with any static site generator.

## Why "hooked on facets"?

Because filters in WordPress are powered by hooks. Because the brand voice is "fun." Because the URL was available. Because *we are not naming our plugin Facetastic*.

## Can I sponsor the project?

Coming. GitHub Sponsors page is in the works.

## How do I report a bug?

[Open an issue](https://github.com/Shepdesign/hooked-on-facets/issues). Include WP version, PHP version, plugin version, and a repro case. Bonus points for a screenshot.
