#!/usr/bin/env bash
#
# Verify Visual DNA v2 extraction end-to-end.
#
# Generates synthetic test images (solid + mixed-color), sideloads them
# as featured images on the first N products in the catalog, runs
# reindex_object on each, and diffs the extracted LAB against the
# expected LAB. Exits 1 on any ΔE > 5.
#
# Usage (inside the wp-cli container):
#   ./bin/verify-visual-dna.sh
#
# Usage (from the host):
#   docker compose exec wp-cli bash /var/www/html/wp-content/plugins/hooked-on-facets/bin/verify-visual-dna.sh

set -euo pipefail

if ! command -v wp >/dev/null 2>&1; then
    cat >&2 <<EOF
Error: wp-cli not on PATH. Run from inside the wp-cli container:
    docker compose exec wp-cli bash $(basename "$0")
EOF
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
wp eval-file "${SCRIPT_DIR}/verify-visual-dna.php"
