#!/usr/bin/env bash
#
# Seed N synthetic WooCommerce products and reindex them through HOF.
#
# Usage (inside the wp-cli container):
#   ./bin/seed-products.sh 100000
#
# Usage (from the host):
#   docker compose exec wp-cli bash /var/www/html/wp-content/plugins/hooked-on-facets/bin/seed-products.sh 100000
#
# After it finishes:
#   - 100k products live in wp_posts (post_type='product', publish)
#   - They're tagged with category, brand meta, color meta, size meta, etc.
#   - wp_hof_index is populated for whichever facets you've defined in the option
#
# Reindex output prints every 5000 objects so you can eyeball the rate.

set -euo pipefail

COUNT="${1:-100000}"

if ! command -v wp >/dev/null 2>&1; then
    cat >&2 <<EOF
Error: wp-cli not on PATH. This script must run inside a wp-cli environment.

From your host machine:
    docker compose exec wp-cli bash $(realpath "$0" 2>/dev/null || echo "$0") $COUNT
EOF
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SEED_FILE="${SCRIPT_DIR}/seed-products.php"

echo "→ Seeding ${COUNT} products via direct \$wpdb inserts…"
wp eval "require '${SEED_FILE}'; hof_seed_products(${COUNT});"

echo ""
echo "→ Running HOF reindex…"
wp eval '
    $indexer = \HookedOnFacets\Plugin::instance()->make( \HookedOnFacets\Indexer::class );
    $start   = microtime( true );
    $count   = $indexer->reindex_all( function ( $n ) {
        if ( $n % 5000 === 0 ) {
            fwrite( STDOUT, "  indexed {$n}\n" );
        }
    } );
    printf( "✓ Indexed %d objects in %.2fs\n", $count, microtime( true ) - $start );
'
