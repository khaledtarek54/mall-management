# Reports

> Unified monthly financial close, AR aging analysis, and tenant/owner statements—all data-driven from the invoice and payment ledgers.

## 1. Purpose & business context

Reports power the **finance & collections team** and **mall owners** with visibility into monthly performance, aging receivables, and tenant liability. In the Eltizam (operator) and Jawad (owner) model:

- **Admin/Finance staff** run the monthly close to reconcile billed revenue, collections, outstanding AR, and credit note activity for month-end settlement and reporting to owners.
- **Collections teams** drill into AR aging buckets to prioritize follow-up with delinquent tenants.
- **Owners** download property-level statements to audit tenants' payment status and see top delinquents at a glance.
- **Tenants** retrieve their own 12-month statement for reconciliation and dispute resolution.

The module is **optional** (Module flag: `reports`; defaults enabled) and scoped via `TenantScope` so each user sees only their assigned properties' data.


## Revenue forecast (2026-08-19)

`/admin/revenue-forecast` — what the portfolio will bill, month by month, from every lease already
signed. Voyager's Forecast Manager *(cited,
[benchmarks/yardi/01](../benchmarks/yardi/01-yardi-lease-administration.md) §334)*, and §205's
point that the forecast is computable the day a lease is signed — true here only because
`ChargeScheduleService` writes the whole rent ladder at signing rather than one current amount.

**It computes nothing.** Each month is `LeaseBillingForecastService` summed, which is
`MonthlyBillingService::planInvoiceForLease()` — the method the real billing run persists. Verified
by tie-out: the portfolio total equals the sum of every lease's own forecast tab, to the piastre. A
forecast with its own arithmetic would disagree with the invoices it predicts, and would do so
first on a proration edge or an escalation step.

### The half that is deliberately missing

Voyager's forecast includes **assumed renewals and re-lets**. That needs a renewal probability and
a market rent, neither of which this system holds. A guessed figure on a revenue chart is
indistinguishable from contracted income — and this is a page an owner may be shown — so every
figure here can be pointed at a signed contract, and the subheading says so where a reader will see
it before any documentation.

### Three rules the numbers follow

| Rule | Why |
|---|---|
| **Net of tax** | VAT is collected for the state, not earned. Including it would overstate every figure by the standard rate |
| **Active leases only** | A `pending_approval` lease is not contracted income, and an ended one is not assumed to renew — it simply stops contributing the month after it expires |
| **A month is "Invoiced" only when EVERY lease in it has been billed** | One un-billed lease makes the whole month a projection. Labelling a part-billed month as settled fact is how a forecast gets read as a fact |

Broken down by charge type, and the CSV carries a column per type — the question a finance lead
asks of a forecast is not *"how much?"* but *"how much of it is rent?"*, and a single total cannot
be reconciled against a budget that is itself split by account.

**Not cached**, deliberately: a cached forecast that silently predates a rent change is the failure
this page exists to prevent. ~0.3 s for 34 leases over 24 months, linear in both. The horizon is
**clamped to 60 months** in the service — the page offers 6/12/24/36, but `horizon` is a public
Livewire property and Livewire takes what the payload says, not what the `Select` rendered.

## 2. Domain model

| Entity | Model Class | Key columns | Meaning |
|--------|-------------|------------|---------|
| **Invoice** | `App\Models\Invoice` | `id`, `number` (unique, e.g. INV-HW-202602-0001), `lease_id`, `tenant_id`, `status` (draft/issued/partially_paid/paid/overdue/disputed/cancelled/credited), `issue_date`, `due_date`, `period_start`, `period_end`, `subtotal` (decimal), `vat_amount` (14% standard), `total`, `paid_amount`, `credit_applied_amount`, `balance`, `currency` (EGP) | Core billing ledger entry; links to lease + tenant. Status tracks lifecycle; balance = total - paid_amount - credit_applied_amount. |
| **InvoiceItem** | `App\Models\InvoiceItem` | `id`, `invoice_id`, `type` (base_rent / service_charge / utility / parking / percentage_rent / late_fee / other), `amount`, `vat_rate` (5.2 decimal), `vat_amount`, `total` | Line-item breakdown of invoice; types feed revenue_by_type aggregation. |
| **Payment** | `App\Models\Payment` | `id`, `reference` (unique), `tenant_id`, `amount`, `method` (card/bank_transfer/instapay/wallet/cash/cheque/other), `status` (initiated/authorized/captured/reconciled/settled/failed/refunded/bounced), `payment_date`, `gateway`, `gateway_transaction_id`, `receipt_notified_at` | Payment record; many-to-many via pivot `invoice_payment` with `allocated_amount`. Only `captured` status counts toward collections. |
| **CreditNote** | `App\Models\CreditNote` | `id`, `number` (unique, e.g. CN-HW-202602-0001), `tenant_id`, `invoice_id` (nullable), `lease_id` (nullable), `status` (draft/issued/applied/void), `issue_date`, `total`, `applied_amount`, `balance`, `reason` | AR adjustment; standalone (lease_id null) = tenant-level, linked = invoice-specific. Counts in monthly close if issued/applied. |
| **Asset** | `App\Models\Asset` | `id`, `code`, `name` | Property/mall; report data is scoped via `TenantScope::currentAssetId()`. |

**Relationships:**
- `Invoice` → `Lease` → `Unit` → `Asset` (scoping path)
- `Invoice` ↔ `Payment` (many-to-many via `invoice_payment` pivot with `allocated_amount`)
- `Invoice` → `Tenant`
- `CreditNote` → `Tenant`, optionally → `Invoice`, optionally → `Lease`

## 3. Business rules & invariants

> **A saved view can deliver itself (2026-08-12).** `reports:deliver` runs from the scheduler at
> 06:00 and emails the saved views due that day as CSV — the same bytes the export button produces,
> so a delivered copy and a downloaded one can never disagree.
>
> **It renders as the person who saved it.** A report reads whatever the current user may read, and
> a console command has no current user: rendered as nobody, a report either shows nothing or shows
> everything. The owner is authenticated for the render only, their `canAccess()` is re-checked
> first, and the guard is restored however it goes — leaking it would hand the next report in the
> run somebody else's property scope. It also means a schedule **stops** when access is withdrawn,
> which is right: a schedule is not a standing grant, and nobody revisits schedules.
>
> **…and in the PROPERTY it was saved in — which it did not, until 2026-09-01.** Rendering as the
> right person is only half the context. Most report pages carry no `$assetId`: they scope with
> `TenantScope::currentAssetId()`, which reads the mall the operator is **standing in**. There is no
> Filament tenant in a queue worker, so that answered `null` — and every scoped query reads null as
> *no property filter*. A rent roll saved in one mall was therefore delivered every month as the
> **whole portfolio**: tenant names, contracted rents, rates per square metre and security deposits,
> in a CSV with no property column and a filename naming no mall. The recipients are routinely
> outside the business — the field's own help text invites the owner's accountant and the auditor,
> *because* they have no login here, which is equally why they could not tell whose tenants they
> were reading.
>
> `ReportParameters::PROPERTY_KEY` makes the standing property part of what a saved view
> reproduces, captured by `snapshot()` for every report rather than only the ones that happen to
> declare an `$assetId`, and the delivery re-establishes it as the Filament tenant around the render
> — so **every** report page is scoped correctly without each one needing a parameter. A view that
> records no property is **refused and logged**, never delivered as the portfolio: one saved before
> this was captured is indistinguishable from one deliberately spanning every mall, and only one of
> those is safe to email. The owner's access to that mall is re-checked at delivery for the same
> reason their `canAccess()` is. (`ADeliveredReportStaysInItsOwnMallTest` — its fixture puts a lease
> in each of two malls, because with one property's data present a portfolio-scoped render and a
> correctly-scoped one produce the same file and the test cannot fail.)
>
> **Idempotent, because the scheduler is not.** `last_delivered_on` is a DATE, claimed under a lock
> and re-checked inside the transaction — the pattern every scheduled scan here uses. A retry or a
> catch-up after downtime re-sends nothing. A monthly schedule on the 31st fires on the last day of
> a short month rather than being skipped: "the 31st" from an accountant means month end.
>
> **A report is deliverable when it implements `App\Contracts\DeliverableReport`** — a CSV that
> renders without a browser. Each page's export closure was lifted into a `reportCsv()` method that
> the export BUTTON now also calls, so a downloaded copy and a delivered one cannot diverge.
>
> The rest have no CSV export in the first place — a checklist, a floor plan, a diagram, a dry run
> and a PDF pack — which is a better reason than "nobody has lifted it yet".
> `ReportCatalogue::NOT_DELIVERABLE` names each with its reason, and a conformance test fails on a
> report that declares neither. *(This paragraph used to hand-type "fourteen of twenty" and "the
> six that are not"; both had drifted, and one of the six it named — "a searchable log" — is the
> audit log, which has been deliverable for some time and as of 2026-08-22 has the export button to
> match. Counts here now come from the registry, per the project's rule against typing one into a
> doc.)*
>
> The general ledger is the one that can be deliverable and still refuse: a saved view with no
> account chosen is an unanswered question, not an empty report, so `reportCsv()` throws a
> `DomainException` the delivery command reports rather than mailing an empty file every month.

> **Filters can be saved (2026-08-12).** Every report takes them and none were rememberable: "AR
> ageing as at last month-end for Atriom Walk" was six clicks, every time. "Save this view" names
> the filters a page is currently carrying; saved views list first on the hub.
>
> **The resource LISTS have their own saved views (2026-08-12).** Same idea, different table, and
> the registers are where filters actually pile up — Leases carries 12, Invoices 10, tenant requests
> 9. `App\Filament\Admin\Resources\Concerns\SavesTableViews` adds "Save view" + a saved-view menu
> to a list page's header; it is on Invoices, Leases, Payments, Tenant requests, Units, Tenants and
> Work orders.
>
> **A view is a URL.** It stores the four keys a list page binds — `filters`, `sort`, `search`,
> `tab` — and opening one is a link built by `App\Support\ResourceLink`. There is no second code
> path setting Livewire state, so a saved view inherits everything `ResourceLinkConformanceTest`
> already guarantees (including that a URL beats a stale session filter), and it can be pasted to a
> colleague as a plain link.
>
> **…and one view can be the one that OPENS (UX-11's other half, 2026-08-23).** Saving a view was
> only half of what the benchmark row asks for; until this shipped an operator could build the
> arrears pack, name it, share it — and still land on the unfiltered list every morning and pick it
> out of a menu. `table_views.is_default` means *"the default for whoever can see it"*, which
> answers both defaults an operator actually wants: an unshared view is a **personal** default, a
> shared one is the **team's**. **A personal default WINS**, so a manager marking a team view never
> overrules a colleague who has already stated their own. (The alternative — a user × resource
> preference table — buys one more case, adopting a colleague's shared view without copying it, for
> a whole extra model and a second place a default can live. Recorded in the migration rather than
> silently dropped.)
>
> **It is applied by REDIRECTING to the view's own URL, never by setting Livewire state** — the same
> rule as the paragraph above, which is what keeps the address bar honest and the link pasteable.
> Livewire fires a trait's mount hook exactly once on the initial build, so it cannot fire on a
> filter change, and it is skipped the moment the request asks for anything, so it cannot loop.
> **The escape hatch is load-bearing, not a nicety:** a link to the plain list carries an EMPTY query
> string, which is indistinguishable from a bare page load, so the obvious "All records" reset would
> bounce straight back into the default. It carries **`?tableView=none`** instead —
> `bootedSavesTableViews()` already ignores anything non-numeric. The default is offered over views
> this user may SEE rather than only ones they own (adopting the team's pack is the case UX-11 is
> about), and re-resolved through `visibleTo()` at the WRITE, because the option list is a UI
> convenience and that clause is the gate.
>
> *(Testing trap, paid for here: `assertNoRedirect()` asserts only on `$effects['redirect']`, which
> Livewire populates on an UPDATE request. A redirect issued from `mount()` is a plain HTTP one on
> the initial response, so that assertion can never fail there — measured, it stayed green with the
> loop guard deleted. `assertRedirect()` is NOT its mirror: on a non-Livewire request it checks the
> RESPONSE, which is why the positive test was sound. The negative one uses `assertOk()`.)*
>
> **…and the COLUMNS are part of it (EG-32, 2026-08-23).** A view stored everything about what the
> list was showing except the columns — the one part an operator had to redo by hand. Filament
> persists a column layout in the SESSION, so the choice survived a reload and nothing else: it did
> not travel with a shared view, was gone tomorrow, and opening a named view left whatever the
> browser happened to be showing. S-5 called that *"no user-defined columns"*, which is only half
> right — this app marks **173** columns toggleable and Filament v4 ships the manager. Work orders
> offer **13** optional columns and tenant requests **10**; what was missing was making the choice
> durable and shareable. (Worth knowing: **`ListInvoices` offers none at all** — the busiest money
> list has no column choice to save. Which of its ten columns should be optional is a judgement for
> the operator, not a default worth guessing.)
>
> The layout is far too big for a query string and Filament binds none of it to the URL, so the link
> carries **`?tableView={id}`** and the page reads the columns back — which keeps a view a single
> pasteable URL instead of growing the second code path the paragraph above rules out. Because the
> id is in the URL, a refresh re-applies the view's columns, exactly as it re-applies its filters.
>
> **Only the toggles are stored** (`name => isToggled`), and only for columns that are toggleable at
> all: a fixed column records no decision, and storing it would pin today's fixed set into a row
> read a year from now. Labels and hidden flags are deliberately not stored — they are re-derived
> from the reader's table every time.
>
> **A view that states no columns opens on the list DEFAULTS**, not on whatever the session held.
> A view is a named state a colleague must be able to open and see what you saw. Views saved before
> this shipped state none, so they open on the defaults.
>
> **Sharing still grants nothing, and now that has two layers.** The layout is rebuilt from the
> READER's own `getDefaultTableColumnState()`, so a name their table does not carry is never
> introduced. On top of that Filament's `syncTableColumnStateItemAttributes()` re-derives `label`,
> `isHidden` and `isToggleable` and forces a fixed column back on. **Upstream is the layer currently
> doing the work** — measured, by deleting our own guard and watching the security test stay green —
> so `SavedTableViewsTest` pins Filament's half as a contract, the same way
> `FilamentActionDispatchContractTest` pins hidden-implies-disabled, and an upgrade that changes it
> turns the build red rather than quietly removing the protection.
>
> **Column REORDERING is ON since 2026-08-23**, panel-wide, from the one line in `TableDefaults`.
> `HasColumnManager` throws a `LogicException` for any blank-label column when reordering is on, so
> it needed a sweep first — and the sweep, run by building every admin list rather than by grepping,
> found exactly **one**: `MarketingPostsTable::hero`, the card thumbnail, plus its portal twin. Both
> now carry the label the form field already had. (Six other `->label('')` calls in `app/Filament`
> are FORM components, which the column manager never sees.)
> `ReorderableColumnsConformanceTest` keeps it true, and **asserts its own premise** — that
> reordering is actually switched on — because a blank label is only fatal BECAUSE it is.
>
> **A saved view stores the ORDER as well as the toggles**, under its own `column_order` key: which
> columns show and what order they show in are different questions, and a row saved before
> reordering existed answers only the first. An empty order means the table's own, and a column the
> saved order never mentions keeps its place at the END — so a column added to the resource later
> appears rather than vanishing because an old row failed to mention it.
>
> **The 23 catalogued REPORTS remember their columns too (EG-32).** `ReportParameters::snapshot()`
> reads a page's own public scalar properties and deliberately excludes trait-provided ones, so
> Filament's `$tableColumns` was invisible to it and a saved report reset its columns on every open.
> `SavesReportViews` now captures the layout beside the parameters and the hub's link carries
> `?savedReport={id}`, exactly as a resource list's saved view carries `?tableView={id}`. Both go
> through **one** implementation, `App\Support\Filament\SavedColumnLayout` — two copies is how the
> two would drift into remembering different things.
>
> The guard is `method_exists($this, 'getDefaultTableColumnState')`, not a list of page classes:
> several catalogued "reports" are not tables at all (the workflow diagram, the occupancy map) and a
> list would be one more thing to keep in step with the catalogue.
>
> **What this does NOT make is a report designer.** Which columns a report offers as optional is
> still a per-report editorial decision, and most offer none today — `RentRoll` offers three,
> AR ageing, the general ledger, the expiration schedule and the income statement offer none. The
> DURABILITY is built and proven; widening the choice is a judgement about each report, not a
> mechanism.
>
> **Stored in `table_views`, not `saved_reports`.** They are the same idea and not the same record:
> `saved_reports` carries the scheduled-delivery half (`frequency`, `recipients`,
> `last_delivered_on`), and emailing someone "the leases list" is not a thing. Those columns would
> be permanently null, and every existing hub/delivery query would need a `where type = …` it does
> not have.
>
> **Sharing grants nothing.** `is_shared` publishes a view to everyone who can open that list.
> Opening it is opening a URL, so the list re-scopes every filter through its own
> `getEloquentQuery()` — property isolation and RBAC apply exactly as for a hand-typed one. Pinned
> by `SavedTableViewsTest`, which opens a shared view as a user assigned to a different property and
> asserts they see nothing. Only the owner may delete a view, re-checked at the delete itself
> rather than only when the option list was built.
>
> Parameters are read from each page's own public scalar properties by reflection
> (`App\Support\ReportParameters`) — so a report that grows a filter has it saved with nothing to
> register. Trait-provided properties are excluded explicitly: reflection reports them as declared
> on the using class, and `InteractsWithTable` alone would have put `isTableLoaded` into every
> saved view. Applying is deliberately lossy — a key the report no longer declares is dropped
> rather than throwing.
>
> **Sharing publishes filters, not access.** A shared view carries a PROPERTY in its parameters, so
> two independent things stop it becoming a capability: the hub asks the report page's own
> `canAccess()` before listing it, and the report re-clamps every parameter exactly as it does for
> a URL typed by hand. Both are tested, each with a paired control. A shared view can only be
> deleted by the person who saved it.

> **There is an index (2026-08-12).** Nineteen reports were scattered across five sidebar groups
> with nothing anywhere listing them: an operator who had not been shown a report did not know it
> existed. `/admin/report-hub` groups them — Financial · Receivables · Leasing · Operations · Tax —
> each with a one-line description of the question it answers, which is the field that earns the
> page. A report appears exactly when the operator could open it, because the hub asks each page's
> own `canAccess()` rather than duplicating a permission that would drift.
>
> `App\Support\ReportCatalogue` is the registry and `ReportCatalogueConformanceTest` the gate:
> every admin page is catalogued or exempt-with-a-reason, and both languages must describe every
> report. The per-report navigation entries are unchanged — this adds a way in rather than moving
> what people already know.

> **The statements drill down (2026-08-12).** An account row on the income statement, balance sheet
> or trial balance opens the general ledger for that account, carrying the report's own year, month
> and property — the scope is the point, because landing on "this year, all properties" answers a
> different question from the one that was clicked. A ledger line then opens the DOCUMENT that
> caused it.
>
> Every piece was already in the database: `journal_entries.source_type/source_id` names the
> document and the statement rows already carried `account_id`. None of it was on a screen, so the
> numbers were correct and terminal.
>
> The source URL resolves through `Filament::getModelResource()` (`App\Support\SourceDocumentUrl`)
> rather than a hand-kept map, so a new posting source is linkable the day its resource exists.
> Every failure — no resource, no edit page, a record the operator may not view, a source since
> deleted — returns null and the column renders plain text: a dead link reads as a broken screen,
> a label reads as information.
>
> **The hazard it introduced:** `assetId` now arrives in a query string, which is exactly the shape
> that leaks another property's books. It is clamped to the operator's visible set in
> `ScopesLedgerReport::hydrateLedgerScopeFromQuery()`, with a paired control in
> `FinancialStatementDrilldownTest` proving the clamp is a filter and not a blanket refusal.

### Invoices & AR
- **Invoice balance** = `total - paid_amount - credit_applied_amount`. When balance ≤ 0, status → `paid`; when balance > 0 and paid_amount > 0, status → `partially_paid`.
- **Open invoices** for AR aging: status ∈ {`issued`, `partially_paid`, `overdue`} AND balance > 0.
- **Cancelled + draft invoices** are excluded from **all** monthly-close billed figures — `invoices.count`/`total`/`vat`, `revenue_by_type`, **and** the collections-rate denominator (a draft was never issued; a cancelled invoice was voided). They still appear in the `by_status` breakdown. *(Fixed 2026-07-27 — they used to inflate the billed headline while `revenue_by_type` excluded them, so the report contradicted itself.)*
- **VAT rate** standard 14%; invoice_items carry individual vat_rate (may vary; 0 for some line types).

### Payments & Collections
- **Only CAPTURED payments** count toward `monthlyClose()->payments[]`. Initiated/failed/authorized statuses ignored.
- **Payment allocation** via pivot `invoice_payment.allocated_amount`; a single payment can split across multiple invoices.
- **Receipt notification** fires once per payment when status=captured AND at least one invoice allocated, idempotent via `receipt_notified_at`.

### AR Aging
Buckets based on `invoice.due_date` vs. reference date (`asOf`), measured in **whole days overdue**:

```
daysOverdue = (int) due_date.startOfDay().diffInDays(asOf.startOfDay(), false)  // negative = not yet due
  daysOverdue <= 0    → 'current'               (not yet due)
  daysOverdue <= 30   → 'd_1_30'                (1–30 days)
  daysOverdue <= 60   → 'd_31_60'               (31–60 days)
  daysOverdue <= 90   → 'd_61_90'               (61–90 days)
  daysOverdue > 90    → 'd_90_plus'             (90+ days)
```

- **Floor to start-of-day on BOTH sides.** `due_date` is a date (midnight) but `asOf` carries a time (`monthlyClose` passes `endOfMonth()` = 23:59:59), so a raw `diffInDays` returns N.99… . *(Fixed 2026-07-27 — the summary used the raw float and over-aged every whole-day boundary by a bucket: a 30-days-overdue invoice showed as 31–60, one due today as 1–30. The drilldown already used `(int)`, so the two didn't reconcile — and the Reports page links each bucket total to that drilldown.)*
- **Summary (`arAgingBuckets`) and drilldown (`arAgingDrilldown`) use identical day-math + the same `issue_date <= asOf` inclusion cutoff**, so a bucket total always equals the sum of its drilldown rows. Guarded by `ReportAgingBoundaryTest`.
- **`monthlyClose()` ages at `min(month-end, today)`** and returns that day as `ar_aging_as_of`. Month-end of the month *being closed* is a future date; ageing to it declared invoices late that weren't due yet. *(Fixed 2026-07-28 — on the demo books the "1–30 days" card read 81 invoices / EGP 1.01m while the drill-down behind it listed 2 / EGP 71k, because the card aged at month-end and the drill-down re-aged at `now()`.)*
- **The ageing date travels with the drill-down.** `MonthlyCloseStats` puts `ar_aging_as_of` in each bucket link (`?bucket=…&asOf=YYYY-MM-DD`); the `ArAging` page ages at that date, shows it in the sub-heading and its own date picker, and puts it in the CSV filename. A bucket total and the worklist behind it can no longer describe different days. Guarded by `ReportsMonthlyCloseAgingTest`.
- **One bucket definition system-wide.** The dashboard `ArAging` chart calls `arAgingBuckets()` too — it used to carry a private copy comparing a midnight `due_date` to a `now()` with a time on it, so boundary invoices landed a bucket too far and the dashboard disagreed with the report.
- **Null due_date** treated as 0 days overdue (current).
- **Paid/zero-balance invoices** excluded entirely.
- **Bucket totals** = sum of `balance` (not total) for invoices in that bucket.
- **Outstanding_total** = sum of all bucket totals; must equal AR at close date.

### AR aging

> **The boundaries are configurable (2026-08-12).** `BillingSettings::ar_aging_bucket_days` — three
> ascending day-counts, 30/60/90 by default — read through `App\Support\AgingBuckets`. "Show me
> 45/90/120" was a deploy, and it is a real request: a mall whose leases pay quarterly ages nothing
> meaningfully at 30 days.
>
> **The keys are identifiers, not descriptions.** `d_1_30` stays `d_1_30` whatever the first
> boundary becomes — it is a URL parameter, a saved-view parameter, a colour lookup and a translation
> key in six places. The LABEL is derived, and reads "1–45 days" when the boundary is 45.
>
> **It also closed a duplication that could not have survived contact.** The ranges lived in
> `ReportService::AGING_BUCKETS` *and again as literals* inside `agingBucketKey()` — under a docblock
> saying the const "is not allowed to be copied". A mistyped set (out of order, non-positive, wrong
> length) clamps back to 30/60/90 rather than throwing: an ageing report must not stop rendering
> over a settings typo. See `ConfigurableAgingBucketsTest`. by charge type (RR-03)

`ReportService::arAgingByChargeType()` re-cuts the same open invoices `arAgingBuckets()` counts, by
what is owed rather than only by how late it is — so the grand total ties to the aging summary
exactly. Surfaced as the **Aging by charge type** page (`ArAgingByType`), CSV and EN/AR.

One aging total is ambiguous: "EGP 400k over 90 days" reads as delinquent rent and prompts a
collections call, when most of it may be a service charge the tenant has formally disputed. The
per-type split comes from `App\Support\InvoiceItemSettlement` (MF-06), which derives from
`invoices.paid_amount` — so the rows sum back to the invoice balances by construction, not by a
reconciliation somebody has to run.

### Monthly Close
- **Period** is month-to-month; `monthlyClose(CarbonImmutable $period)` defaults to current month.
- **Invoices included** if `issue_date BETWEEN period.startOfMonth() AND period.endOfMonth()`.
- **Payments included** if `payment_date BETWEEN period.startOfMonth() AND period.endOfMonth()` AND status=captured.
- **Collections rate** = (captured_payment_total / billable_total) * 100, where billable = the month's invoices **excluding cancelled + draft**; zero-guarded when billable_total=0. (Payments in the month may settle a *prior* month's invoice, so the rate can exceed 100% — it is a cash-flow ratio, not a per-invoice collection %.)
- **Credit notes** included if `issue_date` in period AND status ∈ {`issued`, `applied`}.
- **Revenue by type** aggregates `SUM(invoice_items.amount)` grouped by `type`, excluding cancelled/draft invoices.

### Scoping
- All queries via `TenantScope::applyTo(Query, 'lease.unit')` filter to `Asset::id = TenantScope::currentAssetId()` when a property is pinned.
- When no tenant is pinned (All Properties mode), queries return all properties (for super_admin) or assigned set.
- **Standalone credit notes** (no lease_id) visible across all properties; lease-linked credit notes scoped to their property.

### RBAC
- `reports.view`: grants access to Reports + ArAging pages (accounting, viewer, and roles with explicit grant).
- `reports.download`: gates PDF export of monthly close (viewer, accounting, manager, owner, super_admin).

## 4. Lifecycle / state machine

**No state machine per se**; reports are **read-only aggregations** of Invoice/Payment/CreditNote state.

| Trigger | Result |
|---------|--------|
| Invoice issued | Appears in monthly close billed totals; enters AR aging if balance > 0. |
| Payment captured | Appears in collections; allocated_amount reduces invoice.balance. |
| Invoice balance → 0 | Status → `paid`; invoice drops from AR aging. |
| Credit note issued/applied | Counts in monthly close.credit_notes; applied_amount reduces invoice.credit_applied_amount. |
| Period boundaries | Monthly close window is `[period.startOfMonth(), period.endOfMonth())`; earlier and later data excluded. |

## 5. Services, jobs & scheduled commands

### CSV export — `ReportCsvExporter` + `App\Support\ReportCsv`

> **The audit log was the one page that could be delivered and not downloaded** (fixed 2026-08-22).
> It implemented `DeliverableReport` and defined `reportCsv()` — so a saved view emailed itself on a
> schedule — and never spread `exportActions()`, so there was no button on the screen. The only one
> of the deliverable pages in that state.
>
> It does **not** take the trait's default gate. `ExportsReport::mayExport()` answers `reports.view`,
> which is right for the report pages and wrong here: this page is gated on `activity_log.view`, and
> `RolesPermissionsSeeder` withholds that key from `mall_admin` **because** the feed spans every
> property and has no `asset_id` to scope by. Inheriting the default would have made the export a
> second door into exactly the cross-property data the screen's own gate exists to withhold, so
> `ActivityLog::mayExport()` overrides to `canAccess()` — which also honours the module switch.

The financial reports were **PDF-only**, and the two an accountant most needs as raw data — the
**General Ledger** and **AR Aging** — had **no export at all**. A PDF only presents; an accountant
works in a spreadsheet (pivot, reconcile, hand to an auditor, import elsewhere). So every financial
report now exports to **CSV**:

- **`App\Support\ReportCsv::stream(filename, headers, rows)`** — the one streaming primitive. Prepends
  a **UTF-8 BOM** (Excel needs it to render Arabic), quotes via `fputcsv`, streams so a large GL never
  loads into memory.
- **`App\Services\Reports\ReportCsvExporter`** — flattens each computed report into `[headers, rows]`
  (kept out of the Filament pages so the row shape is unit-testable; a streamed response is not).
  Account names follow the locale; amounts are plain numbers (no separators/symbol) so a spreadsheet
  reads them as numbers. Methods: `trialBalance`, `incomeStatement`, `balanceSheet`, `cashFlow`,
  `generalLedger`, `arAging`. Statements carry per-section subtotals + a final net line, so the CSV
  reads exactly like the on-screen report; the trial balance carries a self-checking totals row.
  Since EG-28 they also carry the **chart's own group subtotals** — current vs non-current, operating
  revenue vs other income — from `App\Support\StatementGroups`, the same helper the screen and the
  PDF use. *"Reads exactly like the on-screen report"* is a claim that has to be kept true by
  construction: EG-36 shipped a screen out of step with its own export once, so the layout lives in
  one place rather than three. The cash-flow export passes `grouped: false`, because its sections are
  activities rather than branches of the chart. See
  [modules/21 → A statement is read by the chart's own subtotals](21-general-ledger.md#a-statement-is-read-by-the-charts-own-subtotals-eg-28-2026-08-22).
- **Wired as an "Export CSV" header action** on all six report pages (Trial Balance, Income Statement,
  Balance Sheet, Cash Flow, General Ledger, AR Aging), gated on `reports.view`. GL and AR aging gained
  export here for the first time.

### `ReportService` (app/Services/Reports/ReportService.php)

**Read-only query layer backed by PHPUnit tests, not spinning up a browser.** All methods scoped via `TenantScope::applyTo()`.

#### `monthlyClose(?CarbonImmutable $period = null): array`
**Signature:**
```php
public function monthlyClose(?CarbonImmutable $period = null): array
```
**Returns:**
```php
{
  period: '2026-02',
  period_label: 'February 2026' (localized),
  invoices: {
    count: int,
    total: float,
    vat: float,
    by_status: { 'issued' => {count, total}, 'paid' => {...}, ... }
  },
  payments: {
    count: int,
    total: float (captured only),
    by_method: { 'card' => float, 'cash' => float, ... }
  },
  ar_aging: (see arAgingBuckets()),
  outstanding_total: float,
  credit_notes: {
    count: int,
    total_issued: float,
    total_applied: float
  },
  revenue_by_type: { 'base_rent' => float, 'service_charge' => float, ... },
  collections_rate: float (percent, 0–100)
}
```
**Idempotency:** Fully read-only; no side effects. Safe to call repeatedly.

#### `arAgingBuckets(?CarbonImmutable $asOf = null): array`
**Signature:**
```php
public function arAgingBuckets(?CarbonImmutable $asOf = null): array
```
**Returns:**
```php
{
  'current': {count: int, total: float},
  'd_1_30': {count: int, total: float},
  'd_31_60': {count: int, total: float},
  'd_61_90': {count: int, total: float},
  'd_90_plus': {count: int, total: float}
}
```
**Details:** Calculates bucket membership based on due_date.diffInDays($asOf). Defaults to end-of-day today.

#### `arAgingDrilldown(string $bucket, ?CarbonImmutable $asOf = null): Collection`
**Signature:**
```php
public function arAgingDrilldown(string $bucket, ?CarbonImmutable $asOf = null): Collection
```
**Returns:** Collection of `Invoice` models in the specified bucket, sorted descending by balance, with `tenant` and `lease.unit` eager-loaded.
**Details:** Used by ArAging page to display invoices in a selected bucket.

#### `topDelinquentTenants(int $limit = 10): array`
**Signature:**
```php
public function topDelinquentTenants(int $limit = 10): array
```
**Returns:**
```php
[
  {
    tenant: Tenant,
    total_outstanding: float,
    days_overdue_avg: int,
    invoice_count: int
  },
  ...
]
```
**Details:** Ranks open invoices with past due_date by tenant; average days overdue, total outstanding, count. Useful for collections prioritization.

---

### `MonthlyCloseReportPdfService` (app/Services/Reports/MonthlyCloseReportPdfService.php)

**Generates PDF binary** via mPDF, rendering the monthly close report for the finance team.

#### `build(CarbonImmutable $period): string`
**Signature:**
```php
public function build(CarbonImmutable $period): string
```
**Returns:** PDF binary (starts with %PDF-).
**Details:**
- Calls `ReportService->monthlyClose($period)`.
- Renders `resources/views/reports/monthly-close.blade.php`.
- RTL-aware: switches font + directionality when locale is 'ar'.
- mPDF temp dir: `storage/app/mpdf`.
- Header names the **operator** via `App\Support\IssuingEntity`, not a literal — see below.
- The **tenant and asset statements** additionally print `seller_billing_email` in their footer,
  only when set; both previously carried a fabricated `billing@…​.test` address (EG-05). The
  monthly-close pack receives the key and does not print it — it is an internal document, so
  there is nobody to invite an enquiry from.

#### `filename(CarbonImmutable $period): string`
**Returns:** e.g., `atriom-monthly-close-2026-02.pdf`.

**Idempotency:** Fully deterministic; same period → identical PDF bytes each time.

---

### PDF sweep (2026-08-17) — two defects that spanned the report PDFs

**RTL-aware at the service layer was not RTL-correct at the template layer.** Every one of the twelve
PDF services already switched font and directionality for Arabic; the drift was entirely in the
markup, where nothing throws and the document still prints.

`resources/views/accounting/pdf/layout.blade.php` — the shared layout behind the **balance sheet,
income statement, trial balance and cash flow** — applied `letter-spacing: .04em` and
`text-transform: uppercase` *unconditionally* on its table headers. **Arabic is a cursive script:
letter-spacing pulls the glyphs apart and breaks the joins**, so the four documents the accountant
most reads in Arabic printed with disconnected letters. Every other template in the set guards both
on `$isRtl`; this layout predates that convention and was never revisited.

**Five of twelve documents printed "Atriom" — the software's name — where the issuing entity
belongs:** the owner statement, the payslip, the monthly-close pack, the facility work log, and the
four financial statements above. The fallback was spelled three different ways across the set
(`'Atriom'`, `config('app.name')`, and a bare literal), which is how it never read as a decision
anyone had made. It is now one call to `App\Support\IssuingEntity`, which resolves the operator's
registered name from `TaxSettings::seller_legal_name`.

The two questions the registry keeps apart, because the documents answer them differently:

- `tradingName($asset)` — the property leads, for a document a **counterparty** reads (invoice,
  receipt, credit note, tenant/property statement, CAM statement, purchase order). A tenant knows
  "Atriom Walk" and may never have heard the operator's registered name; the registered name goes
  underneath.
- `name()` / `legalName()` — the operator leads, for a document **about** a property rather than from
  one: an owner statement names its property in the party block already, a payslip's issuer is the
  employer, and the close pack and work log may span the whole portfolio.

Gate: `tests/Feature/Scenarios/PdfDocumentConformanceTest.php`. It derives the template list from the
PDF services (and follows `@extends`, which is where the RTL defect actually lived) rather than
hand-listing it, so a thirteenth document is covered the day it ships — and it asserts the discovery
found something first, because a sweep whose regex matches nothing passes every check after it.

---

### `AssetStatementPdfService` (app/Services/AssetStatementPdfService.php)

**Property-level statement for the Owner Portal.** Aggregates invoices/payments across all leases at a property for 12 trailing months.

> **Where it is reached (2026-08-18).** The **Property statement** row action on the Properties list,
> gated on `reports.download`. The `/owner` panel this was built for was removed when owners became
> admin users under RBAC, and the button went with it — leaving the service, its tests and its
> Blade view with **no caller in `app/` at all**, so the one document an owner asks for every month
> could not be produced from anywhere in the application.


#### `build(Asset $asset): string`
**Returns:** PDF binary.
**Details:**
- Trailing 12-month window (`asOf.subMonths(12).startOfMonth()` to now).
- Lists open invoices, recent invoices (last 12 months), payments, top 10 delinquent tenants by outstanding.
- Data shape property-level, not per-tenant.

#### `filename(Asset $asset): string`
**Returns:** e.g., `Property-Statement-HW-20260615.pdf`.

---

### `TenantStatementPdfService` (app/Services/TenantStatementPdfService.php)

**Tenant statement for tenant portal + API.** 12-month trailing invoices/payments for a single tenant.

#### `build(Tenant $tenant): string`
**Returns:** PDF binary.
**Details:**
- Trailing 12-month window (same as AssetStatementPdfService).
- All leases for the tenant; per-tenant view (not per-property).

#### `filename(Tenant $tenant): string`
**Returns:** e.g., `Statement-Acme-Corp-20260615.pdf`.

---

### `VatReturnService` (app/Services/Reports/VatReturnService.php)

**The VAT position for a period (الإقرار الضريبي).** Reports; files nothing.

#### `for(CarbonImmutable $start, CarbonImmutable $end, ?int $assetId = null): array`

> **The taxable base is split by the line's TAX CODE (2026-08-12), not by its rate.** It previously
> asked `vat_rate > 0`, which cannot tell a zero-rated supply (taxable at 0%) from an exempt one
> (outside the scope) — different lines on a filed return. `base_zero_rated` is now its own figure.
> Lines raised before `invoice_items.tax_code` existed carry no code and fall back to the old
> heuristic; they are counted in `unclassified_lines` and the count is shown in the page subheading,
> because "no zero-rated supplies this period" and "we cannot tell" are different answers and only
> one of them is safe to sign. Non-VAT families (stamp duty, schedule tax) are excluded from the
> base and from the output-VAT tie-out entirely — they are separate taxes with their own returns.

| Key | Source | Why from there |
|---|---|---|
| `output_vat` | **Ledger** — credit-side movement on `vat_payable` | The ledger is the single source of truth; a return derived from documents would be a second opinion about the same money, and the two agree right up until the month they don't. |
| `input_vat` | **Ledger** — debit-side movement on `vat_recoverable` | Read on its *own* normal side, so a refund posted the other way reduces it correctly. |
| `net_payable` | `output − input` | Negative is a real state (a month of heavy purchasing), not an error. |
| `output_vat_documents` | **Documents** — Σ invoice VAT − Σ credit-note VAT | The cross-check, from the other side of the system. |
| `ties_out` / `output_vat_difference` | the two compared | A mismatch means something is unposted or posted twice. |
| `base_standard` / `base_exempt` | **Documents**, split per LINE | The GL knows revenue by account, not by tax treatment — only the lines can answer which supplies were taxable. Base rent is exempt while service charge is not, and one invoice routinely carries both. |

Both ledger reads use `JournalEntry::REPORTABLE_STATUSES` — see the void-counting note in
[module 21](21-general-ledger.md).

**Credit notes reduce the supply, and until 2026-08-11 this service did not know they existed.**
The ledger side was already net of them (`CreditNoteJournalizer` debits `vat_payable`), so building
the documents side from invoices alone guaranteed `difference = −(credit-note VAT)`: `ties_out` was
**false in every period containing a VAT-bearing credit note**, and a control that cries wolf is one
the operator stops reading. `base_standard`/`base_exempt` never netted them either — and those are
figures that go on a filed return. Live rather than latent: three paths issue VAT-bearing credit
notes routinely (the CAM negative true-up at the pool's `recovery_vat_rate`, the move-out unearned
credit, and a manual note inheriting its invoice's rate). Pinned by `VatReturnCreditNotesTest`,
whose last case is an unposted invoice that must STILL report a discrepancy — netting must not be
achieved by relaxing the check.

---

**No scheduled commands or jobs** for reports module. All generation on-demand via Filament pages or API.

## 6. Filament resources & key fields

### `Reports` page (app/Filament/Admin/Pages/Reports.php)

**Route:** `/admin/reports`  
**Navigation:** "Accounting" group, sort 50.  
**Permissions:**
- `reports.view` (gated by module flag + permission).
- `reports.download` (gated separately for PDF button).

**View data:**
- `period`: YY-mm (query param, defaults to current month).
- `report`: output of `ReportService->monthlyClose($period)`.
- `recentPeriods`: dropdown of last 12 months.

**Key UI elements:**
- Period picker (Livewire live-binding to period).
- KPI grid: invoices issued, payments captured, collections rate, outstanding AR.
- AR aging buckets (5 cards); each links to the ArAging page with **`bucket` + `asOf`**.
- Revenue by type table.
- Download PDF button (gated on `reports.download`).

**Widget wiring — two traps, both hit at once (fixed 2026-07-28):**
- The cards are declared as a **`statsWidgets(Schema $schema): Schema`** rendered by
  `ledger-report.blade.php`, **not** `getHeaderWidgets()`. Filament's page component renders
  header widgets itself, above the page slot — registering them there *and* printing them in
  the view rendered every card **twice**, and would put the cards above the picker driving them.
- The selected period is published through **`getWidgetData()`**. It used to be spelled
  `getHeaderWidgetsData()`, which Filament 4 never calls: the revenue table (reading
  `$this->period` directly) followed the picker while the cards stayed pinned to the current
  month, so one page described two months. `MonthlyCloseStats::$period` is `#[Reactive]` —
  Livewire mounts a child once, so without it the cards freeze at first load.

**Methods:**
- `downloadMonthlyClose()`: builds PDF via `MonthlyCloseReportPdfService::build()`, streams with filename.

---

### `ArAging` page (app/Filament/Admin/Pages/ArAging.php)

**Route:** `/admin/ar-aging`  
**Navigation:** Hidden (reached via Reports page).  
**Permissions:** `reports.view` (same gate as Reports).

**Query params:**
- `bucket`: one of {current, d_1_30, d_31_60, d_61_90, d_90_plus} (defaults d_1_30).
- `asOf`: `Y-m-d` the receivables are aged at (defaults to today; junk falls back to today via
  `ArAging::parseAsOf()`). Set by the monthly-close card that was clicked so the drill-down
  lists exactly the invoices that card counted.

**View data:**
- `invoices`: result of `ReportService->arAgingDrilldown($bucket, $asOf)` sorted by balance desc.
- `bucket`, `buckets`, `asOf`, `totalBalance`.

**Key UI elements:**
- Bucket picker + **as-of date picker** (both Livewire live-binding).
- Sub-heading states the bucket total, the invoice count and the ageing date.
- Invoice table: number, tenant, unit, due_date, balance, days_overdue (measured against
  `asOf`, not `now()`), link to edit invoice.
- CSV export; the filename carries the as-of date (`ar-aging-{bucket}-{Y-m-d}.csv`).

---

### `VatReturn` page (app/Filament/Admin/Pages/VatReturn.php)

**Route:** `/admin/vat-return`
**Navigation:** "Accounting" group, sort 27.
**Permissions:** `reports.view` (via `ScopesLedgerReport::canViewReports()`).

- Period picker inherited from `ScopesLedgerReport` (the same control the other ledger reports use).
- **The tie-out is the subheading, not a row** — it is the point of the screen. `✓ ties out` or
  `✗ differs by X`, followed by the net payable.
- Six rows, each with the *why* as a column description, because a return is signed by someone who
  has to understand what they are signing.
- **No property filter.** `for()` takes one asset id, but a VAT return is filed per *registration*
  and the operator's registration covers the portfolio; offering a per-mall filter would invite
  someone to file a per-mall return, which is not a thing.
- **CSV only, no PDF** — deliberately. This is worked in a spreadsheet and handed to an accountant;
  a PDF would look like a filed document, which it is not.

> **The service shipped 2026-08-11 with zero callers** — no page, no route, no nav entry, no
> command — while its fifteen sibling report services all had a page, and `ROADMAP.md` recorded it
> as done. The one report Egypt requires *monthly* was the only one an operator could not open.
> Reachability is half of "shipped"; `VatReturnCreditNotesTest` now asserts the page is in the
> smoke manifest.

---

### `ArAging` widget (app/Filament/Admin/Widgets/ArAging.php)

**Filament widget** (not a page) displayed on Admin dashboard.

**Permissions:** Restricted to roles: manager, viewer (via `RoleScopedWidget` trait).

**Display:**
- Bar chart with 5 aging buckets (colors: green current → red 90+).
- Tooltip shows EGP amount + count of invoices.
- Buckets come from `ReportService::arAgingBuckets()` — the same call the monthly-close cards
  and the drill-down use. *(Fixed 2026-07-28: it used to run its own `due_date` comparisons
  against a timestamped `now()`, so an invoice due today, or exactly 30/60/90 days late, was
  pushed one bucket too far and the dashboard contradicted the report.)*

---

## 7. Notifications & integrations

**No direct outbound integrations** from the Reports module itself. However:

- **Payment received notification** fires when a payment is `captured` (see Payment model); tenant is notified via portal.
- **Owner overdue notification** (outside Reports) tracks `invoice.owner_overdue_notified_at` to notify owners of tenant overdue invoices.

**PDF generation:**
- Uses **mPDF** library for RTL (Arabic) rendering.
- Temp dir: `storage/app/mpdf` (created on-demand; no cleanup logic).

## 8. Extension points — how to change/extend SAFELY

### Add a new KPI to monthly close
1. Edit `ReportService::monthlyClose()` to calculate the KPI (e.g., `sum($invoices->where('status', 'disputed'))`).
2. Add the field to the return array (e.g., `'disputed' => [...]`).
3. Update the test in `tests/Feature/Scenarios/ReportScenarioTest.php` to assert the new field.
4. Add a `Stat` for it in `App\Filament\Admin\Widgets\MonthlyCloseStats::getStats()` — the KPI grid is a native Filament stats widget, not a Blade template.
5. Update PDF template `resources/views/reports/monthly-close.blade.php` to include it.
6. **Do NOT** break the existing return structure; new fields should be optional/backward-compatible if the return is consumed elsewhere (API, exports, etc.).

### Modify AR aging bucket boundaries
1. Edit the `match()` logic in `ReportService::arAgingBuckets()` (line 129–135).
2. Update the bucket array keys and comments.
3. Update tests in `ReportScenarioTest::test AR aging bucket boundaries` to reflect new cutoffs.
4. Update the bucket labels in `ArAging::buckets()` (the single list the page, its picker and `MonthlyCloseStats` all read) if the names change.
5. **Invariant to maintain:** `outstanding_total == sum of all bucket totals` (tested).

### Add filters to monthly close (e.g., by status, tenant, unit)
1. Add optional parameters to `ReportService::monthlyClose()` (e.g., `?array $statuses = null`).
2. Add `.where()` clauses to the invoice/payment queries before aggregation.
3. Update test(s) to verify filtered results.
4. Update the Reports page query params / form fields (Filament Select for statuses).
5. **Do NOT** break the existing unfiltered call; add a new method or make filters optional.

### Export to Excel or CSV
1. Create `ExportMonthlyCloseService` or similar in `app/Services/Reports/`.
2. Use `ReportService->monthlyClose()` to fetch data (no code duplication).
3. Format and stream via Symfony HttpFoundation Response or Laravel Excel.
4. Add action button to Reports page (Filament Action).
5. Gate on `reports.download` permission.

### Change scoping strategy (e.g., by unit, not by asset)
1. **Do NOT directly modify** `TenantScope` (used across the app).
2. Instead, add a **new scoping method** in ReportService (e.g., `private function scopedInvoicesForUnit(Unit $unit)`).
3. Update `monthlyClose()` to accept an optional Unit parameter.
4. Update tests to verify scoping works correctly.
5. **Invariant:** AR aging totals must still sum to outstanding_total per scope.

---

## 9. Gotchas, edge cases & recently-fixed bugs

### Null due_date
- Invoices **may have null due_date** (edge case from manual invoice entry or legacy data).
- AR aging treats null as 0 days overdue (current bucket).
- **Test:** `arAgingBuckets()` handles null gracefully; no crash.

### Double-notification on payment
- Payment receipt notification is idempotent via `receipt_notified_at` timestamp.
- Called from both `Payment::saved()` hook AND after Filament form save (when pivot is synced).
- **Gotcha:** If both occur in the same request, the second call sees the flag and returns early. Safe but watch for silent failures in logging.

### Three surfaces, one set of buckets *(fixed 2026-07-28)*
The same five ageing buckets are shown in three places — the dashboard chart, the
monthly-close KPI cards, and the AR-ageing drill-down. All three disagreed:
- The **dashboard chart** ran its own `where('due_date', '<', now()->subDays(X))` queries,
  comparing a midnight `due_date` against a `now()` that carries a time — every boundary
  invoice (due today, or exactly 30/60/90 days late) landed one bucket too far.
- The **cards** aged at month-end; the **drill-down** re-aged at `now()`. On the demo books
  the "1–30 days" card said 81 invoices / EGP 1.01m and opened onto 2 / EGP 71k.

Now: the chart calls `arAgingBuckets()`; `monthlyClose()` ages at `min(month-end, today)` and
publishes `ar_aging_as_of`; the cards pass that date to the drill-down in the link. **If you add
a fourth surface, call `ReportService` and carry the as-of date — don't re-derive the buckets.**
Guarded by `ReportsMonthlyCloseAgingTest` + `ReportAgingBoundaryTest`.

### Month boundary edge cases
- Invoice issued on 2026-02-28 (last day) **is included** in Feb close.
- Invoice issued on 2026-03-01 (first day) **is excluded** from Feb close.
- Test guards: `ReportScenarioTest::test monthly close month window`.

### Cancelled invoices in revenue_by_type
- Cancelled + draft invoices **excluded from `revenue_by_type`** aggregation.
- But they **are counted** in `invoices.count` and `invoices.total`.
- **Rationale:** revenue_by_type is operational revenue (excludes cancellations); total billed is accrual basis.
- **Test:** `test excludes cancelled + draft invoices from revenue_by_type but still counts them as billed`.

### Credit note balance calculation
- `credit_note.balance = total - applied_amount`.
- Applied amount increases as the note is used against invoices.
- A credit note is included in monthly close only if status ∈ {issued, applied}.
- **Gotcha:** A drafted credit note never appears, even if it has items.

### Collections rate zero-guard
- When `billed_total == 0`, `collections_rate = 0.0` (not NaN or division-by-zero error).
- **Test:** `test returns a zero collections_rate (no division-by-zero) when nothing was billed`.

### All Properties mode
- When no Filament tenant is pinned (All Properties), `TenantScope::currentAssetId()` returns null.
- `applyTo()` becomes a no-op; queries see all properties.
- For restricted users (non-super_admin) in All Properties, scoping still applies via `AssignedAssets::idsForCurrentUser()`.
- **Test:** `test sees BOTH properties when no tenant is pinned (All Properties / unscoped)`.

### Partial payments and balance tracking
- Invoice balance = `total - paid_amount - credit_applied_amount`.
- A partially paid invoice counts only its **balance** in AR aging (not its total).
- Example: invoice total 10,000, paid 6,000 → balance 4,000 counts in bucket.
- **Test:** `test counts only the open balance of a partially-paid invoice in its bucket`.

### VAT precision
- VAT amounts are stored as `decimal(12, 2)` with no rounding error.
- Monthly close sums VAT exactly: `SUM(invoices.vat_amount)`.
- Invoice items carry individual `vat_rate` (default 14%, may vary).
- **No reconciliation** between item vat (sum of item.vat_amount) and invoice.vat_amount; assume data entry is correct.

---

## 10. Tests & related modules

### Test files

- **`tests/Feature/ReportServiceTest.php`**: Unit tests for ReportService (monthly close aggregation, AR aging, revenue_by_type).
- **`tests/Feature/Services/MonthlyCloseReportPdfServiceTest.php`**: PDF generation (binary output, RTL rendering, filename format).
- **`tests/Feature/Scenarios/ReportScenarioTest.php`** (extensive): Monthly close figures, AR aging boundaries (every cutoff: 0/30/31/60/61/90/91 days), month windows, credit notes, collections rate, status breakdown, scoping (single property + All Properties), RBAC (reports.view + reports.download permissions).

### Related modules

- **[05-billing-invoices.md](./05-billing-invoices.md)**: Invoice domain, status lifecycle, numbering, VAT, period windows.
- **[06-payments.md](./06-payments.md)**: Payment domain, statuses (initiated/captured/failed), allocation to invoices, receipt notifications.
- **[07-credit-notes.md](./07-credit-notes.md)**: Credit note domain, reasons, application to invoices, balance tracking.
- **[04-leases.md](./04-leases.md)**: Lease domain (invoice.lease_id parent).
- **[02-tenants.md](./02-tenants.md)**: Tenant domain (invoice.tenant_id parent, statement generation).


## 11. Weekly spend — fixed vs variable (FR-FIN-02)

A management view of where the money goes week to week, split **fixed** (committed costs that land
whether the mall is busy or not — a security/cleaning contract, admin salaries/rent) vs **variable**
(spend that tracks activity — utility consumption, ad-hoc repairs, discretionary marketing).

- **The classification lives in `App\Support\CostNature`** — a single category→nature map read by
  `Expense::costNature()`, the `Expense` register's nature column + filter (`scopeOfNature`), and the
  report. `Expense` and `VendorBill` carry the **same** category set, so both are classified the same
  way and the register can never disagree with the report. Coarse by category on purpose (the FRD asks
  for a fixed/variable *report*, not per-line tagging); an unmapped category falls to `variable` (the
  conservative default — an unclassified cost isn't treated as committed). Adjust the map as the
  operator's contracts dictate.
- **`ReportService::weeklySpend(from, to)`** sums Expense (`expense_date`, `recorded`) + VendorBill
  (`bill_date`, not draft/cancelled) as the **ex-VAT** cost (input VAT is recoverable, not a cost),
  grouped by **ISO week (Mon–Sun)**. The range is pre-seeded so a spend-free week reads as zero rather
  than vanishing from the trend. Property-scoped via `TenantScope::applyTo` (direct `asset_id`), so it
  respects the selected mall — the first weekly period anywhere in the app (every other report is
  monthly / as-of). Surfaced on the `WeeklySpend` page (fixed/variable/total columns + summarised
  totals + CSV), reusing the shared `ledger-report` view.
- Pinned by `tests/Feature/WeeklySpendReportTest.php` (classification, the ex-VAT split across both
  sources with property-scoping + cancelled-exclusion, and the zero-seeding).

**Workflow visualization (FR-FIN-04)** — the `Workflows` page (Settings group) renders each state
machine's transitions read-only, driven straight off the `TRANSITIONS` matrices that *enforce* the
flows (`PurchaseRequest`, `FacilityWorkOrder`, `TenantRequest`), so it can never document a
transition the services don't allow. No domain change — a rendering of the single source of truth.

## 9. UI architecture — native Filament, no hand-written report markup

Every report surface in this module is a Filament **Page + Table**, not a Blade
template. The pages share one 12-line shell
(`resources/views/filament/pages/ledger-report.blade.php`) that renders three
things and nothing else:

```blade
{{ $this->filtersForm }}   {{-- native Schema: year / property / period / bucket --}}
<x-filament-widgets::widgets … />   {{-- header stats, where the page has them --}}
{{ $this->table }}
```

**Why it matters.** These pages previously carried ~700 lines of hand-written
`<table>` markup with inline styles and hard-coded hex colours. That markup did
not follow the panel theme (including each property's own `primary_color`), had
patchy dark-mode support, and gave an operator no sorting, no column control and
no drill-through beyond bespoke anchors.

**`records()`, not `query()`.** The trial balance, the three statements, the AR
ageing drill-down and monthly-close revenue are all AGGREGATES computed by a
report service, not row sets. They are fed to Filament through
`Table::records()`. Two consequences worth knowing before changing one:

- A per-group `Summarizer` has no query to aggregate. The financial statements
  therefore emit section totals as **real rows** (`is_total`), which is also how
  a printed statement reads. See `Concerns\RendersFinancialStatement`.
- A `Summarizer` on such a table must ignore its `$query` argument and read the
  figure off the report (`->using(fn (): float => $this->report()['total_debit'])`).
  This is deliberate: those totals are the tie-out the statement is judged on,
  so they come from the same array the PDF and CSV are built from.

**Filters stay bound to the page's own properties** (`$year`, `$assetId`,
`$period`, `$bucket`) rather than living in table-filter state, because the PDF
and CSV header actions read those properties. One piece of state means the
export can never describe a different period than the screen.

**Ordering carries meaning** on the general ledger (running balance) and the
statements (section order), so both are `paginated(false)` and unsorted by
design — re-ordering them would make each line's balance not follow from the one
above it.

Related: `tests/Feature/Pages/LedgerReportTablesTest.php` asserts each page's
rows and totals against the report service rather than merely that the page
renders.

---

## AR collections worklist

`/admin/ar-collections` ([`ArCollections`](../../app/Filament/Admin/Pages/ArCollections.php)) —
one row per tenant, outstanding split across every aging bucket at once, sorted **worst-first
(deepest bucket, then size)**, with invoice count, oldest item in days, last payment date (or a red
"never paid"), a statement download per row and a CSV export. Property-scoped, EN + AR.

It is the **collections** question — *who do I call, and about what* — as distinct from
[`ArAging`](../../app/Filament/Admin/Pages/ArAging.php), which is the accountant's *how much is
31–60 days late* and drills into one bucket.

### The one rule for anything that ages a receivable

**Bucket boundaries live in `ReportService::agingBucketKey()`, against the `AGING_BUCKETS`
register. Never re-derive them.** The arithmetic used to be copied between `arAgingBuckets()` and
`arAgingDrilldown()` with a comment asking the two to stay identical; the collections worklist
would have been a third copy. A bucket total that disagrees with the list behind it destroys the
operator's trust in both numbers, and the day-boundary cases (due today; exactly 30/60/90 days) are
where that disagreement hides — `ArCollectionsTest` pins all three views to the same answer on
exactly those days.

`ReportService::openInvoicesAsOf()` is likewise the single query every aging view starts from, so a
drill-down can never surface an invoice its own summary did not count.

---

## Lease expiration schedule

`/admin/expiration-schedule` ([`ExpirationSchedule`](../../app/Filament/Admin/Pages/ExpirationSchedule.php) +
`ReportService::expirationSchedule()`, story RR-02) — the rent roll says what the mall earns today;
this says **when that stops**.

Live leases bucketed by the year their term ends, each bucket carrying its lease count, area, annual
rent and — the number the question is really about — **its share of the mall's total area and
income**. A year with 30% of the income expiring is a year of negotiations that has to start
eighteen months earlier, and the only way to see one before this was to sort the lease table by end
date and add the rents up by hand. CSV exports the per-LEASE rows, not the four totals, because a
leasing manager exports this to work the list.

**Holdovers are their own bucket, sorted first.** A lease past its term but still trading has not
rolled off — its rent is live and its space is occupied — so counting it under a past year would
understate both this year's risk and today's income, and would bury the one row that needs a
decision today rather than in eighteen months.

## Sales & trading performance

`/admin/sales-analytics` ([`SalesAnalytics`](../../app/Filament/Admin/Pages/SalesAnalytics.php) +
`ReportService::salesAnalytics()`, story RR-05) — MTD, YTD, **MAT** (the trailing twelve months) and
growth against the same twelve months a year earlier.

**MAT is the number retail runs on.** A calendar-year figure says nothing useful in March and swings
around Ramadan and the school year; twelve months strips the seasonality out so two dates are
comparable.

**Two growth figures, deliberately.** Total MAT growth says how the centre's income is moving;
**like-for-like** says how the tenants who were already there are trading. A mall that let ten new
shops reads as growth on the first and flat on the second — and the gap between them is the story a
GM is actually looking for.

- **LFL counts only leases that declared in BOTH windows.** Without that exclusion a new anchor's
  whole turnover reads as "growth", which measures letting rather than trading. Both directions are
  pinned: the newcomer is excluded, and so is a departed tenant who would otherwise collapse the
  headline while saying nothing about the tenants still there.
- **The rule is *declared in both*, not *trading every month of both*.** Real declaration data has
  gaps, and the stricter rule would compute a mall-wide metric over a quarter of the mall while
  claiming to describe it. `lfl_leases` reports the count it used, so the basis is on the screen.
- **No prior year reads as UNKNOWN, never 0%** — zero would claim flat trading the data cannot
  support.
- Estimated declarations are flagged, the same as on the occupancy-cost report.

Only leases with percentage rent appear, because those are the ones that declare sales.

## Occupancy cost %

`/admin/occupancy-cost` ([`OccupancyCost`](../../app/Filament/Admin/Pages/OccupancyCost.php) +
`ReportService::occupancyCost()`, story RR-04) — **who is in trouble before they miss a payment**.

Total occupancy cost ÷ declared sales per tenant, rolling 12 months by default. A fashion tenant at
12% of turnover is healthy; one at 30% is failing and will usually stop paying before saying so.
Every input already existed — invoices and `TenantSalesDeclaration` — and the number was produced
nowhere. Thresholds are 20% amber / 25% red: **commonly cited retail rules of thumb, deliberately
not a setting**, because the healthy band differs by trade (food courts run high, anchors run low)
and putting them in a settings screen would imply a precision this does not have. Ask Eltizam for
their own bands before making them configurable.

Four rules that decide whether the number means anything:

- **Cost is what was BILLED, not what was paid.** Occupancy cost burdens the business whether or
  not the tenant has settled it; folding in payment behaviour would make a struggling tenant look
  *cheaper* the longer they went without paying.
- **Late fees and violation fines are excluded** (`ReportService::OCCUPANCY_COST_TYPES`). They are
  penalties for behaviour, and including them would say a tenant's occupancy is expensive because
  they paid late rather than because their rent is high — inverting the signal.
- **No declared sales reads as UNKNOWN, never 0%.** Zero would rank the tenant who files nothing as
  the healthiest in the mall, when a tenant who stops declaring is usually the one in trouble.
- **The portfolio headline is total cost ÷ total sales**, not the mean of the per-tenant ratios,
  which one tiny tenant could otherwise dominate.

Only leases with percentage rent appear, because those are the ones that declare sales at all.

## Rent roll

`/admin/rent-roll` ([`RentRoll`](../../app/Filament/Admin/Pages/RentRoll.php) +
`ReportService::rentRoll()`) — the single most-used report in commercial property, and Atriom had
no version of it.

One row per lease **as at a chosen date**: unit(s) · tenant · area · expiry and months left ·
**base rent in force on that date** · EGP/m²/yr · total monthly (rent + service + levy) · the next
contracted rent step · the next unresolved option deadline · deposit. Property-scoped, CSV export,
EN + AR, each row links to its lease.

**Why it could not exist before the charge schedule.** The rent used to be a single mutable number,
so a roll for last March would have reported *today's* rent and a roll for next year could not
exist at all. Every row now reads the schedule row in force on the as-of date through
**`ChargeScheduleService::pickInForce()` — the same selection the billing engine and the schedule
writer use.** A rent roll that decided "current rent" by its own rule would eventually disagree
with what actually bills, and an owner who catches that stops trusting both numbers; the test
suite therefore asserts the roll against the *invoice the engine produces for the same month*,
never against a hand-computed figure.

Two smaller rules worth keeping:

- **EGP/m²/yr is null, not zero, when the unit has no recorded area.** Unknown is not free, and a
  zero would quietly drag the portfolio rate down.
- **The headline rate is weighted by area**, so a 20 m² kiosk does not move the mall's number as
  hard as a 2,000 m² anchor.


## A schedule that says "every month" reports on a different month (SW-176, fixed 2026-09-02)

A `SavedReport` snapshots every declared parameter of its page, `DeliverSavedReportService`
re-applies them, and a report page derives its period from `now()` in `mount()`. So the frozen value
**overwrote the fresh one**: September's ageing was emailed to the owner's accountant in October, in
November, and every month after that.

**Nothing errors and the CSV arrives on time.** The only tell is that the numbers never move — the
failure a recipient notices last, if at all, and the recipients here are routinely outside the
business, invited precisely because they have no login and therefore no other way to check.

The period is **REWRITTEN in its own shape** — `App\Support\ReportPeriod::advance()`.

**Dropping it was the first repair and it was worse than the bug for the seven ledger reports.** A
null `period` does not mean "this month" on `ScopesLedgerReport`; it means *the whole fiscal year*.
Measured: a monthly VAT return saved for March delivered as `vat-return-2026.csv` carrying the
year's cumulative `net_payable` — on a document Egypt files monthly, whose CSV rows carry no period
line at all, so the filename is the only statement of the window. A stale return is the wrong month;
that is the wrong **amount**, and it looks fresh, which is what makes it likelier to be filed. Form
41 went from a quarter to a year, and the balance sheet's *as at* became 31 December — a future date
on every delivery until December.

| shape | advance |
|---|---|
| `asOf` | today. A point has no length to preserve. |
| `from` + `to` | the same **span**, ending today. Dropping them reset a one-quarter vendor scorecard to the page's hardcoded rolling twelve months — four times the volume. |
| `year` + `period`, month-shaped | the month just **ended** — what a monthly statutory return is filed for, and the one thing no page's `mount()` can produce. |
| `year` + `period`, quarter-shaped | the quarter just ended. |
| `year` + `period`, null | the current year. Null **is** a shape. |
| anything else | left exactly as saved. Better a stale period a recipient can spot than a confidently rewritten one in a shape nobody parsed. |

**Every other saved parameter is kept**, because it is the operator's SHAPE rather than their moment:
the ageing bucket, the ledger account, whether to include zero balances, the comparison basis. **And
the browser is untouched** — opening a saved view still re-applies its period exactly as saved,
because a link is a moment and a schedule is a cadence.

`ReportCatalogue::REPORTING_PERIOD` says which parameters those are, with
`NO_REPORTING_PERIOD` naming the three that have none and why — the same two-camps-and-a-gate shape
as `NOT_DELIVERABLE`, so the next deliverable report cannot ship undecided and a schedule for it
cannot silently freeze. The gate also checks each named parameter still EXISTS on its page: a renamed
one stops being dropped, which is the original bug back with a green build.

`RevenueForecast::$horizon` is deliberately absent — it is a SPAN ("the next twelve months"), already
relative to whenever the report runs, so rewriting it would change the question rather than the
period.

Tests: `AScheduledReportMovesWithTheCalendarTest` — end to end through the real service and mail (the
as-at date is in the CSV filename, so the attachment says which date was used), the parameter split,
the fresh mount, the browser control, and the registry gate.

---

## Sweep fixes — 2026-09-04

*Designed by the patch fleet, adversarially reviewed, then applied and tested one at a
time. Each row's full claim and evidence is in [docs/qa/DEEP-SWEEP-2026-09-01.md](../qa/DEEP-SWEEP-2026-09-01.md).*


### SW-096 + SW-104 (one defect, one patch)

append to the **Workflow visualization (FR-FIN-04)** paragraph (currently at line 921, immediately before `## 9. UI architecture`):

**Two corrections, 2026-09-03 (SW-096 / SW-104).** The page held its own private opinion of two things and was wrong about both. It printed each status as `ucwords(str_replace('_', ' ', $state))` — the raw database value in English typography — so an operator working the Arabic panel read `Awaiting Tenant`, `In Progress` and `Ordered`, while `admin.statuses.tenant_request`, `admin.facility.statuses` and `admin.procurement.statuses` between them already name all eighteen of these states in both languages and are exactly what the request board, the work-order list and the purchase-request list render. And it gated the WHOLE page on `Modules::enabled('approvals')`, which owns the value-threshold approval ladder (`approval_rules`) and none of the three state machines drawn here — so switching that ladder off took the tenant-request and work-order maps down with it, while switching `procurement` off left its purchase-request map on the page describing a module the install no longer runs; the permission list also omitted `facility.view` although one of the three IS the work-order machine. `Workflows::WORKFLOWS` is now one registry — module, permission, status catalogue, transition matrix — read by both the gate and the rows, so the two cannot disagree again. The permission is a UNION and the rows are deliberately NOT narrowed by it (the page holds no records, only the matrices, so a technician reading the procurement ladder learns nothing they may not know); the rows are narrowed by the MODULE, because mapping a workflow the operator switched off describes something this install does not do. Pinned by `AWorkflowMapReadsInTheOperatorsLanguageTest`, mutation-proved four ways.


### SW-117

append at the end (house convention, after `## A schedule that says "every month" reports on a different month`):

## The income statement named the mall it was NOT reporting (SW-117, fixed 2026-09-04)

`ScopesLedgerReport::hydrateLedgerScopeFromQuery()` restores this operator's standing preferences and then PINS `$assetId` to the mall they are standing in, deliberately as the last word — that ordering is the whole of `PropertyField::reportScope()`, whose own docblock says the alternative is a caption naming one mall over another mall's rows, "the more dangerous of the two failures: nobody re-checks a number they believe they asked for". `IncomeStatement::mount()` then called `ReportPreferences::restore()` a **second** time after that hydrate, to pick up `comparison` and `spread` (which it parses from the query string once the hydrate has run) — and `assetId` is a restored parameter too. Measured at HEAD 2026-09-04: `ReportParameters::parametersOf(IncomeStatement::class)` = comparison, spread, year, period, assetId, and `ReportPreferences::VOLATILE` names only year and period of those, so `assetId` is both remembered and restored. Standing in mall B with mall A remembered, the pinned disabled picker read "A" while `TenantScope::reportAssetIds()` clamped the figures back to B. **Not a leak** — with a real tenant selected `visibleAssetIds()` is `[currentId]` for everyone, so the clamp holds and the PDF header, which derives from the clamped set, was right the whole time; what was wrong is the only thing the operator reads. The fix parses this page's own two parameters BEFORE the shared hydrate, so the trait's single restore is the effective one and the pin stays last; the query string still wins, because `restore()` skips any key `request()->query` names. It was the only page with the shape — `ArAging` declares no `assetId`, `MapsOneProperty` returns before its restore whenever a tenant is selected — and a gate now requires the six pages using the bar to leave the restore to it. (`TheIncomeStatementReportsTheMallItNamesTest`, mutation-proved.)

### SW-182

append:

### An exported statement foots, and says whether it balances (SW-182)

The trial balance, the balance sheet and the cash-flow statement each rest on one assertion — debits
equal credits, assets equal liabilities plus equity plus net income, the statement ties to the actual
movement in cash — and each screen leads its subheading with the answer so it cannot be scrolled
past. Both PDFs print it too. **No export carried it**, and the balance-sheet export also dropped
`total_equity_and_liabilities`: measured 2026-09-04 against `mall_management_qa`, the exported file's
last row was `,,Net income,…`, three section subtotals and a net line with nothing to foot against
`Total assets`. That file is the copy that goes to an owner, an accountant or an auditor — the one
reader who cannot open the ledger to settle it — so a sheet that does not balance was
indistinguishable from one that does, on the only copy where it mattered most.

`App\Support\StatementIntegrity` is the ONE wording, read by the three page subheadings, the two PDF
templates and the three exports. It is a class rather than a sixth copy of the ternary: the balanced
sentence was already written out four times, and three exports would have made seven. Same shape and
same reason as `UnallocatedNotice`, the other sentence three renderers of one statement have to agree
about — which drifted once and sent an income statement out of the building quoting 134,300 while the
screen said 84,300. It exposes two methods rather than one taking a pair of keys, because
`balanced`/`not_balanced` are LABELS their renderers mark with ✓/✗ while the cash-flow strings are
whole sentences carrying their own mark; folding them together prints `✓ ✓ Reconciles…`.

**Still open, and stated rather than left to be re-found:** the cash-flow export omits `cash_opening`
and `cash_closing`, which the screen prints as its fourth section and the PDF as its closing table.
Adding them means `sectioned()` growing a footer block — a layout change, where this was a one-line
omission.

Tests: `AnExportedStatementSaysWhetherItBalancesTest` — each check paired with the opposite case, so
a wording that always answered ✓ cannot pass.


### SW-184

append:

### A report range typed backwards is read in order, not answered with EGP 0.00 (SW-184)

`ReportFilters::from()` and `::to()` were plain date pickers with no bound on each other, and the
three reports that take them — WeeklySpend, Occupancy cost, Vendor scorecard — all degrade silently
when the pair is inverted. Measured at HEAD 2026-09-04: with `from` after `to`,
`ReportService::weeklySpend()`'s week-seeding cursor never runs and both `whereBetween` clauses match
nothing, so `WeeklySpend::getSubheading()` renders "EGP 0.00 · EGP 0.00 · EGP 0.00" — an empty table
under three figures that read as a finding — and the export and the scheduled email carry the same.
Occupancy cost degrades to zero cost, zero sales and a null ratio for every tenant, which on that
screen is indistinguishable from a mall where nobody has declared.

Two layers. The pickers now **bound each other** (`maxDate` on `from`, `minDate` on `to`), so the
panel cannot state an impossible window — the visible half. `ReportPeriod::orderedSpan()` is the
guarantee, at the chokepoint a saved view, a `?from=` in the URL and a scheduled delivery all pass
through; it lives beside `advanceSpan()`, which has always refused to move a span when
`$from->greaterThan($to)`, so the class about report periods already knew an inverted one is not a
question. It **swaps** rather than refusing: the same two dates read the way every date-range control
reads them, which is what the operator meant, and it decides which rows are read and never what they
are worth. **A half-stated window is left alone** — no order to fix, and each report's own default is
a better answer than half of one. `weeklySpend()` orders BEFORE normalising to ISO week boundaries,
or a swap would leave the start on a Sunday and the cursor stepping whole weeks off-boundary.

Tests: `AnInvertedReportRangeIsReadInOrderTest` — every inverted call paired with the window as meant,
so a fix that emptied both would not pass.

