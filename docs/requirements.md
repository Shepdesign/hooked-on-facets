# Requirements

What you need to run HOF without sadness.

## minimum

| | Minimum | Recommended |
|---|---|---|
| PHP | 8.1 | 8.3+ |
| WordPress | 6.4 | latest |
| WooCommerce *(if filtering products)* | 8.0 | latest |
| MySQL | 5.7 | 8.0+ |
| MariaDB | 10.3 | 10.6+ |
| Memory limit | 128M | 256M+ |

> [!NOTE]
> We don't support PHP 7.x. PHP 7.4 has been EOL since November 2022. If your host is still on it, ask them why.

## hosting notes

- **Shared hosting:** Works on most. The indexer is chunked specifically so cheap hosts don't choke.
- **Object cache (Redis, Memcached):** Strongly recommended. HOF respects WP transients.
- **CDN / page cache:** Compatible. Facet queries are designed to bypass full-page cache cleanly.
- **Bedrock / Composer-managed WP:** First-class. We ship a real `composer.json`.

## browser support

HOF's frontend runtime targets modern evergreen browsers:

- Chrome / Edge: last 2 versions
- Firefox: last 2 versions
- Safari: 15+
- iOS Safari: 15+

If your audience is on IE11, HOF is not for you. (Neither is the year 2026.)
