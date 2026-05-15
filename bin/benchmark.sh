#!/usr/bin/env bash
#
# Measure HOF perf against plan.md gates.
#
# Usage (inside the wp-cli container):
#   ./bin/benchmark.sh
#   ITERATIONS=500 ./bin/benchmark.sh
#   INCLUDE_REINDEX=1 ./bin/benchmark.sh
#   SKIP_EXPLAIN=1 ./bin/benchmark.sh
#
# Usage (from the host):
#   docker compose exec wp-cli bash /var/www/html/wp-content/plugins/hooked-on-facets/bin/benchmark.sh
#
# Tunables (env vars):
#   ITERATIONS         How many timed calls per stat (default 200).
#   WARMUP             Throwaway calls before measurement (default 10).
#   INCLUDE_REINDEX    Set non-empty to also benchmark a full reindex.
#   SKIP_EXPLAIN       Set non-empty to skip EXPLAIN ANALYZE output.
#
# Prereqs: facets configured (admin SPA or `wp option update hof_facets ...`),
# index populated (`./bin/seed-products.sh` then a reindex).

set -euo pipefail

ITERATIONS="${ITERATIONS:-200}"
WARMUP="${WARMUP:-10}"
REINDEX_FLAG="$( [ -n "${INCLUDE_REINDEX:-}" ] && echo 'true' || echo 'false' )"
EXPLAIN_FLAG="$( [ -n "${SKIP_EXPLAIN:-}"    ] && echo 'false' || echo 'true' )"

if ! command -v wp >/dev/null 2>&1; then
    cat >&2 <<EOF
Error: wp-cli not on PATH. Run from inside the wp-cli container:
    docker compose exec wp-cli bash $(basename "$0")
EOF
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BENCH_FILE="${SCRIPT_DIR}/benchmark.php"

wp eval "
    require '${BENCH_FILE}';
    hof_benchmark_run( [
        'iterations' => ${ITERATIONS},
        'warmup'     => ${WARMUP},
        'reindex'    => ${REINDEX_FLAG},
        'explain'    => ${EXPLAIN_FLAG},
    ] );
"
