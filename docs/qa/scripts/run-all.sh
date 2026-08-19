#!/bin/zsh
# Run the whole pre-staging QA harness against the isolated MySQL database.
#
#   composer qa            — every suite, each from a clean seeded baseline
#   composer qa 20_ar      — only the suites whose filename matches
#
# The point of the reset-per-suite is determinism: these scripts write real data through the real
# services, so a suite that ran before this one would otherwise change what this one measures.
# `composer qa:baseline` builds the snapshot the reset restores; run it once, and again after any
# migration or seeder change.
set -u
QA="$(cd "$(dirname "$0")" && pwd)"
cd "$QA/../../.." || exit 1

FILTER="${1:-}"

if [[ ! -f "$QA/baseline.sql" ]]; then
  echo "No baseline snapshot. Run:  composer qa:baseline" >&2
  exit 1
fi

# Ordered deliberately: the four core modules first (they are the ones a change is most likely to
# break), then the module batches, then the isolated finding reproductions and fix verifications.
SUITES=(
  00_baseline.php
  01_spacing.php 02_spacing_owners.php 03_resale_proration.php 14_premises_dates.php
  10_leasing_billing.php 11_leasing_lifecycle.php 12_leasing_termination.php
  13_leasing_pctrent_relief.php 41_lease_options.php
  20_ar_channels.php 21_ar_reversals.php
  30_payables.php 31_procurement.php
  40_month_cycle.php 42_full_month_all_shapes.php
  50_page_sweep.php 51_search_rbac.php 52_rbac_matrix.php
  53_property_isolation.php 54_cross_property_money.php
  60_cam.php 70_form_interactions.php 90_deposits_owner.php 91b_owner_correct.php
  A1_meters_inventory_assets.php A2_payroll_custody_violations.php
  B1_requests_facility_approvals.php C1_tenants_portal_comms.php D1_api_reports_search.php
  V01_f01_fixed.php V02_f02_fixed.php V04_f04_fixed.php V08_f08_fixed.php V11_f11_fixed.php
  O01_payroll_cancel_is_by_design.php
)

pass=0; fail=0; failed_suites=()

for suite in "${SUITES[@]}"; do
  [[ -n "$FILTER" && "$suite" != *"$FILTER"* ]] && continue
  [[ -f "$QA/$suite" ]] || { echo "  MISSING  $suite"; continue }

  out="$("$QA/run.sh" "$suite" 2>&1)"
  line="$(print -r -- "$out" | grep -oE '[0-9]+ passed.*' | tail -1)"
  p="$(print -r -- "$line" | grep -oE '^[0-9]+' || echo 0)"
  f="$(print -r -- "$line" | grep -oiE '[0-9]+ (failed|FAILED)' | grep -oE '^[0-9]+' || echo 0)"

  pass=$((pass + p)); fail=$((fail + f))

  if [[ "$f" == "0" && -n "$line" ]]; then
    printf '  \033[32mok\033[0m    %-38s %s\n' "$suite" "$line"
  else
    printf '  \033[31mFAIL\033[0m  %-38s %s\n' "$suite" "${line:-no summary — the suite died, run it alone}"
    failed_suites+=("$suite")
    print -r -- "$out" | tail -6 | sed 's/^/          /'
  fi
done

echo
printf '  %d assertions passed, %d failed\n' "$pass" "$fail"
for s in "${failed_suites[@]}"; do echo "    · $s"; done
[[ ${#failed_suites[@]} -eq 0 ]] || exit 1
