#!/usr/bin/env bash
#
# The daily soak report — what the scheduler did overnight, and whether the books still tie.
#
# Runs ON THE BOX (cron, as the app user) and writes storage/logs/soak-YYYY-MM-DD.md; with --post it
# also sends the headline to the Discord webhook the box already has. It never writes to the
# database: every artisan call here is a read (health, config-health, the two audits, the books
# reconciliation) plus one read-only snippet, docs/qa/scripts/soak-deltas.php, run inside the app.
#
#   docs/qa/scripts/soak-check.sh            # report to stdout + the day's file
#   docs/qa/scripts/soak-check.sh --post     # …and post the headline to Discord
#
# Exit code: 0 when nothing is out of the ordinary, 1 when something needs a person — an
# unexpected health FAIL, a blocking config gap, a red reconciliation, an unbalanced trial balance,
# failed jobs, or ERROR lines in the application log since the last run.
#
# Expected health FAILs on a demo-posture staging box (docs/operations/STAGING.md §5) are ignored;
# override the list with SOAK_EXPECTED_HEALTH_FAILS="a,b,c". Likewise SOAK_EXPECTED_CONFIG_GAPS for
# blocking configuration rows the operator has not closed yet (default: seller_tax_identity).

set -uo pipefail

cd "$(dirname "$0")/../../.." || exit 2
POST=0
[[ "${1:-}" == "--post" ]] && POST=1

PHP="${PHP_BINARY:-php}"
TODAY="$(date +%F)"
NOW="$(date '+%Y-%m-%d %H:%M %Z')"
OUT="storage/logs/soak-${TODAY}.md"
STAMP="storage/logs/.soak-last-run"
if [[ -f "$STAMP" ]]; then SINCE="$(cat "$STAMP")"; else SINCE="$(date -d '24 hours ago' '+%Y-%m-%dT%H:%M:%S' 2>/dev/null || date -v-24H '+%Y-%m-%dT%H:%M:%S')"; fi
EXPECTED="${SOAK_EXPECTED_HEALTH_FAILS:-backup_capability,two_factor,demo_accounts}"
# Blocking configuration gaps that are KNOWN and the operator's to close (a demo box has no seller
# TRN; the soak doc records it). A NEW blocking row is still a problem.
EXPECTED_CFG="${SOAK_EXPECTED_CONFIG_GAPS:-seller_tax_identity}"

problems=()
note() { problems+=("$1"); }

# A brace group REDIRECTED, never piped into tee: a pipeline runs it in a subshell, and every
# problem noted inside — and the exit code at the end — would be lost with it.
{
  echo "# Soak report — ${NOW}"
  echo
  echo "- box: $(hostname) · env: $(grep -E '^APP_ENV=' .env | cut -d= -f2) · code: $(git rev-parse --short HEAD 2>/dev/null)"
  echo "- window: since ${SINCE}"
  echo

  # ── 1. Liveness ────────────────────────────────────────────────────────────────────────────
  echo "## 1 · atriom:health"
  echo '```'
  health="$($PHP artisan atriom:health 2>&1)"
  echo "$health"
  echo '```'
  while IFS= read -r row; do
    name="$(echo "$row" | awk -F'|' '{gsub(/ /,"",$2); print $2}')"
    [[ -z "$name" ]] && continue
    if [[ ",${EXPECTED}," != *",${name},"* ]]; then note "health: ${name} FAIL"; fi
  done < <(echo "$health" | grep -E '^\| [a-z_]+ +\| FAIL')
  echo

  # ── 2. Configuration ───────────────────────────────────────────────────────────────────────
  echo "## 2 · atriom:config-health (blocking rows only decide)"
  echo '```'
  cfg="$($PHP artisan atriom:config-health 2>&1)"; cfg_rc=$?
  echo "$cfg" | grep -E '^\| [a-z_]+ ' | awk -F'|' '{printf "%-38s %-10s %s\n", $2, $3, $4}'
  echo '```'
  while IFS= read -r row; do
    name="$(echo "$row" | awk -F'|' '{gsub(/ /,"",$2); print $2}')"
    [[ -z "$name" ]] && continue
    if [[ ",${EXPECTED_CFG}," != *",${name},"* ]]; then note "config-health: ${name} is a BLOCKING gap"; fi
  done < <(echo "$cfg" | grep -E '^\| [a-z_]+ +\| blocking +\| FAIL')
  echo

  # ── 3. The books ───────────────────────────────────────────────────────────────────────────
  echo "## 3 · billing:reconcile --deep"
  echo '```'
  rec="$($PHP artisan billing:reconcile --deep 2>&1)"; rec_rc=$?
  echo "$rec" | grep -E '^\| (✓|✗|×|!)|Net AR|Books tie|MISMATCH|does not|drift' | head -40
  echo '```'
  [[ $rec_rc -ne 0 ]] && note "reconcile --deep: RED (exit ${rec_rc})"
  echo

  echo "## 4 · data audits"
  echo '```'
  cs="$($PHP artisan atriom:audit-charge-schedules 2>&1)"; cs_rc=$?
  echo "$cs" | tail -3
  pd="$($PHP artisan atriom:audit-property-dimension 2>&1)"; pd_rc=$?
  echo "$pd" | grep -vE 'Expected: its property field is deliberately free' | tail -4
  echo '```'
  [[ $cs_rc -ne 0 ]] && note "charge-schedule audit: exit ${cs_rc}"
  [[ $pd_rc -ne 0 ]] && note "property-dimension audit: exit ${pd_rc}"
  echo

  # ── 5. What moved ──────────────────────────────────────────────────────────────────────────
  echo "## 5 · what moved since ${SINCE}"
  echo '```json'
  deltas="$($PHP artisan tinker --execute="\$since='${SINCE}'; $(sed 1d docs/qa/scripts/soak-deltas.php)" 2>&1)"
  echo "$deltas"
  echo '```'
  echo "$deltas" | grep -q '"balanced": true' || note "trial balance: debits ≠ credits"
  fj="$(echo "$deltas" | grep -E '"failed_jobs_since"' | grep -oE '[0-9]+' | head -1)"
  [[ "${fj:-0}" != "0" ]] && note "queue: ${fj} failed job(s)"
  echo

  # ── 6. What the scheduler said ─────────────────────────────────────────────────────────────
  echo "## 6 · scheduled runs (ops log)"
  echo '```'
  cat storage/logs/ops-*.log 2>/dev/null | awk -v s="${SINCE:0:10} ${SINCE:11:8}" '{ ts=substr($1,2,10)" "substr($2,1,8); if (ts >= s) print }' \
    | grep -vE 'Work-order SLA scan complete \{"overdue":0,"penalties_assessed":0,"alerted":0\}|Tenant-request SLA scan complete.*"breached":0' \
    | cut -c1-220 | tail -80
  echo '```'
  echo

  echo "## 7 · application errors since ${SINCE}"
  echo '```'
  errs="$(cat storage/logs/laravel*.log 2>/dev/null | awk -v s="${SINCE:0:10} ${SINCE:11:8}" '{ ts=substr($1,2,10)" "substr($2,1,8); if (ts >= s) print }' | grep -E '\.(ERROR|CRITICAL|ALERT|EMERGENCY):' )"
  n_err="$(echo -n "$errs" | grep -c . || true)"
  echo "ERROR lines: ${n_err}"
  echo "$errs" | cut -c1-260 | head -15
  echo '```'
  [[ "${n_err}" != "0" ]] && note "laravel.log: ${n_err} ERROR line(s)"
  echo

  # ── Verdict ────────────────────────────────────────────────────────────────────────────────
  echo "## Verdict"
  if [[ ${#problems[@]} -eq 0 ]]; then
    echo "**GREEN** — nothing needs a person."
  else
    echo "**NEEDS A LOOK** —"
    for p in "${problems[@]}"; do echo "- ${p}"; done
  fi
} > "$OUT"
cat "$OUT"

date '+%Y-%m-%dT%H:%M:%S' > "$STAMP"

# ── Discord ─────────────────────────────────────────────────────────────────────────────────
if [[ $POST -eq 1 ]]; then
  hook="$(grep -E '^DISCORD_WEBHOOK_URL=' .env | cut -d= -f2-)"
  if [[ -n "$hook" ]]; then
    headline="$(grep -A8 '^## Verdict' "$OUT" | tail -n +2 | head -9)"
    ar="$(echo "$deltas" | grep -E '"open_ar"' | head -1 | tr -d ' ,')"
    newdocs="$(echo "$deltas" | grep -E '"(invoices|payments|journal_entries|expenses|vendor_bills|work_orders)":' | tr -d ' ' | paste -sd' ' -)"
    body="$(printf '**Atriom soak · %s**\n%s\n\nnew: %s\n%s\nfull report: storage/logs/soak-%s.md' "$NOW" "$headline" "$newdocs" "$ar" "$TODAY")"
    payload="$("$PHP" -r 'echo json_encode(["content" => substr($argv[1], 0, 1900)]);' "$body")"
    curl -fsS -m 15 -H 'Content-Type: application/json' -d "$payload" "$hook" >/dev/null 2>&1 || echo "(discord post failed)" >&2
  fi
fi

[[ ${#problems[@]} -eq 0 ]]
