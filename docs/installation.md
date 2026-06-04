# Installation

> [!WARNING]
> **Pre-alpha.** HOF hasn't shipped a public build yet. This page captures the *planned* install paths. Bookmark and check back.

## planned install paths

### 1. zip upload (when alpha lands)

1. Download `hooked-on-facets.zip` from the [Releases page](https://github.com/Shepdesign/hooked-on-facets/releases).
2. WordPress admin → **Plugins** → **Add New** → **Upload Plugin**
3. Upload, activate, done.

### 2. composer (recommended for dev shops)

```bash
composer require shepdesign/hooked-on-facets
```

### 3. git (for contributors)

```bash
cd wp-content/plugins
git clone https://github.com/Shepdesign/hooked-on-facets.git
cd hooked-on-facets
composer install
npm install
npm run build
```

## activation

After activation, HOF will:

1. Create its custom index tables
2. Add a top-level **HOF** menu in the admin
3. Run a first-time indexer (this can take a few minutes on large catalogs)

> [!TIP]
> If you're on a host with low PHP timeouts and a huge catalog, the indexer is queued and chunked. You don't have to babysit it.

## uninstalling

HOF is built to leave nothing behind. Deactivate + delete = clean exit, including index tables. (Opt-in retention coming for sites that want to keep their config across reinstalls.)
