# Licensing & Updates

Hooked on Facets is GPL-2.0-or-later. The premium distribution adds a license key
that unlocks **automatic updates** from the SHEPDESIGN store, backed by Easy
Digital Downloads (EDD) Software Licensing.

## For site owners

1. Buy a license at <https://hookedonfacets.com>.
2. In **Hooked on Facets → License**, paste your key and click **Activate**.
3. Once valid, update notifications and one-click updates appear on the Plugins
   screen like any other plugin.

The plugin re-validates the key daily (cron) and on demand. Deactivating the key
frees the activation for use on another site.

## Enforcement

Enforcement is **soft** by default: an unlicensed site sees an admin notice but
all features keep working. (A future `strict` mode can gate features.) Override:

```php
define( 'HOF_LICENSE_ENFORCEMENT', 'soft' ); // or 'strict'
```

## Configuration constants

Set in `wp-config.php`:

| Constant | Purpose |
|---|---|
| `HOF_LICENSE_STORE_URL` | EDD store base URL (default `https://hookedonfacets.com`). |
| `HOF_LICENSE_ITEM_ID` | The EDD Download / item ID this plugin maps to. |
| `HOF_LICENSE_ENFORCEMENT` | `soft` (default) or `strict`. |
| `HOF_LICENSE_DEV_MODE` | `true` to treat any key as valid (local dev / CI). |

## For the store operator (SHEPDESIGN)

To make activation and updates work end to end:

1. Stand up EDD + EDD Software Licensing on `hookedonfacets.com`.
2. Create the **Download** for Hooked on Facets and note its item ID.
3. Wire that ID into the build as `HOF_LICENSE_ITEM_ID` (or ship it in a config).
4. Upload each release ZIP to the Download so the updater can serve it.

See the [Launch Checklist](launch-checklist.md) for the full runway.
