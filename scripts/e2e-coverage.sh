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

if [ ! -f "$XDEBUG_SO" ]; then
  echo "✗ Xdebug binary not found at $XDEBUG_SO"
  echo "  Adjust the path in scripts/e2e-coverage.sh for your Herd/PHP build."
  exit 1
fi

echo "▸ Cleaning previous coverage dumps..."
rm -rf storage/coverage coverage/e2e
mkdir -p storage/coverage coverage/e2e

echo "▸ Starting coverage server on ${BASE_URL} (Xdebug coverage mode)..."
# Use PHP's built-in server directly instead of `artisan serve`. `artisan serve`
# only whitelists a fixed set of env vars when spawning workers (see
# vendor/laravel/framework/.../ServeCommand.php::$passthroughVariables), so
# COVERAGE=1 never reaches the request handler. `php -S` passes the full
# parent env through unchanged.
#
# The Laravel router script uses getcwd() for the include path, so we cd
# into public/ before launching the server.
ROUTER="$PROJECT_ROOT/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php"
(
  cd "$PROJECT_ROOT/public"
  COVERAGE=1 \
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

echo "▸ Running Playwright suite against ${BASE_URL}..."
set +e
PLAYWRIGHT_BASE_URL="${BASE_URL}" npx playwright test "$@"
PLAYWRIGHT_EXIT=$?
set -e

echo "▸ Stopping server..."
cleanup
trap - EXIT INT TERM

echo "▸ Merging coverage dumps..."
php artisan coverage:merge --html=coverage/e2e

echo ""
echo "✓ HTML report: file://${PROJECT_ROOT}/coverage/e2e/index.html"
echo "  Playwright exit code: ${PLAYWRIGHT_EXIT}"

exit "$PLAYWRIGHT_EXIT"
