#!/usr/bin/env bash
#
# Run the Playwright e2e suite with PHP line-coverage capture.
#
# Boots `php artisan serve` with Xdebug in coverage-only mode + COVERAGE=1, so
# every request the browser makes gets recorded via RecordCoverage middleware.
# After the suite finishes, merges all .cov files into an HTML report under
# coverage/e2e/index.html and prints the total percentage.
#
# Slower than running against Herd directly (Xdebug overhead) but produces a
# real PHP coverage number for the Filament UI that Pest can't reach.

set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PROJECT_ROOT"

XDEBUG_SO="/Applications/Herd.app/Contents/Resources/xdebug/xdebug-84-arm64.so"
PORT="${E2E_COVERAGE_PORT:-8765}"
HOST="127.0.0.1"
BASE_URL="http://${HOST}:${PORT}"
# Parallelism — PHP_CLI_SERVER_WORKERS forks the dev server so it can handle
# concurrent requests; Playwright workers drive different spec files in
# parallel. Cap Playwright below PHP workers so server-side never queues.
PHP_WORKERS="${E2E_PHP_WORKERS:-16}"
PW_WORKERS="${E2E_PW_WORKERS:-2}"

if [ ! -f "$XDEBUG_SO" ]; then
  echo "✗ Xdebug binary not found at $XDEBUG_SO"
  echo "  Adjust the path in scripts/e2e-coverage.sh for your Herd/PHP build."
  exit 1
fi

echo "▸ Cleaning previous coverage dumps + freeing port..."
# Remove per-request dumps + HTML output, but PRESERVE pest.cov so the
# combined coverage-all pipeline can layer on top of a prior Pest run.
mkdir -p storage/coverage coverage/e2e
find storage/coverage -name 'req-*.cov' -delete 2>/dev/null || true
rm -f storage/coverage/server.log storage/coverage/server.pid
rm -rf coverage/e2e
mkdir -p coverage/e2e
# Kill anything still bound to the coverage port from a prior aborted run.
if PIDS=$(lsof -ti tcp:"$PORT" 2>/dev/null) && [ -n "$PIDS" ]; then
  echo "  freeing port ${PORT} (killing PIDs: ${PIDS})"
  echo "$PIDS" | xargs -n1 kill -9 2>/dev/null || true
  sleep 1
fi

echo "▸ Starting coverage server on ${BASE_URL} (Xdebug, PHP_CLI_SERVER_WORKERS=${PHP_WORKERS})..."
# Use PHP's built-in server directly instead of `artisan serve`. `artisan serve`
# only whitelists a fixed set of env vars when spawning workers (see
# vendor/laravel/framework/.../ServeCommand.php::$passthroughVariables), so
# COVERAGE=1 never reaches the request handler. `php -S` passes the full
# parent env through unchanged.
#
# PHP_CLI_SERVER_WORKERS forks N worker processes so the dev server can
# handle concurrent requests — required for Playwright workers > 1.
#
# The Laravel router script uses getcwd() for the include path, so we cd
# into public/ before launching the server.
ROUTER="$PROJECT_ROOT/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"
(
  cd "$PROJECT_ROOT/public"
  COVERAGE=1 \
  PHP_CLI_SERVER_WORKERS="$PHP_WORKERS" \
    /Applications/Herd.app/Contents/Resources/herd coverage \
      -dzend_extension="$XDEBUG_SO" \
      -dxdebug.mode=coverage \
      -dxdebug.start_with_request=yes \
      -S "${HOST}:${PORT}" "$ROUTER" \
    > "$PROJECT_ROOT/storage/coverage/server.log" 2>&1 &
  echo $! > "$PROJECT_ROOT/storage/coverage/server.pid"
)
SERVER_PID=$(cat "$PROJECT_ROOT/storage/coverage/server.pid")

# Make sure we always tear down the server, even on Ctrl-C / failure.
cleanup() {
  if kill -0 "$SERVER_PID" 2>/dev/null; then
    kill -TERM "$SERVER_PID" 2>/dev/null || true
    wait "$SERVER_PID" 2>/dev/null || true
  fi
}
trap cleanup EXIT INT TERM

echo "▸ Waiting for server to become ready..."
for i in $(seq 1 60); do
  if curl -fsS -o /dev/null "${BASE_URL}/up" 2>/dev/null; then
    echo "  ready after ${i}s"
    break
  fi
  if ! kill -0 "$SERVER_PID" 2>/dev/null; then
    echo "✗ Server died during startup. Last log lines:"
    tail -30 storage/coverage/server.log
    exit 1
  fi
  sleep 1
done

echo "▸ Running Playwright suite against ${BASE_URL} (workers=${PW_WORKERS})..."
# Skip 99-system-smoke during coverage runs by default: it hits the same URLs
# the per-resource specs already cover, but uses ~3x the runtime and tends
# to saturate the dev server. Pass --include-smoke to opt in.
INCLUDE_SMOKE=0
ARGS=()
for a in "$@"; do
  if [ "$a" = "--include-smoke" ]; then
    INCLUDE_SMOKE=1
  else
    ARGS+=("$a")
  fi
done

# Default to all specs except 99-system-smoke (unless user passed explicit paths).
# Bash 3-compatible (no mapfile/readarray on macOS default).
if [ "${#ARGS[@]}" -eq 0 ]; then
  while IFS= read -r f; do ARGS+=("$f"); done < <(
    if [ "$INCLUDE_SMOKE" = "1" ]; then
      ls tests/e2e/*.spec.js
    else
      ls tests/e2e/*.spec.js | grep -v '99-system-smoke'
    fi
  )
fi

set +e
PLAYWRIGHT_BASE_URL="${BASE_URL}" npx playwright test --workers="${PW_WORKERS}" "${ARGS[@]}"
PLAYWRIGHT_EXIT=$?
set -e

echo "▸ Stopping server..."
cleanup
trap - EXIT INT TERM

# When called from coverage-all.sh we skip the internal merge so its outer
# merge can include pest.cov alongside the per-request dumps.
if [ -n "${E2E_NO_MERGE:-}" ]; then
  echo "▸ Skipping internal merge (E2E_NO_MERGE set)."
else
  echo "▸ Merging coverage dumps..."
  php artisan coverage:merge --html=coverage/e2e
fi

echo ""
echo "✓ HTML report: file://${PROJECT_ROOT}/coverage/e2e/index.html"
echo "  Playwright exit code: ${PLAYWRIGHT_EXIT}"

exit "$PLAYWRIGHT_EXIT"
