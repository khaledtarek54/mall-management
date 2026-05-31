#!/usr/bin/env bash
#
# Combined coverage: Pest (unit/integration) + Playwright (UI/integration).
# Both produce php-code-coverage `.cov` files that share an identical format,
# so they can be merged into a single report that reflects all PHP code paths
# exercised by either suite.
#
# Output: coverage/combined/index.html
# Use this as the canonical "how much of app/ is tested" number — Pest covers
# Jobs/Mail/Console/Api which Playwright can't reach, Playwright covers the
# Filament UI scaffolding Pest doesn't exercise.

set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$PROJECT_ROOT"

XDEBUG_SO="/Applications/Herd.app/Contents/Resources/xdebug/xdebug-84-arm64.so"

if [ ! -f "$XDEBUG_SO" ]; then
  echo "✗ Xdebug binary not found at $XDEBUG_SO"
  exit 1
fi

echo "▸ Clean slate..."
rm -rf storage/coverage coverage/combined
mkdir -p storage/coverage coverage/combined

echo "▸ Step 1/3: Pest with coverage (exporting to storage/coverage/pest.cov)..."
# Use --coverage-php only (no --coverage). The --coverage flag prints the
# threshold report and exits 2 when min-coverage isn't met, which suppresses
# the .cov export.
/Applications/Herd.app/Contents/Resources/herd coverage \
  -dzend_extension="$XDEBUG_SO" \
  -dxdebug.mode=coverage \
  vendor/bin/pest --coverage-php=storage/coverage/pest.cov \
  > storage/coverage/pest.log 2>&1 || {
    echo "✗ Pest failed. See storage/coverage/pest.log"
    tail -30 storage/coverage/pest.log
    exit 1
  }
if [ ! -f storage/coverage/pest.cov ]; then
  echo "✗ Pest finished but pest.cov was not written. See storage/coverage/pest.log"
  exit 1
fi
PEST_BYTES=$(stat -f%z storage/coverage/pest.cov 2>/dev/null || stat -c%s storage/coverage/pest.cov)
echo "  Pest done — pest.cov ${PEST_BYTES} bytes"

echo ""
echo "▸ Step 2/3: Playwright e2e with per-request coverage..."
# Tell e2e-coverage.sh to skip its internal merge so its cleanup doesn't
# delete pest.cov — we'll merge both together in step 3.
E2E_NO_MERGE=1 scripts/e2e-coverage.sh "$@" || true

echo ""
echo "▸ Step 3/3: Merging Pest + Playwright into one report..."
php artisan coverage:merge --html=coverage/combined --keep

echo ""
echo "✓ Combined HTML report: file://${PROJECT_ROOT}/coverage/combined/index.html"
