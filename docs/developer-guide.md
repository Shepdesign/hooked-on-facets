# Developer Guide

Everything for the people who hook things.

> [!NOTE]
> Detailed reference pages (PHP API, JS API, REST API, hooks) will be added as the plugin lands. This page is the index.

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
# PHP unit + integration
composer test

# JS / component
npm run test

# End-to-end (Playwright)
npm run test:e2e
```

### code style

- **PHP:** WordPress Coding Standards + PSR-12 hybrid via PHPCS
- **JS/TS:** ESLint + Prettier, config in repo
- **Commit format:** Conventional Commits (`feat:`, `fix:`, `chore:`, etc.)

### running the indexer manually

```bash
wp hof index rebuild --site=demo.test
```

## debugging

HOF ships a debug mode that exposes:

- The full SQL of the last facet query
- Index hit/miss counts
- Frontend signal state at any moment

Enable via `define( 'HOF_DEBUG', true );` in `wp-config.php`. Debug output goes to a dock at the bottom of the screen for logged-in admins.

## design tokens

HOF's CSS uses CSS custom properties exclusively. Want to retheme everything? Override the variables on `:root` or scope to your container. See [`brand/colors.css`](https://github.com/Shepdesign/hooked-on-facets/blob/main/brand/colors.css) in the main repo.

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
