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
| **UX5-06** | ~~**Dead-end KPIs** — MallStats (every money role's landing widget)~~ **MallStats SHIPPED 2026-09-05**: all six cards drill through — occupancy and economic occupancy to the unit register, MRR to the **rent roll** (which is that figure itemised), CSAT to tenant requests, collections to payments, AR to **ageing** ("how old is it" is the only question behind that number). Every link is conditional on the destination's own `canAccess()`, because this widget is shown to roles with very different reach and a card landing on a 403 reads as broken rather than as not-for-you. **The two TREND CHARTS shipped the same day** and the shape guessed here was wrong: a `ChartWidget` has NO header-action slot at all (only heading, description and filters), so the link goes in the DESCRIPTION — legal because `getDescription()` accepts an `Htmlable` and Blade's `{{ }}` calls `e()`, which returns an `Htmlable` unescaped. Destination follows what each chart PLOTS: revenue to the invoice register, energy to the meters (the readings behind a spike). Both carry `tableView=none`, or a saved default reopens the list somewhere other than where the click pointed. | **DONE 2026-09-05** | — |
| ~~**UX5-05**~~ | ~~**Technician on a phone**: PM jobs show **no date at all** and no equipment code~~ **SHIPPED 2026-09-05** — fixed WITHIN the six phone columns, not by adding a seventh. "By when" was `target_resolution_at`, the SLA clock only a CORRECTIVE order carries; a preventive one answers to its plan, so the column now falls back to `scheduled_for` and colours red on `pmComplianceState()` as well as `isOverdue()` (a PM has no SLA to breach). The equipment code joins the trade under the title — the machine in front of you outranks the craft. Sorting stays on `target_resolution_at`, because the breached-SLA card sorts on it and a per-row change of meaning is worse than PM jobs grouping together. | — | — |
| ~~**UX5-01**~~ | ~~**No CAM reconciliation workbench** — the year-end runs as four sequential row actions with no arithmetic shown before commitment~~ **LARGELY ALREADY SHIPPED, verified at HEAD 2026-09-05; the remainder closed today.** The row predates three changes that answered most of it: the acts moved off the row onto the record page (2026-08-30, `CamExpensePoolActions`), `billAllPending` became a BATCH whose confirmation states the counts AND the money (how many invoices, how many credit notes, what is recovered, credited and charged in fees), and the allocations tab already shows every participant's working in ten columns with a per-row breakdown modal, beside a pool placeholder giving the landlord's share split into its two levers (vacancy and caps). What was genuinely missing: only `cap_absorbed_amount` carried a summarizer, so an operator could read thirty-nine workings and not what the batch came to. The four money columns total now — Σ allocated being the recovery identity's left side. | — | — |
| ~~**UX5-04**~~ | ~~**⌘K reaches records only** — 33 report/utility pages are sidebar-scan-only while UX-28 advertises the palette~~ **SHIPPED 2026-09-05** — the palette now carries a *Screens & reports* category, LAST (someone typing here usually holds a document number). Not a second index: the entries are `AssistantCorpus`, which already scores every screen and report in both languages and carries the operator's own synonyms — ranking is most of that feature and a second copy is a second thing to keep good. Access is asked per entry per request through `AssistantEntry::isReachableByReader()`, extracted from the assistant on its second real call site. Every word must land, or one shared word answers with a spray of screens. | — | — |
| ~~**UX5-07**~~ | ~~The Arabic-chrome gate sweeps the admin panel only~~ **ALREADY CLOSED, verified 2026-09-05** — `ArabicPanelHasNoEnglishChromeConformanceTest` has swept `portal` and `vendor` since 2026-08-30, with the premise counted PER PANEL so a total cannot stay satisfied by the admin panel's own resources. | — | — |
| ~~**AR-GL-03**~~ | ~~The tenant statement itemizes two of the four settlement channels its own `total_paid` counts~~ **SHIPPED 2026-08-26** — applied on-account credit and a netted security deposit now render in one "Other settlements" section with a KIND column. One table rather than two: both answer the same question and carry the same four facts. | — | — |
| ~~**D3-04**~~ | ~~`TableView::makeDefault()` clears defaults by the **view owner's** id~~ **CLOSED 2026-09-05** — `is_default` was one column answering two questions, so a view its owner had shared AND marked was both their personal default and the team's, and every attempt to fix one meaning damaged the other. `table_view_defaults` is a row per (person, list): a pointer is where I start, a stored NULL is the explicit "no default for me" (the escape that had nowhere to live), and an ABSENT row means "follow the team" — three states, all now reachable from the picker, where two used to render identically as blank. `is_default` keeps only the team meaning, published by an owner-only toggle. Adopting a colleague's view writes the adopter's own row and nothing else. | — | — |
| ~~**D2-09**~~ | ~~No retention/prune for `notifications`, `exports` (+files), `failed_import_rows`, `failed_jobs`, expired Sanctum tokens~~ **SHIPPED 2026-08-26** — `HousekeepingSettings` + `atriom:prune-transient-data`, weekly, a period per class. Laravel's and Sanctum's own pruners are CALLED rather than reimplemented, from inside the command so the period is read at run time. The export FILE is the substance: Filament's `Export` uses the `Prunable` trait with no `prunable()` method and no `pruning()` hook, so even a working prune would orphan the file. | — | — |
| ~~**UX5-08**~~ | ~~Tenant 360 lacks the violations tab and sales trend~~ **SHIPPED 2026-09-05** — both tabs added. Compliance history answers "have they been a problem?", turnover spans every unit the retailer holds (the question a percentage-rent renewal turns on); each is read-only and links to the register that owns the rules, and each is gated on the reader's own rights — sales additionally on the tenant OWING a declaration, asked of the leases rather than of existing rows so it does not hide exactly when the chase matters. No header action on the sales tab: a declaration is keyed on a LEASE and the register has no tenant filter, so any link there would have looked like it narrowed and would not. | — | — |
| ~~**UX5-10**~~ | ~~No consolidated approvals inbox~~ **SHIPPED 2026-09-05** — as CARDS on `ActionRequired`, not a new screen. That widget is already the one panel answering "what needs doing" (seventeen cards, each gated on the register it links to) and carried nothing about approvals, so a purchase request at `requested` and a supplier bill at `draft` — both work stopped until a person decides — were visible only to whoever thought to open that register. A second place to look is precisely what the widget exists to prevent. Oldest first on both: a queue is worked from the front. **En route the test caught the card gated on `purchase_requests.view`, which is not a permission** (the module is `procurement`), so it would have been invisible to everyone including a super admin — the widget's docblock covers the opposite case (an unmapped key stays visible, "the omission is loud") and a mapping to a name nobody grants is silent. A fourth case now asserts every card's permission exists. | — | — |
| ~~**GAP1B-06**~~ | ~~No importer for **equipment** or **unit-ownership records**~~ **SHIPPED 2026-09-05.** Both were genuinely absent (verified). Equipment matters beyond the typing: no equipment means service plans have nothing to attach to, so the whole preventive side of module 26 stays empty. Unit ownerships matter more — `BillUnitOwnershipsService` is SCHEDULED, so every owner missing from the register is one nobody bills, month after month, reported as an unremarkable `skipped`. Both property-clamped through `ResolvesVisibleAssetByCode`, both keyed for re-import (equipment on property+code; ownership on unit+owner, which is what keeps CO-OWNERSHIP expressible — SW-220's shape). An owner is matched against an existing `tenants` row and an unknown one REFUSED, since a counterparty is one register whether the money is rent or صيانة; a resale is deliberately not expressible in a file, because closing the seller and opening the buyer is one act (`TransferUnitOwnershipService`). | — | — |

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

- ~~**OPS-10**~~ — **DONE 2026-09-11.** `notification_delivery` on `atriom:health`: `OpsLog::countEventSince()` reads `notification.delivery_failed` off the daily ops files for the last 24h — the durable record everything already writes to, a file a Redis flush cannot zero — and the row goes red naming the last miss (notification, recipient, error), so `atriom:notify-status` posts to Discord once when a token dies and once when the window has been clean for 24h — a day after the fix, not the moment of it. Refuses to call a file it cannot see clean (`ops_daily` out of the stack, or `OPS_LOG_LEVEL` above warning) and advises by exception class (a malformed address on the record is not the transport's fault). Deployed boxes only. `ADroppedNotificationReachesTheHealthRowTest`, four mutations. Also closed on the way: SW-252's `BestEffortMailChannel` had left `TenantFacingWordingIsTheOperatorsConformanceTest` red on `main` (a channel read as a mail notice) — fixed by derivation (a notice composes in `toMail()`, its own or `AlsoSendsByMail`'s), and the first cut of that clause silently dropped the thirteen trait users, which the gate's own premise count caught. Was: **OPEN.** A `Health` row that counts `notification.delivery_failed` over the last 24h.
  Since SW-252 an inline mail failure is one ops-log WARNING line and no longer a failed job, so on
  production a dead mail token would show ONLY there — the soak check now puts the count in its
  verdict, but `atriom:notify-status` (Discord) watches `Health` rows and would not fire. XS: the
  channel already writes the line; the row needs a durable counter (`failed_jobs`-shaped, not a Redis
  key a flush zeroes) or a tail of `ops-*.log` for the window.
- ~~**OPS-09**~~ — **DECIDED and gated 2026-09-10 — in the direction INFRASTRUCTURE.md §5 already argued, not the one this row proposed.** `noeviction` STAYS (every `Cache::lock()` lives in this store; `allkeys-lru` — and `volatile-lru`, since a lock key carries a TTL — can evict one mid-run), and the missing half is the CAP: `maxmemory 256mb` in `redis.conf`, so a runaway ends in a refused write rather than the OOM-killer. `atriom:health` now carries `redis_memory` (red with no cap, red on any eviction policy, red at 80%; four mutations proved), so the staging box reads red on it until the cap is set on the box — which is the point. The second-instance-for-the-queue idea was declined: it moves the queue and leaves the locks evictable. Was: give Redis a `maxmemory` and an eviction policy before production. Measured on the
  staging box 2026-09-10: healthy (1.66 MB used, peak 1.84, 0 evicted, 0 rejected, queue depth 0,
  last bgsave OK) but `maxmemory` is **0** — no cap — with `maxmemory-policy noeviction`. That pair
  means an unbounded cache does not shed old keys, it starts **refusing writes**; and since
  `QUEUE_CONNECTION=redis` points at the same server, the queue would go down with the cache rather
  than the cache degrading on its own. The usual answer is a cap with `allkeys-lru` for the cache and
  a separate database or instance for the queue left `noeviction` (a queue must never evict a job).
  Not urgent on staging at 1.66 MB — it is a production-topology decision, so it is recorded here
  rather than changed quietly on the box.

- ~~**OPS-03**~~ — **DONE 2026-09-05.** The §2 delta block now carries `IMPORT_QUEUE_CONNECTION`
  as well as `EXPORT_QUEUE_CONNECTION` — the row named only the export half and both default to
  `sync`, so a box built from the docs ran every transfer INLINE and the topology production uses
  was never rehearsed.
- ~~**D2-14**~~ — **DONE 2026-09-05.** `composer qa:baseline && composer test:mysql` is step 5 of
  the cutover, with what it covers and why it has never run: it skips silently on any other driver,
  so the first real-MySQL box is the first place it means anything.
- **D2-04** — run `gh workflow run ci.yml` once before the cutover commit. CI stays off by the
  owner's standing decision; this is the documented manual substitute, and the CVE audit has never
  run since the jobs were repaired.
- ~~**OPS-05**~~ — **CLOSED 2026-09-05**: `atriom:health` reports `0 queued, 0 failed`. Was: reseed this workstation (`migrate:fresh --seed`) once the activity-log session lands:
  716 stale queued jobs and 5 E2E rows from 2026-08-23 are what turn the local health queue row red.
- ~~**SW-243(a)**~~ — **CLOSED 2026-09-05.** Gated, and the gate found four more of the same
  defect. The derivation this row proposed (tokenise the chains, resolve the file's model, compare
  against the column) is what shipped, in `Tests\Support\FieldWidths`, split across two tiers
  because **sqlite cannot see half of it**: Laravel's sqlite grammar emits a bare `varchar` with no
  length, so a 32-character national ID validated into a `varchar(20)` is perfectly green in the
  ordinary suite. The *form-vs-importer divergence* needs no schema and runs on every push
  (`ADoorNeverRefusesWhatAnotherDoorAcceptedConformanceTest`); the *wider-than-the-column* half is
  `tests/Mysql/FieldWidthsOnMysqlTest`, beside the `ValueSets` width check that exists for exactly
  the same reason. Found and fixed: `ChargeImporter.type` `max:64` into a `varchar(32)`,
  `EmployeeImporter.national_id` 32 into 20, `EmployeeImporter.phone` 32 into 30 — each validating
  a row the INSERT then refuses, so the operator reads a raw *"Data too long for column"* in
  `failed_import_rows`, or on a non-strict connection gets a silently truncated national ID — plus
  `LedgerAccountForm.code` capped at 20 while its own importer deliberately allows 32, which is the
  SW-243 lockout **on the chart of accounts**, the one register a migrating operator is certain to
  import. `VendorImporter.email` was the door that was RIGHT (255 is this application's convention
  everywhere else, and RFC 5321 caps a path at 254), so the two supplier columns widened instead.
  The relation-manager attribution is the noise this row predicted and the gate refuses to make it:
  `ContactsRelationManager` resolves through the relationship to `VendorContact`, not to its parent
  resource's `Vendor`. Seven mutations, each killing its own tooth. **Still open: the phone-FORMAT
  half**, below.
- **SW-243(b)** — fix the phone-FORMAT half. The same importer-vs-form divergence exists on
  phone FORMAT rather than length: the importer accepts any string up to its cap, the form applies
  Filament's `tel()` regex, so a real number written `+20 (2) 2735-1234 ext 402` imports cleanly and
  is then refused on its own Edit page — the identical lockout, one rule along, and invisible to the
  width gate because both doors agree about the LENGTH. Fixing it is a decision about which phone
  formats an Egyptian operator's data actually contains, not a width, so it wants the operator's
  real file.
- ~~**SW-249**~~ — **FIXED 2026-09-10.** `resolutionEvidence[]` on every request (same shape as `attachments`, always present), and the stream endpoint serves both tenant-visible collections — it used to look in `attachments` alone, so the URL the resource would have handed out 404'd its own owner. MOBILE-API §4.7, the sync brief (task 19), module 20 and `openapi.json` in the same commit; four API cases, three teeth mutation-proved — including the stream endpoint's collection gate, which the first cut called unexercisable and the review measured as the only security clause in the change (an unregistered collection lands on the fail-open `public` disk and streamed 200 without it). The review also found four endpoints (cancel/confirm/dispute/rate) returning the request without `media`, so both file lists were ABSENT on exactly the responses an app replaces its model with — fixed at the controllers. Was: show the tenant proof of the fix on MOBILE too. SW-246 gave a maintenance request a
  `resolution_evidence` collection and surfaced it in the tenant PORTAL's Resolution section, which
  is where the requirement earns its keep — a rule the person who reported the fault cannot see is
  worth little. `Api\V1\TenantRequestResource` serialises `getMedia('attachments')` by NAME, so the
  API shape did NOT change and nothing is broken; the mobile app simply does not show the new
  collection. Adding it is ~10 lines plus the same-commit obligations CLAUDE.md sets for any
  `/api/v1` change — MOBILE-API.md, the sync brief, module 20 and a regenerated `openapi.json` — and
  it was deliberately not folded into SW-246 because the spec is GENERATED and the tree's composer
  state was mid-change (a concurrent session adding Horizon), so regenerating would have produced
  churn nobody could review. Small, self-contained, wants a quiet tree.
- ~~**SW-247**~~ — **FIXED 2026-09-10.** Best effort per order through `BestEffortNotification`, the order's id in the ops-log record, resolution left loud; five `PreventiveWorkOrderTest` cases had passed only because the old catch swallowed their unseeded roles. `ANightlyRoundBellsEveryOrderItRaisedTest`, mutation-proved. Was: `GeneratePreventiveWorkOrdersService::notifyRaised()` (~:183) has SW-244's shape and
  a wider blast radius: its single `try/catch` wraps the WHOLE `->each()`, so an inline-send failure
  on the first raised order aborts iteration and orders 2..N are never belled — nightly, on
  `facility:generate-preventive`. The work orders themselves are durable so no finding is erased, but
  `Log::warning` names only the first error and nothing records which orders went unannounced. Fix is
  the one SW-244 established: move `BestEffortNotification::send()` INSIDE the closure and delete the
  outer catch. Found by the review of SW-244, 2026-09-10.
- ~~**SW-248**~~ — **FIXED 2026-09-10, and fault (a) below is REFUTED.** All three notifications (the two commands' and `LateFeeService`'s, which had the same shape) are `ShouldQueue`, so nothing held the lock across SMTP — what held was a queued PUSH, which is transactional on the `database` driver only; on redis (the box) the job left before the stamp, so fault (b) stood (for the late fee the rolled-back job named a fee never written and FAILED in Horizon — not a mail, as the first fix note claimed). Stamp then `DB::afterCommit()` at all three, each delivered through `BestEffortNotification` so a miss is an ops-log line by record and never a FAILED fee, and the gate now derives delivery wrappers off `app/Models` so `notifyPortal()` is a hit. `AChaseLetterIsQueuedAfterItsStampCommitsTest`, three teeth + the gate both ways. Was: two nightly commands send mail INSIDE a database transaction, under `lockForUpdate`,
  BEFORE writing their idempotency stamp: `RemindOverdueTenantsCommand` (~:153) and
  `RemindExpiringLeasesCommand` (~:80). Two distinct faults. **(a)** The first holds an exclusive lock
  on an `invoices` row across a synchronous MailerSend round-trip per recipient — and its own sibling
  `ScanOverdueInvoicesCommand` (~:78) carries the comment explaining why that is wrong (*"`invoices`
  is the most contended table in the system"*). **(b)** Because the send precedes the stamp, a throw
  on the third recipient rolls back `tenant_overdue_notified_at` and `dunning_level` while the first
  two e-mails have already left — so the next run chases those tenants again. That is verbatim the
  duplicate-notification bug `ScanWorkOrderSlaBreachesCommand` (~:359) documents having fixed, so the
  correct shape is already written down in this codebase. Fix: stamp first, then
  `DB::afterCommit(fn () => BestEffortNotification::send(...))`. **NOTE the SW-244 precondition** —
  these DO write an idempotency stamp, so the seam must not simply be dropped in front of the
  existing order or a swallowed failure would leave the stamp standing and the tenant never chased.
  Found by the review of SW-244, 2026-09-10.
- ~~**SW-250**~~ — **FIXED 2026-09-10 (found by the review of the zone-supervisors fix).** A SCALAR
  payload bypassed `Rule::in` on every `->multiple()` Select in the panel: Filament attaches the
  rule to `{path}.*` and adds no `array` rule, so a bare id had no children to fail on, was wrapped
  by the state cast and synced. `App\Support\Filament\MultiValueFieldIsAnArray` puts an `array`
  rule on every multi-select and `CheckboxList` (one `Select::configureUsing`, which reaches
  `EntitySelect` and `CatalogueAwareSelect` through `class_parents()`). Measured exposure: an
  options bypass on 31 fields, no unguarded property-owned picker (units are re-checked by their
  services, the user form's grant by `enforceGrantableAssetsRule()`). Also: `CreateArea`/`EditArea`
  were not transactional while the tab's comment claimed the register "always is" — a smuggled
  supervisor left an orphaned zone on the register; both pages declare it now.
  `AMultiSelectRefusesAScalarPayloadTest`; the zone test drives all four doors.
- ~~**SW-252**~~ — **FIXED 2026-09-11 (found on the soak by reading the calendar against the box).**
  `sales:scan-missing-declarations` ran on the 10th at 08:00 with the transport answering 403; two
  tenants owed an August reminder and NOTHING was written — `via() = ['mail', 'database']`, Laravel
  sends channels sequentially with no catch between them, so the tenant's mail threw and the tenant's
  bell row (the mobile app's reminder AND the scan's idempotency stamp) was never written; the portal
  login behind it was never reached; the command `warn()`ed to `/dev/null` and exited SUCCESS. Next
  run 10 Oct scans September: August's chase was gone for good, and the 17th's estimate was about to
  land on tenants never chased. **Nineteen of thirty-five inline notifications share that order.** ONE
  seam: `App\Notifications\Channels\BestEffortMailChannel`, bound over `MailChannel` as `BellChannel`
  is over `DatabaseChannel` — a transport failure on an inline send is an ops-log line, not the thing
  that erases the record. Four failure families (the review found the first draft caught exactly the
  one the box had shown it — MailerSend does not re-wrap its 422); queued mail, mail-only
  notifications and a caller that `requiringDelivery()` still throw (`SendInvoiceToTenantService`
  insists — the first draft had it toast "sent" over a message that never left). The scan records its
  finding first (SW-244's rule) and the soak check puts a delivery-failure count in its VERDICT.
  `ANotificationsRecordDoesNotDependOnItsTransportTest` (14 cases, 9 mutations). Recovered on the
  box by re-running the scan for `2026-08` after the deploy. Account: [modules/19 § SW-252](../modules/19-notifications-scans.md#sw-252).
- ~~**SW-254**~~ — **FIXED 2026-09-11 — the flag is honoured, at ONE definition, and the doors were the bigger half.**
  `Lease::missingSalesDeclarationsFor()` is composed from `scopeOwingSalesDeclaration()` (plus the
  property filter and the fit-out rejection) and the dashboard card's third inline copy reads the
  helper, so the chase, the estimate, the month-end checklist, the card and the list filter name one
  set. Two consequences stated: an EXCUSED percentage-rent tenant is neither chased nor estimated
  (the option label says so); a DISCLOSURE-ONLY tenant is chased and never estimated — an estimate
  is a billing instrument, and an invented turnover would poison the analytics the disclosure is
  for (`reporting_only` on `sales.estimate_run`). **The review found that chasing a disclosure-only
  tenant sent them to three doors that refused them** — the portal picker, the API create (422
  "does not have percentage-rent terms") and the app's `canDeclareSales` — plus the two reports the
  disclosure is collected for never showed them, the lock notification told them "percentage rent
  owed: EGP 0.00", and an excused lease lost its declarations tab. `Lease::declaresSales()` (the
  duty OR the charge) is the one predicate all of those read now; both notifications word
  themselves by the lease's clause in both languages; the scan's period label is localised.
  `AnExcusedTenantIsNotChasedTest` (20 cases, eleven mutations). Account: [modules/09](../modules/09-tenant-sales-percentage-rent.md#declaring-turnover-and-paying-on-it-are-two-clauses-2026-08-30).
  Was: **OPEN (found by the review of SW-253, pre-existing).** `Lease::missingSalesDeclarationsFor()`
  ignores `requires_sales_reporting` while `scopeOwingSalesDeclaration()` honours it, so a
  percentage-rent lease the operator EXCUSED from filing is still chased on the 10th and still
  estimated on the 17th, and the lease list's "owing" filter shows a different set from the two
  commands.
- **SW-257** — **OPEN (found on the soak, 2026-09-12).** `leases:scan-option-windows` announces
  *closing* (within the lead of `latest_notice_date`) before it checks *opening*, and stamps only the
  event it sent — so an option already inside its closing lead when first seen (NG's renewal: earliest
  1 Sep, latest 25 Sep, seeded 5 Sep) got *"the deadline is near; decide"* on the 6th and *"notice may
  now be served; start the conversation"* on the 7th, in that order. XS: once `closing_notified_at`
  is set, an opening is moot — stamp `opening_notified_at` alongside it, or skip the opening branch
  when a closing has gone. One line in `eventFor()` + a tooth.
- ~~**SW-256**~~ — **FIXED 2026-09-12 (found on the soak by reading the overdue set at 06:00).** Three
  NG invoices due THAT DAY read `overdue` on the register, the portal and the mobile app while the
  ageing report filed them under *Current*. `due_date` is a DATE cast (midnight), so `< now()` and
  `isPast()` were true from 00:00 on the due day; `AgingBuckets`, the AR report, the AP badge and
  both chase sweeps already said `whereDate('<')` — Yardi's boundary. `Invoice::pastDue()` /
  `isPastDue()` compare on DAYS now, and four inline `due_date->isPast()` copies (`/me/balance`,
  `/me/summary`, both statement PDFs) read the predicate. **The review found the fee's door**: with
  zero grace the late fee fired ON the due day; grace days are days AFTER the due date and the fee
  lands on `due + grace + 1` — every fee one day later than before, stated (8th due, 7 days, fee on
  the 16th, which the soak calendar had predicted). `AnInvoiceDueTodayIsCurrentNotOverdueTest`
  (11 cases, 9 mutations). Account: [modules/05 § SW-256](../modules/05-billing-invoices.md).
- **SW-255** — **OPEN (found by `atriom:doors --check-diff` on SW-254, pre-existing since 2026-08-30).**
  `leases.requires_sales_reporting` has no door but the lease form: `LeaseImporter` and
  `LeaseExporter` carry neither it nor `has_percentage_rent`, so a migrating operator's "must
  report" column cannot be imported and a re-import of an export loses the ruling; the mobile
  `Api/V1/LeaseResource` publishes `hasPercentageRent` and not the duty, which is why the app is told
  to gate its sales screen on `canDeclareSales` (sync brief task 20). S: a column on the importer and
  exporter (nullable, `1`/`0`/blank), and the API key only if the app wants to SHOW the clause
  (MOBILE-API.md + spec in the same commit). Left out of SW-254 deliberately — that change was about
  the READERS of the flag.
- ~~**SW-253**~~ — **FIXED 2026-09-11 — the first option, plus a lookback.** The estimate requires `Lease::salesDeclarationRemindedAt()` (the same bell row the chase writes and reads for its idempotency — one definition now) to be ≥7 WHOLE days old; a lease with no reminder is skipped and reported to the ops log, never chased from the estimate; and the default run looks back three declarable months so a chase re-run late (August's, on 11 Sep) still ends in an estimate (17 Oct) rather than in a period nothing ever bills. Stricter than Voyager, stated in the benchmark. Every existing estimate fixture had never been chased — they run the real scan first now. `AnEstimateFollowsARecordedReminderTest`, five mutations. Was: **OPEN (found by the review of SW-252).** `sales:estimate-missing` (the 17th) estimates
  any lease `missingSalesDeclarationsFor()` returns; it never checks that the tenant was CHASED. The
  "week after the chase" is a schedule day, not a stamp — so a chase lost to any cause (a scan that
  did not run, a period the reminder was never sent for) still ends in an estimate on a tenant who was
  never asked. Market shape: the estimate follows a recorded reminder. S: the estimate skips (and
  reports) a lease with no `sales_declaration_reminder` row for the period, or the 17th's run chases
  first and estimates on the NEXT pass. Decide which; both are one query.
- ~~**SW-251**~~ — **FIXED 2026-09-10 (found by the review of SW-248).** `Payment::booted()`'s
  `saved` hook sent `PaymentReceivedNotification` (not `ShouldQueue`, `mail`+`database`+`push`)
  inline — inside `CapturePaymentService::capture()` and the Paymob callback's transaction, under
  the INVOICE locks. Deferred with `DB::afterCommit()` in the hook, as the Create/Edit pages already
  did for their own call. A model event is a door the transaction-closure gate cannot see; stated.
  `AReceiptIsMailedAfterTheInvoiceLockIsReleasedTest`. **Inherited and recorded, not fixed:** the
  nine SW-213 sites report a deferred push failure with `$this->warn()`, which is `/dev/null` under
  cron on the box — the three SW-248 sites go to the ops log through `BestEffortNotification`; the
  nine should follow (XS each).
- ~~**SW-245**~~ — **FIXED 2026-09-10 — decided as a PROJECTION.** `billing:scan-overdue-invoices` re-runs `recomputeTotals()` on every row whose stored status disagrees with `Invoice::pastDue()`, both directions, under a lock; `invoice.past_due` is in `ProjectedState::PROJECTIONS`. The SW-238/240 rule governs act-statuses, which the projector never touches; the form already called `overdue` derived. `AnInvoiceReadsOverdueOnTheDayItBecomesLateTest`. Was: `invoices.status` goes stale and only DISPLAY depends on it. `overdue` is a function of
  today and nothing sweeps it: `Invoice::recomputeTotals()` is the only writer and it runs when a
  SETTLEMENT touches the invoice, so an issued invoice nobody touches after its due date reads
  `issued` indefinitely. Measured on the staging soak 2026-09-10 — six NG invoices two days past due
  with money on all six, all still `issued`; the four that DID say `overdue` said it only because the
  late-fee run had touched them. **No money is affected**: SW-135 routed every collections surface
  through `stillOwed()` + a date predicate precisely because the column was already known to lag. What
  reads the column is the register's status filter and tabs and the tenant's own view, which
  understate. The registry's stated reason (that `billing:scan-overdue-invoices` re-stamps it — that
  command writes `owner_overdue_notified_at` and nothing else) is corrected; what is left is the
  DECISION: a nightly sweep would move a money document's status without an act, which CLAUDE.md has
  a standing rule about (SW-238/240), and the cheap alternative — having the overdue scan call
  `recomputeTotals()` on the rows it already locks — reaches each invoice only once, because it
  filters on `whereNull('owner_overdue_notified_at')`.
- ~~**SW-246**~~ — **FIXED 2026-09-10.** Was: the tenant-request evidence gate is wrong in BOTH directions, and blocks two request
  types outright. `TenantRequestService::transition()` refuses `resolved` unless the request has a
  linked work order or `hasMedia('attachments')` — applied to all EIGHT `TenantRequestType` cases,
  though the gate immediately below it (`requiresDecision()`) is type-aware, so the vocabulary to fix
  it already exists. Found by doing the operator's job on the soak, 2026-09-10: a noise COMPLAINT and
  a parking-permit ACCESS request could not be resolved at all — *"Attach a photo of the completed
  work, or raise a work order for it"* — and there is no photograph of having spoken to the neighbours.
  **The other direction is worse**: `attachments` is the collection the TENANT uploads to on the
  portal's own submission form, i.e. a photo of the PROBLEM. So the gate is satisfied by the tenant's
  intake photo and lets a maintenance request be resolved with no evidence of the FIX — vacuous on
  exactly the type it was written for (FR-USR-06). **Both halves fixed**:
  `TenantRequestType::requiresCompletionEvidence()` (true for Maintenance only, the sibling of
  `requiresDecision()`) and a new `resolution_evidence` collection that means proof of the WORK, with
  an *Attach evidence* action shaped like the work order's and the evidence shown to the tenant in
  the portal's Resolution section. Nothing closes on nothing: every resolve still requires
  `resolution_notes` and three types still owe an approve/reject. See docs/modules/11.
- **D2-13 / H3** — measure the leading-wildcard `LIKE` search on a posture-B staging box before
  optimising anything. H3's own instruction, and staging is the first place it can be measured.
- ~~**OPS-06**~~ — **DONE 2026-09-06**, on the first genuinely quiet tree. It had grown from 30
  files to **69** while it waited, which is the argument for doing it the moment the window opens
  rather than when it is convenient. `vendor/bin/pint --test` now passes.
  **It broke one thing and a gate caught it**: `fully_qualified_strict_types` promoted
  `@use HasFactory<\Database\Factories\AssistantQuestionFactory>` — a docblock naming a class that
  has NEVER existed — into a real `use` statement, and `UnresolvedClassReferenceConformanceTest`
  refused it. The annotation was only ever safe while it stayed a string. That is the risk in any
  formatting sweep over this tree and the reason the source-reading gates are the ones to run after
  one: fifteen of them were, plus a behaviour sample over the rewritten files. *(Original note:)*
  `vendor/bin/pint --test` failed on **30 files** and has for a long time: files nobody
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

~~All stale **in the direction of understating the build** — half a day closes the set.~~
**CLOSED 2026-09-05, and the list itself was the best evidence for its own rule.** Ten rows were
carried here; **six had already been fixed** by the sessions that owned those documents — the CPI
and revenue-forecast rows were corrected on 2026-08-31, C3.1/C3.2 read as built and reasoned-decline
in STATUS today, benchmarks/yardi/03 carries the words *"this paragraph said 'there is no statement'
until it was re-checked"*, STATUS §0's own paragraph already records the `FixtureColumnsExist`
correction, and A3.4 was accurate as written. **A drift list goes stale exactly as fast as the
documents it is about**, which is why every row here was re-checked against the CODE before anything
was edited rather than fixed from the list.

Four were genuinely still wrong, and one of them was wrong in the expensive direction:

| Where | Was | Now |
|---|---|---|
| gap-analysis H1 | `FixtureColumnsExistConformanceTest` "ships `skip()`ed" | Struck through — the 72 ghost keys were cleared and the gate switched on the same afternoon (`7335552f`), hours after that row was last re-verified |
| gap-analysis §7 | Two rows in ONE table disagreeing about the revenue forecast — *"the open half is the forward projection"* beside *"🟡 → ✅ built 2026-08-19"* | The stale row removed. A document contradicting itself in adjacent rows is worse than either row alone |
| STATUS §7 | *"on a phone the work-order list shows cost variance but hides `equipment.code` and `scheduled_for`"* | Closed by UX5-05, and **the question was wrong in both directions**: the cost columns were already toggled off at every width, and the fix went WITHIN the six phone columns rather than adding a seventh |
| **STATUS B2.1/B2.2** | **XS** — "the fee % and basis are configurable; only the account is missing" | **M**, matching gap-analysis B1, which was right. Measured: `management_fee_pct` and `fee_basis` are captured, on the form, audited and in `ValueSets` — and **nothing reads them**. No service computes the fee, nothing raises it, nothing posts it. The account is not the last thing missing; it is what unblocks writing the rest |

Also corrected: `ci.yml`'s header named **five** conformance gates when there are ~96. The list is
now deliberately not restated there — the job runs `vendor/bin/pest --parallel`, so every gate runs
whether or not somebody remembered the comment, and a named subset in a header reads as the set that
is covered.

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
