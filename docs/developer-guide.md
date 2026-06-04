# Developer Guide

Everything for the people who hook things.

> [!NOTE]
> This page is the index. Detailed reference pages (PHP API, JS API, REST API, hooks) are being filled in.

## what's here

| Page | What |
|---|---|
| [Architecture](Architecture) | The stack, directory layout, dev env |
| `PHP API` *(coming)* | Public classes and methods |
| `JavaScript API` *(coming)* | Frontend hooks, signal subscriptions |
| `REST API` *(coming)* | Endpoint reference |
| `Hooks Reference` *(coming)* | All `apply_filters` / `do_action` points |
| `Extending Facet Types` *(coming)* | Build a custom facet type |

## development workflow

### local setup

See [Architecture → Dev Environment](Architecture#dev-environment).

### running tests

```bash
# PHP unit + integration (PHPUnit)
composer test

# JS / component (Vitest)
npm run test
```

### code style

- **PHP:** WordPress Coding Standards + PSR-12 hybrid via PHPCS
- **JS/TS:** ESLint + Prettier, config in repo
- **Commit format:** Conventional Commits (`feat:`, `fix:`, `chore:`, etc.)

### running the indexer manually

```bash
wp hof reindex --url=demo.test
```

## debugging

HOF honors the standard WordPress debug constants. Set `WP_DEBUG` (and `SCRIPT_DEBUG` for unminified assets) in `wp-config.php` to surface PHP notices and load readable frontend bundles. The **Indexer** admin screen reports index stats (rows, objects, per-facet breakdown), which `wp hof status` also prints from the CLI.

## design tokens

HOF's CSS uses CSS custom properties exclusively. Want to retheme everything? Override the variables on `:root` or scope to your container, edit them in the **Design tokens** admin screen, or filter them in PHP via `hof_public_css_tokens`.

```css
:root {
  --hof-color-hook: #534AB7;
  --hof-color-facet: #D85A30;
  --hof-radius: 8px;
  /* ...override what you want */
}
```

## getting help

- 🐛 [Open an issue](https://github.com/Shepdesign/hooked-on-facets/issues)
- 💬 Discussions (coming soon)
- 🤝 [Contributing guide](Contributing)
