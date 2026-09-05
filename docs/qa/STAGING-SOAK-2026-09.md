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

## The acts — what a person does during the month

The scheduler cannot do these, and the month is only a full cycle if somebody does:

1. **Set the statutory payroll rates** (Settings → Payroll rates) and **approve the August run**,
   which the seeder left in DRAFT on purpose: the shipped rung carries the insurable band with every
   rate at zero, and an approved run that withholds nothing is a BLOCKING configuration-health row.
   Then generate and approve September's run.
2. **Bank the cheques as they mature** — Koshary on/after 8 Sep (deposit to CIB, then clear against
   the September invoice), Carrefour's from 10 Sep. Bounce one deliberately and watch the NSF path.
3. **Approve Delta's draft call-out bill**, and the draft bill the cleaning schedule raises on the 7th
   (fill in the supplier's invoice number). Pay Guardian's open bill before 15 Sep.
4. **Answer the three tenant requests**; complete the HVAC work order it raised (costs, checklist).
5. **Declare August sales** for one of the two (before the 10th to beat the reminder, or after the
   17th to see the estimate replaced) — leave the other undeclared so the estimate stands.
6. **Activate Bershka's draft lease before 1 Oct**, bill its deposit, and receive it.
7. **Decide Cairo Optics** after 1 Oct: convert to holdover or let the unit go and run the move-out
   (final account, deposit netting, refund).
8. **Close September** (Month-End Close) after the 1 Oct runs; then try to post something into it.
9. **Owner statement for September** for Jawad (`owner@atriom.test`) once the month is closed.

Each act is itself a check: the toast, the ledger panel, the tenant's portal and the trial balance
must all agree afterwards.

## The daily check

On the box, `docs/qa/scripts/soak-check.sh --post` runs every morning at 08:05 Cairo (cron, app
user). It writes `storage/logs/soak-YYYY-MM-DD.md` and posts the verdict to the Discord webhook the
box already uses for health changes. It reads: `atriom:health`, `atriom:config-health`,
`billing:reconcile --deep`, both data audits, what moved since the previous run
(`soak-deltas.php`, run inside the app), the ops log since the previous run, and ERROR lines in the
application log. Exit 0 = green; 1 = a person reads the file.

The person's half, each day: (1) read the report; (2) tick the calendar row(s) for that date — did
the expected document appear, with the expected figure, on the expected property; (3) open the
screens the day's events touch, in Arabic once; (4) record below. A defect found goes to
`DEEP-SWEEP-2026-09-01.md`'s worklist with an SW number, is fixed with a regression test, deployed,
and noted here with the hash.

**Read the health rows with the staging triage in mind**: `backup_capability`, `two_factor` and
`demo_accounts` are red on this box BY DESIGN (STAGING.md §5); the script ignores exactly those three.
Anything else red is real.

**No configuration gap going in.** Measured on the box after the 5 Sep deploy: every BLOCKING row of
`atriom:config-health` is green — the seller TRN is set, the posting map is complete, the period is
open. So the daily check expects NONE (`SOAK_EXPECTED_CONFIG_GAPS` is empty) and any blocking row
during the month is a regression. The one advisory red is `payroll_rates_configured`, which is the
operator act below: the statutory rates are still zero.

**Known environment gap going in:** the MailerSend token on the box lacks the SEND scope, so every
e-mail notification fails with `403 Forbidden` (a WARNING in the ops log, not a failed job — the
notification's database channel still lands in the bell). The soak proves the bell and the queue;
it cannot prove e-mail delivery until the token is fixed.

## Journal

| Date | Verdict | What ran / what was seen | Findings |
|---|---|---|---|
| 2026-09-05 (late) | reviewed + deployed | Both change batches went through an adversarial review before commit, and both reviews found real things. **On the soak seeder:** the Carrefour +7% step is ALREADY in September's invoice (a mid-month anniversary snaps to its billing month — `ChargeScheduleService::billingBoundary()`), so the calendar's 15 Sep row, the cheque-series reasoning and two hardcoded fallback amounts were all wrong; the seeder now stops loudly rather than inventing a figure, and refuses to continue if a billing run considered nothing (a held period lock answers all-zeros in silence). **On the demo seeder:** the ageing spread could stamp a two-month covered window, and `historyLines()` keyed charges by type alone, which would price every line at the final ladder rung once the ladder is projected. Both fixed. Commits `0faa35f9` (seeders), `39d20a6a` (SW-242), `3e1be764`, deployed to staging. | — |
| 2026-09-05 | pre-validated | Every scheduled event in the calendar was dry-run on the scratch copy with `--date`/`--period` before staging was seeded: 4 late fees on D+1 and 6 more on the 16th, the cleaning bill drafted on the 7th (28,500 incl. VAT), the waste levy expensed on the 15th (5,130), Guardian's retainer STILL billed on the 20th under a contract that ended on the 12th (68,400 — a question for the operator, see the calendar), 1 + 3 preventive work orders, both August declarations reminded then estimated, Carrefour stepped once on the 15th, September depreciation posted. Books tied out after all of it; every charge schedule unambiguous. | **First finding, from the dry-run:** a recurring cost LINKED to a vendor contract keeps raising draft bills after that contract has ended — `GenerateRecurringExpensesService::raiseVendorBill()` copies `vendor_contract_id` onto the bill and never asks whether the contract is still in force; the schedule reads only its own `ends_on`. Yardi's recurring payable is bounded by the contract term. Fixed the same day as **SW-242** (`39d20a6a` — `RecurringExpense::effectiveEndsOn()`, own end or the contract's, whichever is earlier; the review of the fix closed four more doors: re-linking an ended contract on edit, a terminated contract keeping its original term, a deleted contract lifting the bound, and an N+1 on the register; `ARecurringCostStopsWhenItsContractEndsTest`, 9 cases). On the box the 20 Sep row now reads: Guardian's retainer must NOT bill. |
| 2026-09-05 | seeded | NG seeded on the scratch database first, then on staging: 66 invoices, 53 receipts, 12 cheques, 5 bills, GL posted. `billing:reconcile --deep` 9/9 green. | Two seeder-side findings fixed before staging: receipts created outside a transaction posted before their allocation (53 void + reversal pairs); a back-dated billing run dates DUE from the run day, by design, so seeded history re-anchors due dates to issue date + terms. |
