#!/usr/bin/env bash
# Build a clean, distributable Hooked on Facets plugin ZIP.
# Docker-driven (no host PHP/Node). Run from the repo root.
set -euo pipefail

REPO="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$REPO"

SLUG="hooked-on-facets"
UID_GID="$(id -u):$(id -g)"

# --- 1. Resolve + verify version (single source of truth = plugin header) ---
HEADER_VER="$(grep -E '^[[:space:]]*\*?[[:space:]]*Version:' "$SLUG.php" | head -1 | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]\r')"
CONST_VER="$(grep -E "define\([[:space:]]*'HOF_VERSION'" "$SLUG.php" | sed -E "s/.*'HOF_VERSION'[^']*'([^']+)'.*/\1/")"
if [ -z "$HEADER_VER" ]; then echo "ERROR: could not read Version header" >&2; exit 1; fi
if [ "$HEADER_VER" != "$CONST_VER" ]; then
  echo "ERROR: version mismatch — header=$HEADER_VER HOF_VERSION=$CONST_VER" >&2; exit 1
fi
VERSION="$HEADER_VER"
echo "==> Building $SLUG $VERSION"

# --- 2. Clean staging + output dirs ---
WORK="$(mktemp -d)"
STAGE="$WORK/$SLUG"
mkdir -p "$STAGE" "$REPO/dist"
trap 'rm -rf "$WORK"' EXIT

# --- 3. Export tracked, non-export-ignored files (honors .gitattributes) ---
git archive --worktree-attributes HEAD | tar -x -C "$STAGE"
echo "==> Staged archive tree"

# --- 4. Regenerate .pot (before vendor exists, so vendor isn't scanned).
#        Preserve the committed header (brand metadata: Report-Msgid-Bugs-To,
#        Language-Team, Plural-Forms) and splice in the freshly-scanned body,
#        so the shipped template matches the committed one rather than carrying
#        WP-CLI placeholder headers (FULL NAME / YEAR-MO-DA / X-Generator). The
#        committed languages/<slug>.pot is the canonical header source. ---
POT="$STAGE/languages/$SLUG.pot"
HEADER_LINES="$(grep -n '^$' "$POT" | head -1 | cut -d: -f1)"   # committed header incl. trailing blank
head -n "$HEADER_LINES" "$POT" > "$STAGE/.pot-header"
docker run --rm --user "$UID_GID" -v "$STAGE":/app -w /app wordpress:cli \
  i18n make-pot . "languages/$SLUG.pot.fresh" --slug="$SLUG" --domain="$SLUG" \
  --exclude=node_modules,tests,bin,assets
FRESH_BODY_START="$(( $(grep -n '^$' "$STAGE/languages/$SLUG.pot.fresh" | head -1 | cut -d: -f1) + 1 ))"
cat "$STAGE/.pot-header" > "$POT"
tail -n +"$FRESH_BODY_START" "$STAGE/languages/$SLUG.pot.fresh" >> "$POT"
rm -f "$STAGE/.pot-header" "$STAGE/languages/$SLUG.pot.fresh"
echo "==> Regenerated .pot (committed header preserved)"

# --- 5. Production composer deps (no dev) ---
docker run --rm --user "$UID_GID" -e COMPOSER_HOME=/tmp -v "$STAGE":/app -w /app composer:2 \
  install --no-dev --optimize-autoloader --no-interaction --no-progress
echo "==> Installed --no-dev vendor"

# --- 6. Build front-end assets in an isolated container, copy dist into stage ---
# (host node_modules holds macOS-arm64 rolldown bindings that crash in Linux)
docker run --rm -v "$REPO":/src:ro -v "$STAGE":/out -w /tmp node:20-alpine sh -c '
  tar -C /src --exclude=node_modules --exclude=.git --exclude=dist --exclude=vendor -cf - . | (mkdir -p /build && tar -C /build -xf -) &&
  cd /build && rm -rf node_modules && npm ci && npm run build &&
  mkdir -p /out/assets && cp -r /build/assets/dist /out/assets/dist'
echo "==> Built + copied assets/dist"

# --- 7. Strip build-only files that had to be present for steps 5-6 ---
rm -f "$STAGE/composer.json" "$STAGE/composer.lock"
# Drop source maps — they expose readable source and aren't needed at runtime.
find "$STAGE/assets/dist" -name '*.map' -delete 2>/dev/null || true

# --- 8. Zip with a top-level hooked-on-facets/ folder ---
OUT="$REPO/dist/$SLUG-$VERSION.zip"
rm -f "$OUT"
( cd "$WORK" && zip -rq "$OUT" "$SLUG" -x '*.DS_Store' )
echo "==> Wrote $OUT ($(du -h "$OUT" | cut -f1))"

# --- 9. Self-verify the package ---
VERIFY="$WORK/verify"; mkdir -p "$VERIFY"; unzip -q "$OUT" -d "$VERIFY"
fail=0
for bad in tests bin .github docker-compose.yml package.json package-lock.json plan.md \
           vite.config.js vitest.config.js phpunit.xml.dist composer.json composer.lock \
           node_modules README.md CHANGELOG.md .git .gitattributes; do
  if [ -e "$VERIFY/$SLUG/$bad" ]; then echo "VERIFY FAIL: dev file shipped: $bad" >&2; fail=1; fi
done
for need in hooked-on-facets.php uninstall.php readme.txt vendor src includes public admin integrations languages assets/dist; do
  if [ ! -e "$VERIFY/$SLUG/$need" ]; then echo "VERIFY FAIL: missing runtime: $need" >&2; fail=1; fi
done
if [ -d "$VERIFY/$SLUG/vendor/phpunit" ] || [ -d "$VERIFY/$SLUG/vendor/brain" ]; then
  echo "VERIFY FAIL: dev dependency shipped in vendor/" >&2; fail=1
fi
if find "$VERIFY/$SLUG/assets/dist" -name '*.map' | grep -q .; then
  echo "VERIFY FAIL: source map(s) shipped in assets/dist" >&2; fail=1
fi
if [ "$fail" -ne 0 ]; then echo "==> PACKAGE VERIFICATION FAILED" >&2; exit 1; fi
echo "==> Package verified OK"
