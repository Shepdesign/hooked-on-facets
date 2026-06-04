# Page Builders

HOF binds a builder's query loop to the active facet filters, then renders facet
UI either through a native element or the `[hof_facet]` shortcode. Binding is
**opt-in** (by an identifier you set), never magic auto-detection.

## Gutenberg

Use the **Hooked on Facets** block (`hof/facet`). Core's Query Loop block runs a
real `WP_Query`, so it flows through HOF's `pre_get_posts` interceptor with no
extra configuration.

## Elementor — by Query ID

Set a **Query ID** of `hof` on your Loop Grid / Posts / Products widget. HOF hooks
`elementor/query/hof` and applies `post__in` from the URL's `?hof[*]` state. Bind
multiple loops or use a different convention via the `hof_elementor_query_ids`
filter. Place facets with the HOF widget or the `[hof_facet]` shortcode.

## Bricks — by CSS class

Tag your query-loop element with the CSS class `hof` (Style → CSS classes). HOF
hooks `bricks/posts/query_vars` to apply `post__in`. Filter the class list via
`hof_bricks_query_ids`. A native placement element is provided.

## Breakdance — Array Query recipe

Breakdance exposes no scoped query hook, so binding is a documented recipe: in a
Post Loop Builder set the query to **Array** and return:

```php
return hof_breakdance_query_args( $base_args );
```

This merges HOF's URL-derived `post__in` when filters are active and returns your
args untouched otherwise. Place facets with the `[hof_facet]` shortcode inside a
Code/Shortcode element.

## Divi

- **Theme Builder archive/index templates** drive the main query, which HOF already
  intercepts — no Divi-specific code needed.
- **A Blog module on a regular page** runs a secondary query. Merge HOF's filters
  from your own scoped `pre_get_posts` snippet:

  ```php
  $query->set( 'post__in', /* ... */ );
  // or simply build args via:
  $args = hof_divi_query_args( $base_args );
  ```

A native `ET_Builder_Module` (`et_pb_hof_facet`) provides facet placement; the
`[hof_facet]` shortcode is the fallback.
