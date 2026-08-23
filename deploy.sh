#!/usr/bin/env bash
#
# Atriom — release deploy.
#
# WHY THIS FILE EXISTS. INFRASTRUCTURE.md §10 has referred to "a deploy.sh" since the document was
# written, and there was never one. So every release was somebody retyping nine commands from
# PRODUCTION-RUNBOOK.md §2 into a shell, in order, on the right box, as the right user — and the
# failure mode of that is not an error, it is a step quietly skipped. Two of those steps fail
# SILENTLY when skipped: `npm run build` leaves both panels rendering as unstyled HTML, and
# `queue:restart` leaves workers executing the previous release's code against the new schema.
#
# This is the runbook's sequence, in one command, that refuses rather than continues.
#
#   ./deploy.sh                  # deploy this environment (prompts on production)
#   ./deploy.sh --yes            # no prompt — for a CI/CD caller
#   ./deploy.sh --skip-migrate   # code-only release; refuses if migrations are pending
#   ./deploy.sh --skip-search    # skip the search re-fold (a huge database, and you know this
#                                # release changed no searchTextSources())
#
# Rollback is NOT here on purpose. Restoring a release means restoring its DATABASE too, and
# `down()` drops columns in 171 of this project's migrations — so a code-only rollback silently
# leaves the schema ahead of the code. See PRODUCTION-RUNBOOK.md §8 and §13.

set -Eeuo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")"

ASSUME_YES=0
SKIP_MIGRATE=0
SKIP_SEARCH=0

for arg in "$@"; do
  case "$arg" in
    --yes|-y)       ASSUME_YES=1 ;;
    --skip-migrate) SKIP_MIGRATE=1 ;;
    --skip-search)  SKIP_SEARCH=1 ;;
    -h|--help)      sed -n '2,22p' "$0"; exit 0 ;;
    *) echo "unknown option: $arg" >&2; exit 2 ;;
  esac
done

step() { printf '\n\033[1;36m▶ %s\033[0m\n' "$1"; }
ok()   { printf '\033[0;32m  ✓ %s\033[0m\n' "$1"; }
die()  { printf '\n\033[0;31m✗ %s\033[0m\n' "$1" >&2; exit 1; }

# Maintenance mode is lifted even if a step below dies, so a failed deploy cannot leave the
# operator staring at a 503 with no idea why. Nothing else is undone: a partial deploy must stay
# visible rather than be silently reverted into an unknown state.
MAINTENANCE=0
cleanup() {
  if [[ $MAINTENANCE -eq 1 ]]; then
    php artisan up >/dev/null 2>&1 || true
    printf '\033[0;33m  ! maintenance mode lifted after a failed deploy — the release is INCOMPLETE\033[0m\n' >&2
  fi
}
trap cleanup EXIT

# ---------------------------------------------------------------------------
# Pre-flight — everything that should stop a deploy before it changes anything
# ---------------------------------------------------------------------------
step "Pre-flight"

[[ -f .env ]] || die ".env is missing. This box has never been provisioned — see INFRASTRUCTURE.md §10."

command -v php >/dev/null      || die "php is not on PATH."
command -v composer >/dev/null || die "composer is not on PATH."
command -v npm >/dev/null      || die "npm is not on PATH. Node is REQUIRED — without it both panels render unstyled."

APP_ENV="$(php -r 'echo trim(explode("=", array_values(array_filter(file(".env"), fn($l) => str_starts_with(trim($l), "APP_ENV=")))[0] ?? "APP_ENV=unknown", 2)[1] ?? "unknown");' 2>/dev/null || echo unknown)"
ok "environment: ${APP_ENV}"

# A dirty tree means someone edited files on the server. `git pull` would either clobber that work
# or fail on a conflict halfway through the deploy; both are worse than stopping now.
if [[ -n "$(git status --porcelain)" ]]; then
  die "the working tree has local changes. Commit, stash or discard them before deploying:
$(git status --short)"
fi

PREVIOUS_REF="$(git rev-parse --short HEAD)"
ok "current release: ${PREVIOUS_REF}"

if [[ "$APP_ENV" == "production" && $ASSUME_YES -eq 0 ]]; then
  printf '\n\033[1;33mThis is PRODUCTION. Deploy %s? [y/N] \033[0m' "$PREVIOUS_REF"
  read -r reply
  [[ "$reply" =~ ^[Yy]$ ]] || die "aborted."
fi

# ---------------------------------------------------------------------------
step "Fetching the release"
git pull --ff-only
ok "now at $(git rev-parse --short HEAD)"

# ---------------------------------------------------------------------------
step "Installing PHP dependencies"
composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

# ---------------------------------------------------------------------------
# `npm ci` not `npm install`: the lockfile is the release. And the build must happen BEFORE any
# prune, because vitepress (which builds the handbook) is a devDependency.
step "Building assets and the handbook"
npm ci
npm run build
php artisan filament:assets

[[ -d public/build ]] || die "public/build is missing after the build — both panels would render with no CSS."
[[ -d storage/app/handbook ]] || die "storage/app/handbook is missing after the build — /handbook would 503."
ok "public/build and storage/app/handbook are present"

# ---------------------------------------------------------------------------
step "Entering maintenance mode"
# `--render` serves a styled page rather than a bare 503, and the secret lets you walk the site
# while it is down to confirm the release before lifting it for everyone.
php artisan down --render="errors::503" --retry=60 --secret="deploy-${PREVIOUS_REF}" || php artisan down --retry=60
MAINTENANCE=1
ok "site is down (bypass: /deploy-${PREVIOUS_REF})"

# ---------------------------------------------------------------------------
if [[ $SKIP_MIGRATE -eq 1 ]]; then
  step "Skipping migrations (--skip-migrate)"
  # A code-only release whose code expects a column that is not there yet is not a code-only
  # release. Refuse rather than deploy half of a change.
  if php artisan migrate:status 2>/dev/null | grep -qi "pending"; then
    die "--skip-migrate was passed but migrations are PENDING. The new code would run against the old schema."
  fi
  ok "no pending migrations"
else
  step "Running migrations"
  php artisan migrate --force
fi

# ---------------------------------------------------------------------------
# The step this script existed to make unforgettable and did not run (added 2026-08-23).
#
# A migration creates an EMPTY table; the rows come from a seeder, and `atriom:install` is the only
# thing that lays down the reference data and re-syncs the RBAC catalogue. Both failure modes are
# silent and neither is an error:
#
#   * a permission that exists only in the seeder file leaves its screen ABSENT FROM THE NAVIGATION
#     for everyone, including super_admin — `canAccess()` simply returns false. That is how the
#     Trades and Failure-code registers shipped invisible on 2026-08-20, and it took the operator
#     opening the panel to find it;
#   * an upgraded box gets the schema of a new catalogue and none of its content — no rails to
#     activate, none of the seeded expense categories — while every screen renders normally.
#
# It runs in the `--skip-migrate` branch TOO, deliberately: a release can add a permission or a
# catalogue row with no migration at all, which is exactly what the 2026-08-20 one did.
#
# Idempotent by design — it re-asserts reference data, touches no business row, never seeds demo
# data, and verifies through the journalizers' own resolver that the database can still post.
step "Re-asserting reference data and permissions"
php artisan atriom:install --force
ok "reference data + RBAC catalogue in step with this release"

# ---------------------------------------------------------------------------
step "Rebuilding caches"
# optimize:clear first — a cached config from the PREVIOUS release survives otherwise, and the
# symptom (an env var that will not take effect) looks like a bad .env rather than a stale cache.
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
ok "config, routes, views and events cached"

# storage:link is a first-deploy step, but it is idempotent and a missing link breaks every media
# URL — cheap to assert on every release rather than remember once.
php artisan storage:link >/dev/null 2>&1 || true

# ---------------------------------------------------------------------------
step "Restarting workers"
# Money flows through the queue (GL posting, notifications, exports). Without this, workers keep
# running the OLD code against the NEW schema for as long as they live.
php artisan queue:restart
ok "workers signalled to restart"

# ---------------------------------------------------------------------------
step "Lifting maintenance mode"
php artisan up
MAINTENANCE=0
ok "site is live"

# ---------------------------------------------------------------------------
# The fold blob is written by the model on save, so a RESTORE or an UPGRADE leaves every existing
# row carrying the fold of the release before it — and the failure is silent in the worst way: the
# search bar reports that a record does not exist. The 2026-08-20 trade register is exactly that
# (`category` left three blobs when it stopped being a column).
#
# The runbook's rule was "if a release changes any searchTextSources(), this command is part of the
# release", which is a question somebody has to remember to ask. It runs every time instead: the
# rebuild compares the fold before writing and SKIPS the row when it is unchanged, so on a release
# that touched nothing this is a read-only pass. Chunked by id, written quietly, and it does not
# move `updated_at`.
#
# After `up` on purpose — it is a data pass that is safe while the site is live, and holding the
# site down for it would trade a silent bug for real downtime.
if [[ $SKIP_SEARCH -eq 1 ]]; then
  step "Skipping the search re-fold (--skip-search)"
else
  step "Re-folding the search index"
  php artisan atriom:rebuild-search
fi

# ---------------------------------------------------------------------------
# The preflight is the deploy's own verdict. It is reported, not enforced: a stopped worker or a
# stale backup is a real problem but not a reason to roll back a release that deployed correctly,
# and an exit code here would make the two indistinguishable.
#
# `--quick` runs the health check AND the two data audits, and skips only the deep reconciliation.
# Until 2026-08-19 this step was `atriom:health` alone, so a release could leave leases whose charge
# rows overlap — which bill NOTHING — or money documents filed against no property, and the deploy
# would report "healthy" because the box was alive. Liveness and correctness fail differently, and
# the audits are count queries: they cost nothing to run on every release.
#
# The deep reconciliation is deliberately NOT here. It scales with history rather than with the
# portfolio, and a check slow enough to be irritating on the fifth release of an afternoon is a
# check somebody eventually comments out. It runs at cutover (STAGING-CUTOVER.md §5) and weekly on
# the scheduler.
step "Post-deploy preflight"
if php artisan atriom:preflight --quick; then
  ok "preflight clean"
else
  printf '\033[0;33m  ! atriom:preflight reports problems — the release is LIVE; see the FAIL rows above.\033[0m\n'
fi

printf '\n\033[1;32m✓ Deployed %s → %s (%s)\033[0m\n' "$PREVIOUS_REF" "$(git rev-parse --short HEAD)" "$APP_ENV"
printf '  Rolling back? Read PRODUCTION-RUNBOOK.md §13 first — the schema moved too.\n'
