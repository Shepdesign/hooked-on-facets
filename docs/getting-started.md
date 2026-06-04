# Getting Started

## Install

1. Upload the `hooked-on-facets` folder to `/wp-content/plugins/`, or install the
   ZIP via **Plugins → Add New → Upload Plugin**.
2. Activate **Hooked on Facets**.
3. (Premium) Enter your license key on the **License** screen to enable automatic
   updates — see [Licensing & Updates](licensing-and-updates.md).

## Build the index

Facets are served from a dedicated index table (`wp_hof_index`), not live
`WP_Query` lookups. Build it once after install:

- **Admin:** the **Indexer** screen → *Rebuild index*.
- **WP-CLI:** `wp hof reindex` (or `wp hof reindex --post=<id>` for one object).

After the first build, the index keeps itself current on `save_post`,
`deleted_post`, and `set_object_terms`. Large rebuilds run as a chunked
background job (Action Scheduler when available, `wp_cron` otherwise).

## Define a facet

Open **Hooked on Facets → Facets** and create a facet in the builder. Each facet
maps to a source (a taxonomy, a meta key, or a custom field) and a display type
(see [Facet Types](facet-types.md)). The builder shows a live preview.

If WooCommerce, ACF, Meta Box, or Pods is active, use the per-source **Suggest**
buttons to generate ready-to-use facet configs from fields that actually have
data — no hand-typing meta keys.

## Place a facet

Three ways, all server-rendered for a fast first paint:

- **Shortcode:** `[hof_facet name="brand"]`
- **Gutenberg block:** the **Hooked on Facets** block (`hof/facet`)
- **Page builder:** see [Page Builders](page-builders.md)

Helpers: `[hof_active_filters]` renders the active-filters chip strip;
`[hof_bin_button]` adds an item to the saved comparison bin.

## How filtering works

Selecting facet values updates the URL and re-renders the results region in place
(no full reload):

```text
?hof[brand]=acme&hof[price]=10-50
```

The frontend POSTs this state to `/wp-json/hof/v1/filter`, which returns the
matched IDs plus per-facet counts in one round trip; the matched IDs are applied
to the loop via `post__in`.
