# Staging soak — one month unattended, September 2026

**What this is.** From 2026-09-05 the staging box (`docs/operations/STAGING.md`) runs a second
property, **Nile Gate Mall (NG)**, seeded by `Database\Seeders\NileGateSeeder` beside the Val Plaza
demo mall, and is left alone. The scheduler fires every night exactly as it would in production, and
every morning a person reads what it did against what it should have done. **Val Plaza (VP) is not
part of the test** — it is the demo mall and stays as the demo team left it.

The question the month answers is not "does the code work" (the suite answers that) but **"does the
system keep the books right when nobody is driving it"** — the class of defect that only time
produces: a sweep that never fires, a run that fires twice, a projection that goes stale on a day
nothing happened, a figure that drifts between two readers.

**The dataset is built so that something is SUPPOSED to happen on a known day.** A mall seeded
mid-life proves the system holds a portfolio; it gives the scheduler nothing to do. Nile Gate has a
lease whose rent anniversary falls on the 15th, a lease that expires at month-end, a contract that
ends on the 12th, a cheque that matures on the 8th, a tenant two months in arrears, a draft lease
that must NOT bill on the 1st — each one a check with a date on it.

## The property

Twenty units on two floors, 2,140 m² leasable. Seven active leases, one draft, two sold units,
three contractors, four employees, two fixed assets, two bank accounts on their own chart leaves.
Everything is written through the service that owns it (leases through `LeaseCreationService`,
every invoice through `MonthlyBillingService::runForPeriod()` — the same method the 02:00 job calls),
so the history is what the system would have produced had it been running since September 2025.

| Tenant | Unit | Rent | Why it is here |
|---|---|---|---|
| Carrefour Express | B-01 | 90,000 → 96,300 | Anchor. Rent anniversary **15 Sep**: the ladder already stepped September's invoice to 96,300 (a mid-month step snaps to its billing month, by design), so the 15 Sep sweep moves the lease's own rent figure and must add no second rung. Ten-cheque series from 10 Sep at the stepped amount. Percentage rent; August undeclared. COI lapses 22 Sep. |
| Al Tazaj | A-04 | 30,000 | **Two months behind** → late fee, dunning, arrears ageing. Percentage rent; August undeclared. COI already lapsed. Urgent HVAC request open. |
| Cairo Optics | A-01 | 15,000 | Lease **expires 30 Sep** → `leases:expire` frees the shop on 1 Oct, holdover decision. |
| Nano Pharmacy | A-07 | 12,000 | Commenced 16 Aug → prorated first invoice. Deposit billed, **unpaid**. |
| Fit Zone Gym | B-04 | 45,000 | August **half-paid**. Renewal option window closes **25 Sep**; expansion window over B-05 opens 12 Sep. |
| Koshary Abou Tarek | A-05 | 35,000 | Pays by cheque: one matures **8 Sep** (held), one 8 Oct. |
| Orange Kiosk | A-06 | 6,000 | Commenced 1 Sep → first invoice, unpaid → overdue 9 Sep. |
| Bershka | B-02 | 40,000 | **DRAFT** from 1 Oct. Must not bill until activated. |
| Hassan Mahmoud (owner) | B-03 | صيانة 7,150 | Unit owner, pays every assessment. |
| Layla Farouk (owner) | A-02 | صيانة 4,125 | Unit owner, **stopped paying** in August. |

Payables: Nile Clean (cleaning retainer, draft bill raised on the 7th), Guardian Security (contract
**ends 12 Sep**, notice already overdue; retainer on the 20th — does a schedule tied to an expired
contract still bill?), Delta Elevators (COI lapses 25 Sep; a draft call-out bill awaiting approval).
Two schedules without a vendor: municipal waste levy (15th), telecom (25th).

## The calendar — what should happen, and when

D0 = **Sat 5 Sep 2026**. Times are Africa/Cairo, from `php artisan schedule:list`.

| When | Job | Expected on Nile Gate |
|---|---|---|
| every night 01:30 | `marketing:ensure-budgets` | a marketing budget row for NG appears on the first night |
| 6 Sep 02:45 | `vendors:scan-contract-renewals` | Guardian Security: notice deadline passed → alert |
| 6 Sep 02:45 | `vendors:scan-document-expiry` · `tenants:scan-document-expiry` | Delta COI (25 Sep), Carrefour COI (22 Sep), Al Tazaj COI (lapsed) → alerts |
| 6 Sep 04:00 | `atriom-late-fees` | Al Tazaj's August invoice (due 8 Aug, grace 7) → 2% late fee invoice; Fit Zone's half-paid August likewise; Layla's August assessment likewise; **and Nano's unpaid DEPOSIT bill** (due 23 Aug) — a late fee on a security deposit is a question for the accountant, so note what it does. Four fees in all (dry-run confirmed). |
| 6 Sep 06:00 / 06:15 | `billing:scan-overdue-invoices` · `remind-overdue-tenants` | owner alert + tenant reminders for the overdue set |
| 7 Sep 02:30 | `facility:generate-preventive` | weekly cleaning inspection → work order |
| 7 Sep 05:30 | `expenses:generate-recurring` | Nile Clean retainer → **draft vendor bill** (supplier's number blank, awaiting approval) |
| 8 Sep 07:45 | `pdc:scan-maturing` | Koshary cheque matured, still held → reported |
| 9 Sep 06:00 | overdue scan | Every unpaid September lease invoice (due 8 Sep) joins the overdue set: Carrefour and Koshary (cheques pending), Al Tazaj, Nano, Fit Zone, Orange — six; the owners' assessments are due the 15th |
| 9 Sep 12:00 | `announcements:send-scheduled` | the fire-drill notice goes out |
| 10 Sep | Carrefour cheque #1 matures | held until somebody banks it (an act) |
| 10 Sep 08:00 | `sales:scan-missing-declarations` | Carrefour + Al Tazaj reminded: August undeclared |
| 12 Sep 02:30 | preventive generator | generator monthly test-run → work order |
| 12 Sep 06:45 | `leases:scan-option-windows` | Fit Zone expansion window OPENS → alert |
| 13 Sep 02:30 | `vendors:expire-contracts` | Guardian Security → `expired` |
| 14 Sep 08:00 | `pdc:scan-coverage` (Mondays) | Carrefour's cheques run out June 2027, lease runs to Sept 2028 → reported |
| **15 Sep 05:30** | `leases:apply-escalations` | **Carrefour's rent figure 90,000 → 96,300** and a lease event recorded. NOTE: September was ALREADY billed at 96,300 (INV-NG-0058 = 118,215) — `ChargeScheduleService::billingBoundary()` snaps a mid-month step to the start of its billing month, a documented rule ("bills all of April at the new rent, as it always has"), stricter than Yardi, which prorates the two rates within the month. The sweep must NOT step it a second time. |
| 15 Sep 05:30 | recurring | municipal waste levy → expense |
| 16 Sep 04:00 | late fees | Every September lease invoice still unpaid after the 7-day grace → late fee (six if no cheque was banked; dry-run: 6). Banking Carrefour's and Koshary's cheques before the 15th is what keeps theirs off the list |
| 17 Sep 07:30 | `sales:estimate-missing` | estimated August declarations for Carrefour + Al Tazaj |
| 20 Sep 02:30 | preventive | quarterly fire-safety inspection → work order |
| 20 Sep 05:30 | recurring | Guardian retainer — its contract ended on the 12th, so **nothing is raised** (SW-242, fixed 5 Sep before the box reached this date); the register's *Next due* for it reads blank |
| 25 Sep 02:45 | vendor document scan | Delta COI lapses today |
| 25 Sep 05:30 | recurring | telecom → expense |
| 25 Sep 06:45 | option windows | Fit Zone renewal window CLOSES → alert; lapses if nobody acted |
| 28 Sep 03:30 | `accounting:post-depreciation` | September depreciation on the chiller + CCTV |
| **1 Oct 02:00** | `atriom-monthly-billing` | October invoices for every ACTIVE lease at the rent in force; Bershka (draft) must NOT bill; Cairo Optics must NOT bill (expired 30 Sep) |
| 1 Oct 02:30 | `billing:run-assessments` | October assessments for both owners |
| 1 Oct 05:15 | `leases:expire` | Cairo Optics → `expired`, A-01 → vacant, holdover card on the dashboard |
| 2 Oct 04:00 | straight-line rent | no-op (switched off in Settings → Billing) — confirm it stays a no-op |
| Fridays 03:00 / 04:00 | `accounting:sync-ledger --all` · `billing:reconcile --deep` | both green |
| Sundays 03:00 | `atriom:backup-verify` | restore drill green |

## The acts ledger — and they are MINE, not the operator's

**Standing instruction (2026-09-05, from Khaled): I run this month end to end myself** — the daily
check, the operator acts below, and any fix a finding needs. He reads the result each day. He is
away 5–9 Sep; nothing waits for him.

Every act goes through the SERVICE the panel's own button calls, driven on the box with
`php artisan tinker --execute` as the app user. That proves the service, the guards, the ledger and
the audit trail — not the Livewire form, which is proved separately by the browser sweep. **Never
raw SQL, never a status written by hand**: a state reached by assignment is exactly what this system
refuses, and an act performed that way would test nothing.

**Update the Status column in the same commit as the act**, with the date and the document it
produced. A ledger nobody maintains is worse than none — a fresh session reads this table to know
what is overdue.

| # | Act | Due | How (the service the panel calls) | Status |
|---|---|---|---|---|
| A1 | Set the statutory payroll rates, re-line and approve the August NG run | 5 Sep | `PayrollRate` rung → delete draft lines → `GeneratePayrollService::generate()` → `PayrollService::approve()` | ✅ **5 Sep** — 10% / 11% / 18.75%; PAY-NG-202608-0001 approved, gross 35,300, net 27,887, posted as JE-0147 (Dr wages 35,300 + employer SI 6,618.75 / Cr tax 3,530, insurance 10,501.75, net 27,887) |
| A2 | Approve the cleaning retainer's draft bill the schedule raises, giving it the supplier's own invoice number | 7 Sep | `VendorBill::reference` then `VendorBillService::approve()` | ⬜ |
| A3 | Bank Koshary's matured cheque: deposit to CIB on its maturity date, then clear it against the September invoice | 8 Sep | `PostDatedChequeService::deposit()` then `::clear()` | ⬜ |
| A4 | Answer the three tenant requests; complete the HVAC work order (labour, parts, checklist) | 8–9 Sep | `TenantRequestService::transition()/comment()`, `FacilityWorkOrderService::markItem()/transition()` | ⬜ |
| A5 | Bank Carrefour's first cheque; **bounce the second deliberately** and watch the NSF path | 10 Sep, 10 Oct | `PostDatedChequeService::deposit()/clear()/bounce()` | ⬜ |
| A6 | Declare Al Tazaj's August sales (after the 10th reminder, before the 17th estimate); leave Carrefour undeclared so the estimate stands | 11–16 Sep | `TenantSalesDeclaration` + `PercentageRentCalculationService::recalculate()/lock()` | ⬜ |
| A7 | Pay Guardian's open bill before its due date | by 14 Sep | `VendorBillService::recordPayment()` | ⬜ |
| A8 | Approve Delta's draft call-out bill | by 15 Sep | `VendorBillService::approve()` | ⬜ |
| A9 | Exercise or lapse Fit Zone's renewal option before the window closes | by 24 Sep | `ExerciseLeaseOptionService` (or let it lapse, and check the 25th alert) | ⬜ |
| A10 | Activate Bershka's draft lease, bill its deposit, receive it | by 30 Sep | `Lease` status → `active`, `BillSecurityDepositService::bill()`, receipt | ⬜ |
| A11 | Decide Cairo Optics after it expires: convert to holdover, or run the move-out (final account, deposit netting, refund) | 1–3 Oct | `ConvertLeaseToHoldoverService` **or** `SettleMoveOutService` | ⬜ |
| A12 | Close September, then try to post into it and confirm the refusal | after 2 Oct | Month-End Close, then a back-dated expense | ⬜ |
| A13 | Owner statement for September for Jawad, and a disbursement | after the close | `GenerateOwnerStatementRunService` → `FinaliseOwnerStatementRunService` → `DisbursementService` | ⬜ |

Each act is itself a check: the refusal wording, the ledger entry, the tenant's portal and the trial
balance must all agree afterwards. **An act that is REFUSED is a result, not a blocker** — record the
refusal and whether it was right.

## The daily runbook

Run every day, in this order. It takes ten minutes when nothing is wrong.

**1 · Read the box's own report.**

```bash
ssh root@144.91.115.90 "cat /var/www/atriom-staging/current/storage/logs/soak-$(date +%F).md"
```

Missing (the cron did not fire)? Run it by hand and say so in the journal:
`ssh root@… 'cd /var/www/atriom-staging/current && sudo -u atriom-staging bash docs/qa/scripts/soak-check.sh --post'`

**2 · Check what ran against what should have run.** Take the calendar rows for yesterday and today
and verify each one by querying the box — the document exists, on the right property, for the right
figure. A row that produced NOTHING is the interesting case, and it is invisible in a report that
only lists what happened. The ops log is the record of every scheduled run.

**3 · Perform the acts due today** from the ledger above, and tick them.

**4 · Anything unexpected → the fix loop.** In order, and none of it skipped:

  1. Reproduce it LOCALLY (the scratch database `mall_soak_scratch`, or a Pest test) — never debug
     by editing staging.
  2. Read the module doc for the rule before changing the rule.
  3. Fix it in the service that owns it, one seam, not at the call sites.
  4. Write the regression test in `tests/Feature/Regression/`, and **mutation-prove it**: undo the
     fix by hand, watch it go red, put it back. A test that passes either way proves nothing.
  5. Run the targeted suites — the ones that touch what you CHANGED, not only what you fixed.
  6. `vendor/bin/pint` on the touched files.
  7. **Adversarial review before committing** — a subagent told to break the change. On this project
     it has caught something real in every fix so far, including two that would have shipped a worse
     bug than the one being fixed.
  8. Commit with the reason, update the module doc + `DEEP-SWEEP-2026-09-01.md` row + this journal in
     the SAME commit, push.
  9. Deploy: `ssh root@… 'cd /var/www/atriom-staging/current && sudo -u atriom-staging bash -c "setsid nohup ./deploy.sh --yes > /tmp/atriom-deploy-$(date +%Y%m%d-%H%M%S).log 2>&1 &"'`, then poll the log.
  10. **VALIDATE ON THE BOX.** The fix is not done until the behaviour is confirmed against staging's
      own data — re-run the scan that produced the wrong answer and read the new one. A green test on
      a laptop is not the same claim.

**5 · Write the journal row** — date, verdict, what ran, what was found, what was done, hashes — and
commit it with the day's work.

**6 · Report to Khaled**, whether or not he asks: what happened, what was found and fixed, what is
expected tonight, and anything that genuinely needs him. Plain English and Arabizi, no Arabic script.

### Reading the report correctly

- `atriom:health` is red on three rows BY DESIGN on this box — `backup_capability`, `two_factor`,
  `demo_accounts` (STAGING.md §5). The script ignores exactly those three. **Anything else is real.**
- `atriom:config-health` has NO expected gap: every blocking row is green as of 5 Sep, so a blocking
  row appearing during the month is a regression. `payroll_rates_configured` went green with act A1.
- `billing:reconcile --deep` must stay 9/9. It is the one line that says the money is still right.
- **E-mail is known-broken on the box** — the MailerSend token is rate-limited (HTTP 429), so every
  tenant e-mail fails with a WARNING in the ops log. The database/bell channel still lands, so
  notifications ARE testable; delivery is not. Do not re-report it daily.

### Standing rules

- **Never reseed or reset staging**, and never touch Val Plaza. Losing the soak's accumulated state
  loses the month.
- **Add events by ACTING, not by seeding.** The dataset is finished; anything else that happens on it
  must happen the way an operator would make it happen.
- **Record every anomaly in the journal even when it turns out to be correct behaviour** — the
  explanation is the value, and next month nobody will remember why the 15th looked odd.

## Journal

| Date | Verdict | What ran / what was seen | Findings |
|---|---|---|---|
| 2026-09-05 (evening) | act A1 done | **Standing instruction changed: I run the month myself** — the daily check, the operator acts (new ledger above) and any fix. Khaled away 5–9 Sep. Did A1: set the payroll rates (10% salary tax as a flat placeholder for the bracket table, 11% employee / 18.75% employer NOSI), re-lined the August NG run and approved it — gross 35,300, net 27,887, posted as **JE-0147** (Dr wages 35,300 + employer insurance 6,618.75 / Cr tax 3,530, insurance 10,501.75, net 27,887; balanced). `payroll_rates_configured` went green. Also corrected the daily check's expected-gap default to EMPTY (`416b8656`): the box has no blocking configuration gap, and an ignore-list carrying a row that has since been fixed is how a real gap goes quiet. | Chased one suspected bug to nothing: `payroll_rates.note` is varchar(255) and I hit the limit from tinker — the FORM caps it at 255 correctly. A scan of every `TextInput` writing a bounded column found 7 with no cap; five look real and are queued as a low-severity batch. |
| 2026-09-05 (late) | reviewed + deployed | Both change batches went through an adversarial review before commit, and both reviews found real things. **On the soak seeder:** the Carrefour +7% step is ALREADY in September's invoice (a mid-month anniversary snaps to its billing month — `ChargeScheduleService::billingBoundary()`), so the calendar's 15 Sep row, the cheque-series reasoning and two hardcoded fallback amounts were all wrong; the seeder now stops loudly rather than inventing a figure, and refuses to continue if a billing run considered nothing (a held period lock answers all-zeros in silence). **On the demo seeder:** the ageing spread could stamp a two-month covered window, and `historyLines()` keyed charges by type alone, which would price every line at the final ladder rung once the ladder is projected. Both fixed. Commits `0faa35f9` (seeders), `39d20a6a` (SW-242), `3e1be764`, deployed to staging. | — |
| 2026-09-05 | pre-validated | Every scheduled event in the calendar was dry-run on the scratch copy with `--date`/`--period` before staging was seeded: 4 late fees on D+1 and 6 more on the 16th, the cleaning bill drafted on the 7th (28,500 incl. VAT), the waste levy expensed on the 15th (5,130), Guardian's retainer STILL billed on the 20th under a contract that ended on the 12th (68,400 — a question for the operator, see the calendar), 1 + 3 preventive work orders, both August declarations reminded then estimated, Carrefour stepped once on the 15th, September depreciation posted. Books tied out after all of it; every charge schedule unambiguous. | **First finding, from the dry-run:** a recurring cost LINKED to a vendor contract keeps raising draft bills after that contract has ended — `GenerateRecurringExpensesService::raiseVendorBill()` copies `vendor_contract_id` onto the bill and never asks whether the contract is still in force; the schedule reads only its own `ends_on`. Yardi's recurring payable is bounded by the contract term. Fixed the same day as **SW-242** (`39d20a6a` — `RecurringExpense::effectiveEndsOn()`, own end or the contract's, whichever is earlier; the review of the fix closed four more doors: re-linking an ended contract on edit, a terminated contract keeping its original term, a deleted contract lifting the bound, and an N+1 on the register; `ARecurringCostStopsWhenItsContractEndsTest`, 9 cases). On the box the 20 Sep row now reads: Guardian's retainer must NOT bill. |
| 2026-09-05 | seeded | NG seeded on the scratch database first, then on staging: 66 invoices, 53 receipts, 12 cheques, 5 bills, GL posted. `billing:reconcile --deep` 9/9 green. | Two seeder-side findings fixed before staging: receipts created outside a transaction posted before their allocation (53 void + reversal pairs); a back-dated billing run dates DUE from the run day, by design, so seeded history re-anchors due dates to issue date + terms. |
