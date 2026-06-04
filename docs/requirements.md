# Requirements

What you need to run HOF without sadness.

## minimum

| | Minimum | Recommended |
|---|---|---|
| PHP | 8.2 | 8.3+ |
| WordPress | 6.4 | latest |
| WooCommerce *(if filtering products)* | 8.0 | latest |
| MySQL | 8.0.31 | latest |
| Memory limit | 128M | 256M+ |

> [!IMPORTANT]
> MySQL **8.0.31 or newer** is required. The resolver runs on the SQL `INTERSECT` operator, which MySQL added in 8.0.31. MariaDB does not implement `INTERSECT` the same way and is not supported.
>
> We also don't support PHP 7.x. PHP 7.4 has been EOL since November 2022. If your host is still on it, ask them why.

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
