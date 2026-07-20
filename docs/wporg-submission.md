# WordPress.org Submission Guide

How to get Hooked on Facets onto the WordPress.org plugin directory as the free
edition, with the premium edition sold from hookedonfacets.com. Internal runbook.

## Distribution model

WordPress.org hosts **free** plugins only. The model is **freemium**:

- **Free edition → WordPress.org.** The full plugin, minus the licensed
  self-updater and license-server calls. This is the discovery engine and the
  "open source, actively developed" storefront. It updates through WordPress.org.
- **Premium edition → hookedonfacets.com.** The same code plus `src/Licensing/`
  (EDD-backed license activation + auto-updates from your store).

The split is driven by the `HOF_EDITION` constant in `hooked-on-facets.php`
(`premium` by default). The free build flips it to `free`, which:

- never boots the `Updater`, the license revalidation cron, or the license admin
  notice (`src/Plugin.php` → `core_services()`),
- passes `null` as the REST controller's license manager, so `/license` routes
  are not registered,
- hides the **License** tab in the admin SPA (`admin/src/App.jsx`),
- and the build additionally **strips `src/Licensing/` and
  `src/Admin/LicenseNotice.php`** from the ZIP entirely.

Net result: the WordPress.org build makes **no external calls except** the
opt-in Anthropic "ask" facet (disclosed in `readme.txt` → External services), and
cannot self-update outside WordPress.org — both hard WordPress.org requirements.

## Build the free ZIP

From the repo root (Docker, matching the premium build):

```bash
EDITION=free ./bin/build-release.sh
# → dist/hooked-on-facets-free-1.0.0.zip
```

The script exports the tracked tree (honoring `.gitattributes` `export-ignore`),
applies the free-edition transforms, installs `--no-dev` Composer deps, builds the
Vite assets, regenerates the `.pot`, strips dev files + source maps, zips, and
self-verifies (including that `src/Licensing` is absent and `HOF_EDITION` is
`free`). The premium build is the same command without `EDITION=free`.

## Pre-submission checklist

- [ ] `readme.txt` — `Stable tag` matches the plugin version; `Tested up to` is the
      current WordPress release; `Contributors` is your **wordpress.org** username
      (not a display name).
- [ ] `readme.txt` **External services** section is accurate (the Anthropic "ask"
      facet). Keep it current if any other external call is ever added.
- [ ] Version is a real release (no `-alpha`). WordPress.org rejects alpha stable tags.
- [ ] The free ZIP contains **no** `src/Licensing/` and no self-updater.
- [ ] JS source ships (`admin/src/`, `public/src/`) and the repo link is in `readme.txt`.
- [ ] Plugin runs on a clean WordPress install with no PHP notices.
- [ ] Text domain is `hooked-on-facets` throughout; `languages/hooked-on-facets.pot`
      is present.

## Submit for review

1. Create/sign in to a **wordpress.org** account (its username becomes the
   `Contributors` slug and the plugin owner).
2. Go to https://wordpress.org/plugins/developers/add/ and upload
   `hooked-on-facets-free-1.0.0.zip`.
3. A human reviewer checks it — typically a few days to a couple of weeks. They
   look for GPL compliance, no self-updating, sanitized/escaped I/O, prefixed
   globals, and an accurate External services disclosure. Respond to their email
   thread; fixes are re-submitted in the same thread.
4. On approval you receive **SVN** access at
   `https://plugins.svn.wordpress.org/hooked-on-facets/` and the public listing
   is created.

## Publish via SVN (after approval)

WordPress.org distributes from SVN, not the ZIP. Layout:

```text
hooked-on-facets/
  trunk/            # current development version (the plugin files)
  tags/1.0.0/       # a copy of trunk at each release (matches Stable tag)
  assets/           # listing images — NOT shipped in the plugin
```

Release flow:

```bash
svn co https://plugins.svn.wordpress.org/hooked-on-facets/ svn-hof
# Unzip the free build into trunk/ (replace contents), then:
cd svn-hof
svn add --force trunk/*
svn cp trunk tags/1.0.0
svn ci -m "Release 1.0.0"
```

`Stable tag: 1.0.0` in `trunk/readme.txt` tells WordPress.org which tag to serve.

## Listing assets (the `assets/` SVN dir — you design these)

These are separate from the plugin ZIP; they live in SVN `assets/` and drive the
directory listing. All PNG or JPG.

| Asset | File name | Size |
|---|---|---|
| Icon | `icon-256x256.png` (and `icon-128x128.png`) | 256×256, 128×128 |
| Banner | `banner-1544x500.png` (and `banner-772x250.png`) | 1544×500, 772×250 |
| Screenshots | `screenshot-1.png` … `screenshot-4.png` | any; match `readme.txt` order |

The four screenshot captions are already written in `readme.txt` →
`== Screenshots ==` (facet builder, dashboard, front-end facets, tokens editor).
The brand mark (isometric hexagon + coral spark) is described in
`src/Admin/MenuRegistrar.php::register_menu()` — reuse it for the icon.

## Keeping the two editions in sync

The free and premium editions build from the **same `main`**. Every release:

1. Bump the version (the premium `Release` GitHub Action auto-publishes the
   premium ZIP + GitHub release on a version change).
2. Build the free ZIP: `EDITION=free ./bin/build-release.sh`.
3. Push the free build to SVN `trunk/` + a new `tags/<version>/` and bump the
   `Stable tag`.

Because the split is a single constant, there is no separate free branch to
maintain — one codebase, two builds.
