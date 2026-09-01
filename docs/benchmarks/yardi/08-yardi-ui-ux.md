# 08 — UI/UX: what to copy from Yardi, and what not to

> The ask: *"same Yardi UI/UX as possible so the user uses the system easily with all features."*
> This file answers it — but it starts by disagreeing with half of it, for a reason backed by
> Yardi's own users.

> ### Statuses re-verified against the code 2026-09-01
>
> **Four things in this file were describing work that had shipped.** UX-08 still read 🟠 open while
> the CAM reconciliation workbench had been on the pool's own record page for weeks; UX-13 still read
> 🔴 while every form in its own candidate table was either converted or deliberately left alone by
> the rule the story states; UX-03's *"still open"* sentence named four things of which three had
> shipped, and one of those three is a **design decision** somebody could undo by mistake while
> reading this file as a worklist; and the *correspondence from the record* cell in
> [§2](#2-what-voyager-gets-right-and-what-it-costs-atriom-to-be-without-it) was a snapshot taken
> before the send actions existed. Each now carries a note naming the class that closed it. **The
> original text stays** — it is the case that was made at the time, and the reasoning in it is what a
> future change has to respect.
>
> UX-05's own warning applies to this file more than to any other in the tree: *"this row still read
> 🔴 after the page had shipped. Two rows in this file did … check the code before believing a 🔴
> here."* That was written on 2026-08-10, and it has now happened twice more.
>
> **The rows still marked open were re-checked and are genuinely open** — UX-10 (the global search
> provider iterates `Filament::getResources()` and nothing else, so no page or action is reachable by
> typing its name), UX-12 (`MallStats` renders three money figures with no `->url()`), and the stated
> remainders on UX-02 (nothing draws a legacy gap or overlap on the schedule panel) and UX-07 (the
> tenant hub still carries neither sales nor violations). **[§4](#4-the-ui-work-list--stories-ux-01ux-13)
> is the authoritative status**; the phasing table in [§6](#6-where-this-sits-in-the-plan) is the plan
> as it was drawn and has not been maintained since.

---

## 1. The honest read: copy the architecture, not the look

Yardi Voyager's *capability* is the benchmark. Its **interface is not**, and copying it would make
Atriom worse:

- **77% of Voyager reviews that mention usability call the UI unintuitive**, with a steep learning
  curve *(cited: SelectHub review analysis)*.
- Users describe it as *"outdated and confusing"*, navigation as *"cumbersome"*, and simple tasks as
  taking *"significantly more clicks than necessary"*. One verified review calls it *"SUPER clunky,
  and early 90s interface"* *(cited: Capterra / G2 / SelectHub)*.
- Competitors win reviews **specifically on this axis** — Re-Leased's pitch against Voyager is a
  modern, intuitive interface with a lower learning curve, and users agree in head-to-head
  comparisons *(cited)*.
- **Yardi itself agrees.** Voyager 8 (2023→) is a deliberate move *away* from the old screens:
  refreshed UI with "cleaner typography, more whitespace, intuitive iconography", customizable
  dashboards, contextual navigation, better search, mobile optimisation — and, most tellingly, a
  shift **"from a heavily form-based interface toward a more dashboard-centric operating
  experience"** *(cited)*.

**Atriom is already ahead on the visual layer.** Filament 4 gives a modern, responsive, themeable,
RTL-capable admin that Voyager 7S does not have, plus a tenant portal and a mobile API Voyager
charges separately for.

**So the goal is not "look like Yardi". It is: reach Yardi's completeness and information
architecture — every feature discoverable, every number drillable, every workflow visible — on
Atriom's better-looking surface.** That is the standard Voyager 8 is itself chasing.

---

## 2. What Voyager gets right, and what it costs Atriom to be without it

| Voyager pattern | Why it works | Atriom today |
|---|---|---|
| **The record hub** — a lease/tenant "screen" that is a *dashboard for that record*: terms, charges, AR, receipts, notes, documents, options, all reachable without leaving | The operator lives on one page. Everything about the tenancy is one click away | Filament resources are **edit-form-centric**. The lease page is a form; charges, invoices, CAM, sales and deposits are all elsewhere |
| **Drill-down on every number** | A CAM charge → the pool → the expense accounts → the vendor invoices. Nothing is a dead-end figure | Partial — the AR aging page drills; most KPI numbers do not |
| **Dashboard-centric landing** — A/R dashboard, **Month-End dashboard**, receivables dashboard *(cited, Voyager 8)* | You start from "what needs doing", not from a menu | Role dashboards exist (registry-gated). No month-end close dashboard; no work-list framing |
| **Batch review before commit** — the posting run produces an editable batch you inspect *before* it becomes real | Catches a mis-keyed escalation before it reaches 400 tenants | Billing posts directly. Guarded against double-billing, but not against *wrong* billing |
| **Quick-nav / function search** | Power users type where they want to go | Global search exists (module 34) over **records**; it does not reach pages or actions |
| **Saved report filters + report builder** *(cited, Voyager 8)* | The accountant's monthly pack is one click, not twelve filters | Reports have filters; nothing is savable or shareable |
| **Correspondence from the record** — statements, invoices, notices merged and sent from where you are *(cited)* | No export-then-mail-merge round trip | Invoice PDFs and tenant statements exist; not a general "generate & send from here" surface |
| **Persistent scope selector** (property/entity) | Everything you see is scoped, always, visibly | ✅ **Atriom already does this well** — property-first, conformance-gated isolation |

> **The correspondence row is half closed, and the half that remains has a number.** *"Not a general
> generate-&-send surface"* was true when it was written and is now a snapshot: **sending a document
> from the record it belongs to exists on the documents that need it.** An invoice is emailed from
> its own page with its PDF attached ([`EditInvoice::sendToTenant`](../../../app/Filament/Admin/Resources/Invoices/Pages/EditInvoice.php)
> → [`SendInvoiceToTenantService`](../../../app/Services/SendInvoiceToTenantService.php), labelled
> *Send* or *Send again* because those are different decisions); a violation notice is sent from the
> violation (`ViolationTable::sendNotice`, never automatically on create); an owner statement is sent
> from its run (`OwnerStatementRunActions::send`, which marks it sent and bells the owner); an
> announcement is sent from the announcement. The chase is served by download-where-you-are rather
> than by mail-merge — the AR collections row hands you the tenant statement to attach to the call,
> and a CAM allocation hands you that tenant's service-charge statement — and the automated dunning
> twin is the scheduled `billing:remind-overdue-tenants`.
>
> **What is genuinely absent is the MERGE half**: composing a document from a template with a
> record's data. That is [gap **O1**](../../gap-analysis/README.md#2-the-complete-open-list), lease
> document generation and e-signature, and it is tracked there — so this row must not be read as a
> second, separate ask for a correspondence surface. When O1 is built it inherits the renderer the
> other thirteen documents already share (`App\Support\Pdf\PdfDocument`, written in the **reader's**
> language) and the standing-wording registry (`App\Support\DocumentText`), which is most of what a
> merge engine is for.

---

## 3. Where Atriom already beats the benchmark — keep these

Bilingual **EN + AR with real RTL** · a genuine tenant portal with multi-user scoping · a mobile API
· modern responsive Filament shell · property-first isolation enforced by a build gate · native
Filament actions with authorization gates · the search blob that folds Arabic spellings
(«شركة»/«شركه», «أحمد»/«احمد»).

**Do not regress any of these while chasing Yardi parity.** In particular: **no Blade view pages** —
admin UI stays native Filament (an infolist inside an action `->schema()`), and every new string
ships EN + AR keys in the same change.

---

## 4. The UI work-list — stories UX-01…UX-13

Priorities as elsewhere: 🔴 must · 🟠 should · 🟡 later.

### UX-01 ✅ The Lease hub page — **SHIPPED 2026-08-17**

**What shipped.** The record hub: nine commercial actions moved off the LIST (where they hung on
every row while this page carried one) into `App\Filament\Admin\Actions\LeaseActions`, grouped on
the lease page as Money · Premises · Lease. The tabs cover Rent schedule (+ Billing forecast, which
this story did not ask for), Money, Events, Options and Documents. `LeaseSummary` is the Summary,
as a header widget rather than a separate View page — the lease page already IS the hub, and a
second surface showing the same facts drifts from it.

**Deviation, stated:** the story asked for a View page (infolist, not a form). Building one would
have duplicated a surface that already exists and could disagree with it.

---

#### The original story
**As a** Leasing Manager **I want** one page that shows me everything about a tenancy **so that** I
stop hunting across five resources.

A Filament **View page** (infolist, not a form) with tabs:

| Tab | Contents |
|---|---|
| **Summary** | tenant · units + total area · term dates · **rent effective today** · next step date + amount · options with their next critical date · deposit held vs contractual · **AR balance** · occupancy cost % |
| **Rent schedule** | the LS-01 date-ranged grid: from · to · charge type · amount · basis · origin (manual / escalation / amendment). Future rows visually distinct from the current one |
| **Money** | invoices, payments, credit notes, CAM allocations, % rent — each drillable |
| **Events** | the LE-01 timeline: what changed, when it took effect, why, who |
| **Options** | OP-01 options with notice windows, status, and an "exercise" action |
| **Documents** | the lease PDF + amendments |

**Acceptance:** every number links to what produced it; the page is reachable from the leases table
in one click; EN + AR; property-scoped; `->authorize()` on every action in it.

---

### UX-02 ✅ The rent-schedule grid — **SHIPPED 2026-08-08**
Shipped as a **Charge schedule** panel on the lease (`ChargeScheduleRelationManager`) — Yardi's
Charges grid on the lease record. One row per charge type per date range, with the three things an
operator scans for: **which row is billing now** (green, bold amount), **what is still scheduled**
(blue), and **why each row exists** (`origin`: set at creation · changed by an operator ·
contracted escalation · carried on renewal · derived from base rent). The panel heading states what
is billing today and when it next changes, so the answer is readable without opening anything.

**Read-only, deliberately.** Rent changes route through the "Change Rent" action →
`LeaseRentChangeService` → `ChargeScheduleService`, which closes the current row and opens the
next, moves the marketing levy with it, and keeps `Lease::base_rent_monthly` in step. An editable
amount cell would reintroduce exactly the drift that service exists to prevent — the same reason
the rent fields on the lease form are disabled. A test asserts the table exposes no actions.

**Still to come:** the timeline strip, and surfacing a gap/overlap in legacy data (write-time
refusal and the billing guard both exist; nothing draws it yet).

---

### UX-03 ✅ The AR / collections dashboard — **SHIPPED 2026-08-08**
Shipped as `/admin/ar-collections`. The existing `ArAging` page answers the **accountant's**
question — *"how much is 31–60 days late"* — and drills into one bucket. The **collections**
question had no screen at all: *"who do I call this morning, and about what."*

One row per tenant, their outstanding split across every bucket at once, **worst-first — deepest
bucket, then size**, because a tenant 120 days late for 10k needs the call before one 5 days late
for 100k. Plus invoice count, oldest item in days, and **when they last actually paid** (or a red
"never paid") — the single best signal for slow-payer vs stopped-payer. Statement download per row,
CSV export, property-scoped, EN + AR.

**The refactor that came with it:** the aging boundary arithmetic was copied between
`arAgingBuckets()` and `arAgingDrilldown()` with a comment asking them to stay identical — a
promise a comment cannot keep. A third copy for the worklist would have been the one that finally
disagreed, and *a bucket total that disagrees with the list behind it destroys trust in both*. It
now lives once in `ReportService::agingBucketKey()` against a single `AGING_BUCKETS` register, and
`ArCollectionsTest` holds all three views to the same answer on the exact day boundaries where an
off-by-one hides.

**Still open:** grouping by charge type (RR-03) waits on item-level allocation (MF-06); inline
apply-credit / record-payment / flag-disputed are not there yet.

> **CLOSED 2026-08-10 and 2026-08-25 — the sentence above lists four things and only ONE of them is
> still open.**
>
> **Grouping by charge type shipped 2026-08-10**, together with the item-level allocation it was
> waiting on: MF-06 is `App\Support\InvoiceItemSettlement` and RR-03 is
> [`ArAgingByType`](../../../app/Filament/Admin/Pages/ArAgingByType.php) at
> `/admin/ar-aging-by-type`. Its own docblock states why the split earns a screen: *"EGP 400k over
> 90 days"* reads as delinquent rent and prompts a collections call, and if most of it is a service
> charge the tenant has formally disputed then **the call is the wrong action and the number is the
> wrong alarm**. Because every per-line figure is derived from `invoices.paid_amount` rather than
> stored, the re-cut ties to the aging summary by construction — the same discipline the
> `agingBucketKey()` refactor above imposed on the buckets.
>
> **Flagging a line disputed shipped the same day** as MF-07: `InvoiceActions::disputeLine` and its
> `resolveDispute` twin, over `invoice_items.disputed_at`, backed by
> [`DisputeInvoiceItemService`](../../../app/Services/DisputeInvoiceItemService.php). The reason is
> required, because the flag **suppresses a late fee** and the first question anyone asks three months
> later is why. **It is deliberately per LINE and on the invoice, not per tenant on this worklist:** a
> dispute is an argument about a charge, and flagging a whole tenant would suppress the fee on rent
> nobody is arguing about.
>
> **Record-payment shipped 2026-08-25 (UX5-03) and is a LINK by design, not a gap.** The row carries
> the tenant across into the real payment form (`PaymentResource::getUrl('create', ['tenant' => …])`)
> rather than opening a slimmed-down modal here. The comment at the call site states the reason and it
> should not be re-litigated: the real form guards the posting date, the property scope,
> over-allocation and the orphaned-receipt case, and **a second form beside it would be a second set
> of those guards to keep in step** — the same argument that made the charge-schedule grid read-only
> in UX-02.
>
> **What genuinely remains from this row is inline apply-credit alone.** Applying a tenant's
> on-account credit lives on the invoice (`EditInvoice::apply_credit` → `ApplyTenantCreditService`,
> capped at `min(credit, balance)` and posting its own dated entry), so settling a chased tenant from
> a credit they are already holding still means leaving the worklist. If it is built, it is the same
> shape as record-payment — carry the tenant and the invoice across — for the same reason.

---

### UX-04 ✅ The Month-End Close dashboard — **SHIPPED 2026-08-08**
A checklist that knows its own state, in the order from
[02 §9](02-yardi-money-flow.md#9-month-end-close): billing posted · recoveries/percentage rent
posted · receipts entered · AP posted · automated journals posted · GL tie-out clean · period
closed. Each row shows ✅/⚠️, the count outstanding, and a link to the thing to fix.

**Everything it needed already existed** — `AccountingPeriod`, the books tie-out,
`PeriodService`. Shipped as `/admin/month-end-close`, backed by
[`MonthEndReadinessService`](../../../app/Services/Accounting/MonthEndReadinessService.php), which
derives every number from the service that already owns that decision rather than re-implementing
it. The `ledger_in_sync` row runs the **same assertion `PeriodService::closePeriod()` runs**, so a
green checklist means the close will actually go through. Closing itself stays in the Accounting
Periods resource — one place to close a period, one gate.

**The failure mode this screen has and no other screen does: being green for the wrong reason.**
One instance was caught while building it — the books-tie-out row read a `$check['ok']` key that
`BooksReconciliationService` does not emit (it emits `passed`), so `?? true` would have reported
clean on every month forever. It now treats an unreadable result as a failure, and
`MonthEndCloseTest` asserts each row goes **red when its condition is genuinely outstanding**, not
merely that the page loads. Mutation-verified against that exact bug.

---

### UX-05 ✅ Billing run preview (batch before commit) — **SHIPPED**
A dry-run of `MonthlyBillingService::runForPeriod()` rendering the proposed invoices — lease, period,
lines, total — with the skip **reasons** shown (`fit_out`, `off_cycle`, `already_billed`,
`no_applicable_charges`, which the service already returns). The operator reviews, then commits.

**This was the single highest-confidence UI addition in the list**: it is the control that stops a
bad escalation reaching 400 tenants, and the service already returned everything it needed.

**Shipped as `BillingRunPreview`**, reachable from the invoices table. It shows the service's own
skip REASONS rather than a bare "skipped" — "in fit-out" is an answer, "skipped" is a question.
*(Status corrected 2026-08-10: this row still read 🔴 after the page had shipped. Two rows in this
file did, which is the same staleness the gap analysis carried — check the code before believing a
🔴 here.)*

---

### UX-06 ✅ Rent roll & lease expiry screens — **SHIPPED** *(the UI half of RR-01/RR-02)*
Rent roll as at a date, with column sort/filter, per-m² columns, totals, and CSV + PDF. Expiry
schedule as a by-year view with area and rent at risk. Both property-scoped, both EN + AR.

---

### UX-07 ✅ Tenant 360 — **SHIPPED 2026-08-10** *(partial: sales trend and violations pending)*
Customer-level rather than lease-level: every lease this tenant holds (current and past), total
exposure, AR, sales trend, occupancy cost %, requests, violations, documents. Yardi's
customer-vs-lease split exists precisely so this view can exist.

**Shipped as `ViewTenant` + `TenantInfolist`.** The relation managers — leases, payments, requests,
notes, portal users — were ALREADY on the resource; only the Edit page rendered them, so answering
"what is going on with this tenant" meant opening an edit form, and a read-only role could not get
there at all. The hub existed and was locked behind a form.

Money leads, because that is what the screen is opened for: outstanding, credit on account, active
leases and status across the top, identity and tax details below. **Every figure is property-scoped**
through `visibleAssetIds()` — a tenant trading in two malls has one row here, so an unscoped total
would show a mall-A operator what the company owes in mall B.

**Still open on this row:** sales trend and violations are not on it yet. Both exist as their own
screens; folding them in is a smaller job than the hub was.

---

### UX-08 ✅ CAM reconciliation workbench — **SHIPPED as the pool's own record page**
One screen for the whole [S11](04-scenarios.md#s11--cam-year-end-reconciliation) flow: pool total →
(later: the GL accounts behind it) → denominator + basis → the allocation table with each tenant's
area, share, cap applied, admin fee, estimate billed, true-up → bill/credit actions → **the
re-estimate proposal** (RC-05). Plus the tenant-facing statement (RC-06) generated from the same
screen.

> **CLOSED — the pieces on 2026-08-09, assembled onto one screen 2026-08-30, batch posting
> 2026-09-01.** Every leg of the flow above is on the CAM expense pool's record page, re-verified
> against the code today.
>
> **The pool total, and the GL accounts behind it.** `CamExpensePoolActions::syncFromLedger` pulls the
> year's actual expense straight out of the ledger (RC-01), and the `ledgerAccounts` picker on
> `CamExpensePoolForm` is *which* accounts — the parenthesised *"later"* in the story. **The
> denominator and the basis are on the same form**: the participant area, plus `expense_basis` and
> `estimate_basis`, both **frozen once any allocation is billed**, because a basis is a claim about
> money already invoiced.
>
> **The allocation table is the working, not a summary.** `CamAllocationsRelationManager` carries the
> tenant, the unit, the share, the allocated amount, the capped cost, what the cap absorbed, the
> estimate already paid, the true-up and the admin fee — and its `breakdown` action renders every leg
> of one tenant's figure, cap and VAT included, which is what makes the number arguable rather than
> merely displayed. `PdfDownloadAction::make('statement')` on the same row is RC-06, through
> `CamStatementPdfService`.
>
> **Bill/credit is both per row and as a batch.** Per-allocation *Bill* stays, because holding one
> tenant back while billing the rest is a real act (a disputed share, a lease in negotiation);
> `billAllPending` is the batch, and its confirmation modal states in **figures** how many invoices
> and how many credit notes it is about to raise — the difference between a batch you can approve and
> a button you press hoping. `markReconciled` then refuses to close the year while any allocation is
> still unbilled, so a reconciled pool cannot mean *"we stopped looking"*. `applyEstimates` is the
> re-estimate proposal, RC-05.
>
> **A stated deviation: this is the record hub, not a workbench page.** Voyager's reconciliation
> screen is a destination of its own; Atriom put the same flow on the pool record under the
> panel-wide rule in [`App\Support\RowActionPolicy`](../../../app/Support/RowActionPolicy.php) —
> **the list finds, the record acts** — with `EditCamExpensePool::getHeaderActions()` composing
> `CamExpensePoolActions::all()`. The reason is the one UX-01 gave for not building a separate lease
> View page: a second surface showing the same pool would be free to drift from it, and the pool page
> already holds the form, the allocations and the acts. Anyone tempted to build the standalone
> workbench should read that as a decision, not an omission.

---

### UX-09 ✅ Critical-dates work-list — **SHIPPED 2026-08-10**
One list answering "what needs action in the next 90 days": option notice windows opening/closing,
lease expiries, insurance expiries, contract renewals. Grouped by urgency, each with the action that
resolves it. The nav badge points here.

**This was mostly already built and nobody had noticed.** The `ActionRequired` dashboard widget
already carried thirteen categories — lease expiries at two urgencies, holdovers, vendor document
expiry, contract notice, matured cheques, overdue AR, unbilled leases, vacant units, missing sales
declarations, SLA breaches — each permission-gated and each linking to a filtered list. It IS the
work-list this story asks for.

**The gap was option notice windows**, which is the item with the hardest deadline: once the window
passes the right lapses, a renewal the tenant loses or a break they can no longer take.
`leases:scan-option-windows` notified about it and nothing showed it in the list an operator opens.
Added as a fourteenth row plus the `option_closing` filter it lands on.

Building a separate page would have duplicated thirteen categories that already work, and the copy
would have drifted from the original within a release.

---

### UX-10 🟠 Global search reaching actions and pages
Extend module 34's provider so typing "rent roll", "close period" or "run billing" navigates, not
just record lookups. Keep the existing rule absolutely: authz + property isolation come from
`getEloquentQuery()`, never re-implemented in the provider.

---

### UX-11 ✅ Saved views — **SHIPPED, both halves** *(filters 2026-08-17 · columns and the default 2026-08-23)*
Let a user save a table's filter+column state and set one as their default. The accountant's monthly
pack becomes one click.

**The row was marked shipped while half of it was not built.** `SavesReportViews`/`SavesTableViews`
saved the filters; the columns rode along from EG-32 slice 1; *"and set one as their default"* had
no column, no action and no code path, so an operator could build the arrears pack, name it, share
it — and still land on the unfiltered list every morning and pick it out of a menu. Found by the
pre-staging verification against this file, which is the argument for re-reading a row rather than
trusting its tick.

`table_views.is_default` means *"the default for whoever can see it"*, which covers both defaults
that are actually wanted: an unshared view is a PERSONAL default, a shared one is the TEAM's. **A
personal default wins**, so a manager marking a team view never overrules a colleague who has
already stated their own. It is applied by REDIRECTING to the view's own URL — this trait's rule is
that a view IS a URL and nothing sets Livewire state directly — and the menu grows an *"All
records"* escape carrying `?tableView=none`, because a link to the plain list has an empty query
string, which is precisely what the redirect reads as "nothing asked for".

> **A false-pass worth recording.** `assertNoRedirect()` reads only `$effects['redirect']`, which
> Livewire populates on an UPDATE request — a redirect issued from `mount()` is a plain HTTP one on
> the initial response, so that assertion **cannot fail** there and stayed green with the loop guard
> deleted. `assertRedirect()` is not its mirror: on a non-Livewire request it checks the RESPONSE.
> The negative assertion has to be `assertOk()`, which falls through `Testable::__call()` to the
> same response.

---

### UX-13 ✅ Tabbed forms for every multi-concern resource — **SHIPPED** *(operator directive, 2026-08-08)*
**As an** operator **I want** a long resource form split into tabs, each tab one group of related
settings **so that** I am looking at one concern at a time instead of scrolling thirty fields.

> **CLOSED 2026-08-10 — the heading read 🔴 while its own candidate table below said otherwise.**
> Every form in that table is either converted or deliberately left alone by the rule the story
> states, and the standard has since been applied outside the list: `FormTab::make()` is used by
> **eight** forms — payment, invoice, tenant, lease, credit note, service plan, tenant request and
> `MarketingPostForm`, which its own commit describes as *"a thirty-field scroll"* and which was never
> on the list below because nobody had measured it.
>
> **The one row that did not convert is `VendorBillForm`, and that is the rule working rather than
> work outstanding.** It carries fifteen fields in exactly **two** sections — the bill's details and
> its amounts — and the standard is *more than ~3 distinct concerns*, so a tab strip there would cost
> a click and buy nothing. The same threshold is why everything below ~12 fields stays a section.
>
> `FormTab` remains **mandatory** for any conversion: Filament still ships no validation-error
> indicator on `Tabs`, so a tab strip without the per-tab danger badge is strictly worse than the
> scroll it replaced. `FormTabErrorBadgeTest` holds it, mutation-verified against an upstream API
> change.

**The standard.** A resource form covering more than ~3 distinct concerns is built as
`Tabs` → one tab per concern, via **`App\Support\FormTab::make(label, [...])`** — never a bare
`Tab::make()`.

**Why the helper is mandatory, not a convenience.** Tabs introduce one failure the long scroll did
not have: submit from tab 1 with a required field blank on tab 4, and the form refuses with the
error rendered on a panel nobody can see. **Filament v4.11.8 ships no validation-error indicator on
`Tabs`** — the word "error" does not appear in `Tabs.php`, `Tab.php` or their Blade. `FormTab` adds
a danger badge counting the errors *inside that tab*, derived at render time from the tab's own
fields (`getChildComponentContainers()` → `getFlatFields()` → `getStatePath()` vs the error bag), so
it can never drift from the fields the tab actually holds. Without it, a tabbed form is strictly
worse than the scroll it replaced.

**Reference implementation:** [`LeaseForm`](../../../app/Filament/Admin/Resources/Leases/Schemas/LeaseForm.php)
— 30 fields, 6 sections → 5 tabs (Lease details · Term · Financial terms · Percentage rent ·
Notes & documents), with `persistTabInQueryString()` so a link can point at a tab.
Tests: `FormTabErrorBadgeTest` (badge is per-tab, and mutation-verified against an upstream API
change).

**Remaining candidates, worst first** (fields / sections, measured 2026-08-08):

| Form | Fields | Sections |
|---|---|---|
| ~~`LeaseForm`~~ | ~~30~~ | ✅ **done** |
| ~~`TenantRequestForm`~~ | ~~24~~ | ✅ **done 2026-08-10** — 6 tabs, permit tab conditionally visible |
| ~~`InvoiceForm`~~ | ~~19~~ | ✅ **done 2026-08-10** — 4 tabs (Details · Items · Amounts · Notes) |
| ~~`TenantForm`~~ | ~~18~~ | ✅ **done 2026-08-10** — 4 tabs |
| ~~`CreditNoteForm`~~ | ~~18~~ | ✅ **done 2026-08-10** — 4 tabs |
| `VendorBillForm` | 15 | **2 — left as sections.** The rule is *more than ~3 concerns*, and two panels do not earn a tab strip |
| ~~`ServicePlanForm`~~ | ~~15~~ | ✅ **done 2026-08-10** — 4 tabs (Scope · The work · Schedule · Assignment), fields REORDERED into groups |
| ~~`PaymentForm`~~ | ~~14~~ | ✅ **done 2026-08-10** — 4 tabs |

Below ~12 fields a tab strip costs more than it saves — leave those as sections.

**A `Tab` has no `->description()`**, so a section carrying one (tax & address, documents, payment
allocations) leads its panel with a hidden-label `Placeholder` instead. Dropping the guidance would
have been the silent cost of the conversion — those sentences are the only explanation an operator
gets for what the panel is for.

---

### UX-12 🟡 Drill-down audit
A sweep with one rule: **every money figure on every dashboard and report links to the records
behind it.** Cheap per widget, and it is what makes an operator trust the number.

---

## 5. The six UX rules to hold while building these

1. **Click budget.** The most-repeated task each role does must be ≤ 3 clicks from their landing
   page. This is the specific thing Voyager is criticised for; do not import the defect.
2. **No dead-end numbers.** If a figure can be drilled, it links. If it genuinely cannot, say what
   it is made of in a tooltip.
3. **Refusals explain themselves.** A `DomainException` already renders as a message, not a 500 —
   make the message say *what to do next*, not just what went wrong. The skip **reasons** the
   billing service returns are the model: `fit_out` beats "no charges".
4. **One concern per screen.** A form covering many concerns is tabs, not a scroll (UX-13) — and a
   tab strip without a per-tab error badge is a regression, not a feature.
5. **Bilingual and RTL from the first commit.** EN + AR keys in the same change, never a follow-up.
   An Arabic-first operator is the primary user.
6. **Feedback carries state.** After an action, say what changed and link to it — "3 invoices
   created, 1 skipped (fit-out) → view run".

---

## 6. Where this sits in the plan

**Phase 8**, running *alongside* the functional phases rather than after them — each UI story
depends on the phase that produces its data:

| UI story | Ships with |
|---|---|
| ~~UX-05 billing preview~~ ✅ · ~~UX-04 month-end close~~ ✅ · ~~UX-03 AR collections~~ ✅ | **all three shipped 2026-08-08** |
| UX-01 lease hub · UX-02 rent schedule grid | phase 1 (charge schedule) |
| UX-09 critical dates | phase 3 (options) |
| UX-06 rent roll / expiry | phase 7 (reports) |
| UX-08 CAM workbench | phase 6 (recoveries) |
| UX-07 tenant 360 · UX-10 search · UX-11 saved views · UX-12 drill-down audit | opportunistic |

**UX-05, UX-04 and UX-03 all shipped 2026-08-08.** All three were assembly over data that already
existed, none waited on the schedule rebuild, and each removed a class of operator anxiety on its
own. **UX-01/UX-02 are next, and they wait on phase 1.**

---

## Sources

- [Yardi Voyager 8 — what's new](https://www.redirectconsulting.com/blog/whats-new-in-yardi-voyager-8-features-ai-and-upgrade-best-practices) and [2026 guide](https://www.redirectconsulting.com/blog/yardi-voyager-8-2026-guide-features-ai-capabilities-operational-impact) — refreshed UI, dashboard-centric shift, contextual navigation, Report Builder
- [Introducing Voyager 8 — BC Solutions](https://www.bcsolut.com/post/voyager8) — A/R dashboard, Month-End dashboard, receivables dashboard
- [Voyager User Interface Overview (Core User's Guide)](https://yardi.westcliff-group.com/voyager/Help/Core%20User's%20Guide/Voyager%20User%20Interface%20Overview.html) — side menu, lookup lists
- [Yardi Voyager reviews — SelectHub](https://www.selecthub.com/p/property-management-software/yardi-voyager/) — the 77% usability figure
- [Yardi Voyager reviews — Capterra](https://www.capterra.com/p/33832/Yardi-Voyager/reviews/) · [G2 pros & cons](https://www.g2.com/products/yardi-voyager/reviews?qs=pros-and-cons) — "clunky", "early 90s interface", excess clicks
- [Re-Leased vs Yardi Voyager](https://www.selecthub.com/property-management-software/yardi-voyager-vs-re-leased/) · [G2 comparison](https://www.g2.com/compare/re-leased-vs-yardi-voyager) — the usability head-to-head
