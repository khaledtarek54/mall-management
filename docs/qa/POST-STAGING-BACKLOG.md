# Atriom — post-staging backlog

> **What the final pre-staging verification found and deliberately did NOT fix.** Everything here was
> confirmed by an adversarial second pass (see
> [STAGING-FINAL-VERIFICATION.md](STAGING-FINAL-VERIFICATION.md) for the evidence per finding) and
> then judged **not MVP-blocking**: it does not produce wrong money, does not lose a cost silently,
> and is not armed by a decision the client is about to make.
>
> **The MVP-blocking half is DONE** — nine money fixes shipped 2026-08-24, and the **collections
> cluster** (dunning ladder · invoice send/resend · inline record-payment) shipped 2026-08-25 after a
> re-read of this list judged the original call wrong: they were filed as usability, and getting paid
> is the operator's actual day. Both tranches are in §0 so nobody re-opens them from the report.
>
> **This is a backlog, not a second live list.** When a row here is picked up it moves to
> [ROADMAP.md](../ROADMAP.md) or [STATUS.md](../STATUS.md) and is struck here; when it is declined it
> gets a one-line decline row in [gap-analysis §6](../gap-analysis/README.md) and disappears. Two
> lists of one launch is how a stale one survives.

**Sections:** [0 · Done, do not re-open](#0--done-before-staging--do-not-re-open) ·
[1 · First weeks of production](#1--first-weeks-of-production) ·
[2 · Needs a decision, not code](#2--needs-a-decision-before-it-needs-code) ·
[3 · Ops hygiene](#3--ops-hygiene-xs-each) ·
[4 · Documentation](#4--documentation-drift) ·
[5 · Deliberately declined](#5--recommended-declines-one-line-each)

---

## 0 · Done before staging — do not re-open

Shipped 2026-08-24 with regression tests (`ClearedChequeSettlesWhatIsOpenTest`,
`CutoverAndChargeTermsSurviveTest`) and the touched suites re-run green.

| ID | What was wrong | Fix |
|---|---|---|
| **AR-GL-02** | A cleared SERIES cheque (no invoice named — the Egyptian norm) captured a receipt with zero allocations, which belongs to no property, so the credit could never be drawn: the month's invoice stayed open and the tenant could be **late-fee'd while the mall held their cleared cash** | `clear()` settles the tenant's open invoices in the cheque's property oldest-first under a locking read; `Tenant::creditBalance()` falls back to the cheque's own property for a wholly unallocated receipt, so a genuine advance stays drawable |
| **GAP1B-01** | The deposit sub-ledger had no cutover path — legacy deposits read **zero**, or were keyed as receipts that invented cash and doubled the liability | `deposit_transactions.is_opening_balance` (receipt-only, refused otherwise), journalizer skip, form toggle, `ChangeImpact` entry — mirroring `invoices.is_opening_balance` |
| **AR-GL-01** | `CreditNoteJournalizer` hard-coded `vat_payable`, so the day a charge code points at stamp/schedule tax (**C-TAX — a row, no deploy**) every credit would reverse the wrong liability and break the VAT return's tie-out permanently | Tax reversed per line's own posting role, header remains the authority on size; `describeAs()` now takes the tax code and both system-raised callers pass it |
| **D3-01** | A relief window dropped `billing_timing` **and** `prorate` — an arrears service charge silently flipped to advance for the relief segments, double-billing the crossover month | Both carried on the relief and resumed rows; the false comment claiming relief "comes through" `setAmount()` corrected |
| **D3-02** | Renewal, resale and holdover dropped `prorate`; the resale also dropped `end_date`, so a bounded seller charge re-opened **unbounded** on the buyer | Carried at every copy site |
| **D3-03** | The ownership billing run ignored `prorate` while its own credit service honoured it — bill and credit priced one line by two rules | `prorationMethodWithin()` threaded into `billOne()` |
| **M4B-01** | Form 41's `remitted` was the exact negation of the ledger tie-out, so the standard flow (Q1 paid in April) made Q2 read *"does not tie, remitted 0.00"* — a permanent false alarm on the number the operator files from | Both sides read **gross** with reversal pairs excluded |
| **M4B-02** | Year-end close swept 1 Jan–31 Dec while the fiscal start month is configurable and **C-FY is open** — a July answer would have rolled halves of two fiscal years. The idempotency lookup keyed on `whereYear`, which would have **double-rolled** retained earnings once fixed | Close and lookup both derive the fiscal year's own span |
| **M4B-03** | One schedule whose catch-up date landed in a closed period threw **out of the loop**, so every later schedule booked nothing, nightly, silently | Per-schedule isolation, failures counted, logged, named in the command output, non-zero exit |

Also shipped, small: `MoneyAccount` rail tier re-checks postable+active (M4B-04) · deposit
application takes the invoice's own `asset_id` (AR-GL-04) · `Payment.tenant_id` promoted to REFUSED
with a real model guard behind it (M4B-05) · vendor-bill badge excludes drafts (D3-05) ·
`Health` refuses `QUEUE_CONNECTION=sync` on a deployed box (OPS-02) · the `cashFlow()` docblock and
CLAUDE.md's *"there is no morph map"* corrected (M4B-07, D2-06).

**How it was verified**, so nobody has to take the list on trust:

| Check | Result |
|---|---|
| New regression tests | `ClearedChequeSettlesWhatIsOpenTest` (10) + `CutoverAndChargeTermsSurviveTest` (7) — **17/17** |
| Existing suites for every touched path | PDC · PDC series · auto-apply credit · tax-posting · recurring costs · withholding return · year-end close · prorate · renewal · credit notes · CAM true-up · deposits (×5) · unit ownership (×4) · payment guards — **all green** |
| Conformance gates | ChangeImpact · GlRegistry · PostingDateGuard · FieldHelp · ArabicPanelChrome · TranslationKey · ResourceFormSmoke · UnresolvedClassReference — **8/8** |
| Migration | applied to the local database (`2026_08_24_880000`) |
| Books, after the change | `billing:reconcile --deep` **9/9 on real data** — 293 invoices, 11,183,784.59 invoiced, 1,481,825.54 outstanding |

### The collections cluster — shipped 2026-08-25

Three findings, one workflow: **getting paid**. They were backlogged as "usability, not money" and
that line was wrong for a system whose operator's day *is* chasing rent — so they were built before
staging rather than after.

| ID | What was wrong | Fix |
|---|---|---|
| **1A-16 / UX5-02** | Each overdue invoice chased its tenant **exactly once, ever** — a tenant three months behind had been written to as often as one three days behind, and nothing recorded how many times anyone had been asked | `invoices.dunning_level` beside the existing date stamp, and a cadence: `dunning_followup_days` (**0 = chase once — the shipped default, so no tenant gets a message on deploy day they would not have got yesterday**) with `dunning_max_notices` as the ceiling. The notice AT the ceiling is a **final demand** — `dunning.final_notice`, which carries no floor and falls back to the ordinary reminder, so the escalation happens in the operator's own words or not at all |
| **UX5-09** | An invoice raised by any path except the monthly run **notified nobody**, and there was no send or re-send anywhere on the record | `SendInvoiceToTenantService` — one seam both the billing run and the operator's button go through — plus a Send / Send again action that labels itself by whether it has gone before, refuses a draft, and stamps `invoices.tenant_notified_at` so *"I never received it"* has an answer |
| **UX5-03** | The worklist told you who to call and then left you to find the payment form yourself: six screens, re-searching the tenant you were already looking at | A **Record payment** action on the collections worklist and on the tenant hub's Payments tab, linking to the real payment form with the tenant carried across. A link, not a second slimmer form — the real one owns the posting-date guard, the property scope, the over-allocation backstop and the orphaned-receipt refusal |

Verified: `ChasingATenantIsALadderNotOneEmailTest` (9 cases, including *"still chases exactly once
when no cadence is configured"* — the assertion that makes this safe to deploy), plus the settings,
change-impact, authz, translation, wording, page-smoke and draft-visibility gates. The wording gate
was **extended rather than exempted**: a block may now declare that it falls back to another block
(`DocumentText::FALLS_BACK_TO`), and the gate follows the chain and requires a real floor at the end
of it. Dry-run against the demo portfolio: 11 overdue invoices, each correctly at notice #1.

**One deploy note:** the deposit flag is a schema change, so staging needs `php artisan migrate` —
and `./deploy.sh` already runs it, which covers the dunning cadence too.

> *Corrected 2026-08-28.* This line used to add "and the dunning cadence needs
> `php artisan settings:migrate` as well". **There is no such command** — `spatie/laravel-settings`
> v3 ships `make:setting`, `make:settings-migration`, `settings:discover` and the two cache
> commands, and nothing else. It was also unnecessary: settings migrations live in
> `database/settings/` and are applied by the ordinary `migrate`, which is verifiable rather than
> assumed — `2026_08_25_320000_an_overdue_invoice_is_chased_more_than_once` is recorded in the
> `migrations` table like any other. A runbook step that cannot run is worse than a missing one:
> whoever follows it on staging sees a failure, and the natural conclusion is that the cadence did
> not land when in fact it already had. Nothing else requires a data backfill — every fix is either behaviour on a path that had none,
or a stricter read of columns that already existed. `invoices.dunning_level` IS backfilled to 1 for
anything already chased, so switching the cadence on cannot send a "first reminder" to a tenant who
has already had one.

---

## 1 · First weeks of production

Real operator pain, none of it wrong money.

| ID | Gap | Why it can wait | Size |
|---|---|---|---|
| **UX5-06** | ~~**Dead-end KPIs** — MallStats (every money role's landing widget)~~ **MallStats SHIPPED 2026-09-05**: all six cards drill through — occupancy and economic occupancy to the unit register, MRR to the **rent roll** (which is that figure itemised), CSAT to tenant requests, collections to payments, AR to **ageing** ("how old is it" is the only question behind that number). Every link is conditional on the destination's own `canAccess()`, because this widget is shown to roles with very different reach and a card landing on a 403 reads as broken rather than as not-for-you. **Still open: the two TREND CHARTS** (MonthlyRevenueTrend, EnergyConsumptionTrend) — a chart has no per-point link, so that is a header action, a different shape. | The numbers are right; the click is missing | XS remaining |
| **UX5-05** | **Technician on a phone**: PM jobs show **no date at all** and no equipment code, with no operator override of `visibleFrom('md')` | O3 (a technician app) is declined, so this is the tool — but a technician can still open the record | S |
| **UX5-01** | **No CAM reconciliation workbench** — the year-end runs as four sequential row actions with no arithmetic shown before commitment | The engine is at/above Yardi and allocations are inspectable after generation. Only unshipped 🟠 UI story | M |
| ~~**UX5-04**~~ | ~~**⌘K reaches records only** — 33 report/utility pages are sidebar-scan-only while UX-28 advertises the palette~~ **SHIPPED 2026-09-05** — the palette now carries a *Screens & reports* category, LAST (someone typing here usually holds a document number). Not a second index: the entries are `AssistantCorpus`, which already scores every screen and report in both languages and carries the operator's own synonyms — ranking is most of that feature and a second copy is a second thing to keep good. Access is asked per entry per request through `AssistantEntry::isReachableByReader()`, extracted from the assistant on its second real call site. Every word must land, or one shared word answers with a spray of screens. | — | — |
| ~~**UX5-07**~~ | ~~The Arabic-chrome gate sweeps the admin panel only~~ **ALREADY CLOSED, verified 2026-09-05** — `ArabicPanelHasNoEnglishChromeConformanceTest` has swept `portal` and `vendor` since 2026-08-30, with the premise counted PER PANEL so a total cannot stay satisfied by the admin panel's own resources. | — | — |
| ~~**AR-GL-03**~~ | ~~The tenant statement itemizes two of the four settlement channels its own `total_paid` counts~~ **SHIPPED 2026-08-26** — applied on-account credit and a netted security deposit now render in one "Other settlements" section with a KIND column. One table rather than two: both answer the same question and carry the same four facts. | — | — |
| **D3-04** | ~~`TableView::makeDefault()` clears defaults by the **view owner's** id, so a colleague adopting a shared view wipes the owner's personal default~~ **FIXED 2026-09-05** — the clearing is scoped to the ACTOR, plus the shared tier when the marked view is itself shared (two team defaults resolve by row id, which nobody decided). Mutation-proved per tooth. **STILL OPEN, and it is the smaller half:** a non-owner's "clear" cannot escape a team default — the flag is a column on the shared row, so there is nowhere to record "not for me". Needs the per-user pivot `ReportPreference` already models. | A preference, not money | S remaining |
| ~~**D2-09**~~ | ~~No retention/prune for `notifications`, `exports` (+files), `failed_import_rows`, `failed_jobs`, expired Sanctum tokens~~ **SHIPPED 2026-08-26** — `HousekeepingSettings` + `atriom:prune-transient-data`, weekly, a period per class. Laravel's and Sanctum's own pruners are CALLED rather than reimplemented, from inside the command so the period is read at run time. The export FILE is the substance: Filament's `Export` uses the `Prunable` trait with no `prunable()` method and no `pruning()` hook, so even a working prune would orphan the file. | — | — |
| ~~**UX5-08**~~ | ~~Tenant 360 lacks the violations tab and sales trend~~ **SHIPPED 2026-09-05** — both tabs added. Compliance history answers "have they been a problem?", turnover spans every unit the retailer holds (the question a percentage-rent renewal turns on); each is read-only and links to the register that owns the rules, and each is gated on the reader's own rights — sales additionally on the tenant OWING a declaration, asked of the leases rather than of existing rows so it does not hide exactly when the chase matters. No header action on the sales tab: a declaration is keyed on a LEASE and the register has no tenant filter, so any link there would have looked like it narrowed and would not. | — | — |
| **UX5-10** | No consolidated approvals inbox | Per-module badges + tabs exist; the ladder is single-level anyway | S |
| **GAP1B-06** | No importer for **equipment** or **unit-ownership records** *(the PDC leg was refuted — `lodgeSeries` IS bulk entry)* | Both are hand-keyable at this portfolio size | S each |

---

## 2 · Needs a decision before it needs code

Not ours to schedule. Each is already on [STATUS.md](../STATUS.md); repeated here only where this
round changed the reading.

| ID | Question | This round's finding |
|---|---|---|
| **A2.1** | **Do tenants withhold tax from rent?** | Egyptian corporate tenants are generally required to. If yes, the first short payment starts an AR residue that dunning will chase for ever — **its §5 "do you need this?" placement understates how early it bites**. Recommend moving it to §3 |
| **A2.7** | **One TRN or one per owner?** | The single-issuer assumption is real and enforced. If the two malls bill under different owner registrations, **every second-mall invoice is VAT-invalid** until the M-sized per-asset override ships. Already correctly in STATUS §2 |
| **GAP1B-02** | **Consolidated financial statements** | The code supports consolidation and no surface reaches it. **The STATUS half is done** — A3.8 was corrected on 2026-08-24 and now says plainly that consolidated is not reachable, so the documents no longer disagree. What is left is the DECISION: reopen the All-Properties question (M), or accept the per-property split |
| **1A-15** | **Leasing pipeline / deal CRM** | On no record at all, though the project's own Yardi benchmark states the verdict (*"for an operator leasing a second mall from scratch, the pipeline IS the job"*). Open it or decline it |
| **B1** | **Management fee** | Confirmed still recorded-and-billed-by-nothing, honest interim test in place. Blocked on the accountant naming the account. Note the two docs size it differently (gap: M, STATUS: XS) |
| **1A-17** | **Lease assignment / sublease (تنازل)** as an executable event | Recordable as a clause only; the terminate+new-lease workaround breaks AR continuity. Low frequency here |

---

## 3 · Ops hygiene (XS each)

- **OPS-03** — add `EXPORT_QUEUE_CONNECTION` to STAGING.md's §2 delta block (a staging box built from
  the docs keeps `sync`, so exports run inline and the prod topology is never rehearsed).
- **D2-14** — add `composer test:mysql` to STAGING-CUTOVER.md step 5. The tier exists *for* the first
  real-MySQL box and skips silently everywhere else, so it has never run outside a developer laptop.
- **D2-04** — run `gh workflow run ci.yml` once before the cutover commit. CI stays off by the
  owner's standing decision; this is the documented manual substitute, and the CVE audit has never
  run since the jobs were repaired.
- ~~**OPS-05**~~ — **CLOSED 2026-09-05**: `atriom:health` reports `0 queued, 0 failed`. Was: reseed this workstation (`migrate:fresh --seed`) once the activity-log session lands:
  716 stale queued jobs and 5 E2E rows from 2026-08-23 are what turn the local health queue row red.
- **D2-13 / H3** — measure the leading-wildcard `LIKE` search on a posture-B staging box before
  optimising anything. H3's own instruction, and staging is the first place it can be measured.
- **OPS-06** — `vendor/bin/pint --test` fails on **30 files** and has for a long time: files nobody
  touched this cycle are dirty at HEAD (`app/Support/MorphMap.php`, `Navigation.php`,
  `ReportCatalogue.php`), so this is drift rather than anything a sweep introduced. `composer lint`
  exists and CI is paused, so nothing has enforced it. Almost all of it is `ordered_imports` /
  `unary_operator_spaces`. One `composer fix` run closes it — do it on a QUIET tree, because it
  rewrites files across the whole app and would collide with anything in flight.
- ~~**OPS-08**~~ — **GREEN as of 2026-09-05** (11/11, re-run at HEAD). Was: `ADocumentIsWrittenInItsReadersLanguageTest` is **RED on `main`** (3 of 11 cases: an
  English-locale invoice renders «فاتورة»). It is red at HEAD independently of any deposit or
  payment-link work, and the PDF views were last touched by `98fa45cc` — whoever owns that commit
  should re-run the file. Recorded here rather than fixed because several sessions are working the
  tree and this is not this sweep's area.
- ~~**OPS-07**~~ — **CLOSED 2026-09-05**: baseline rebuilt, `composer qa` is 1087/0. Was: the MySQL QA baseline is **stale** and two `tests/Mysql` cases fail on it for reasons
  unrelated to any code: `leases.requires_sales_reporting` and the `facility_work_order_comments`
  table are both missing from `docs/qa/scripts/baseline.sql`. Rebuild with `composer qa:baseline`.
  Until then the tier reports two red rows that read as product defects and are not.

---

## 4 · Documentation drift

All stale **in the direction of understating the build** — half a day closes the set. Full table in
[STAGING-FINAL-VERIFICATION.md §8](STAGING-FINAL-VERIFICATION.md#8--documentation-drift-found-by-this-round).

Highest value first: STATUS §0 + gap-analysis H1 (the fixture gate is **on**) · STATUS §5 C3.1 (bins
shipped) and C3.2 (transfers work; cross-property is a reasoned decline) · gap-analysis §3.2 vs §7
(CPI escalation) and §3.6 vs §7 (revenue forecast) · STATUS §7's stale technician-phone claim ·
benchmarks/yardi/03's stale "no CAM statement" · the B1 sizing mismatch · move UX-08/UX-10/UX-12 into
ROADMAP or close them there. *(CLAUDE.md's morph-map sentence and the `cashFlow()` docblock were
corrected in the same commit as §0 — they were active traps for the next maintainer, not just drift.)*

---

## 5 · Recommended declines (one line each)

Write the row so the omission reads as a decision rather than an oversight — the whole point of
gap-analysis §6.

| ID | Item | Suggested reason |
|---|---|---|
| **1A-18** | TI allowances / leasing commissions | Egyptian practice grants rent-free fit-out, which is built (`fit_out_scope`); no cash TI to capitalise |
| **1A-19** | Specialty-leasing license agreements | A kiosk is modelable as a short lease and `RentableItem::TYPE_KIOSK` covers the cart register; only the lighter agreement and the income split are missing |
| **1A-20** | Footfall / traffic counting | Same no-sensor-estate reasoning that declined IoT and predictive maintenance |
| **GAP1B-05 / D2-10** | Admin API, outbound webhooks, BI extract | One operator whose consumer is an accountant; CSV/XLSX + scheduled email is the handoff, and an API with no consumer is attack surface |
| **1A-21** | Tenant sales audit workflow | The money half exists (void → re-declare → re-lock re-attributes correctly); record it as KEEP/EXTEND rather than building a workflow |
| **GAP1B-07** | Per-property billing dispatch | One job at 2 malls is correct; the ceiling is a known shape at ~10, and a timeout already surfaces on the health screen |
