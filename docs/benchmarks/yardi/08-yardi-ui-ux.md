# 08 — UI/UX: what to copy from Yardi, and what not to

> The ask: *"same Yardi UI/UX as possible so the user uses the system easily with all features."*
> This file answers it — but it starts by disagreeing with half of it, for a reason backed by
> Yardi's own users.

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

### UX-01 🔴 The Lease hub page
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

### UX-02 🔴 The rent-schedule grid
The visual counterpart to LS-01, and the screen that makes the schedule model *legible*: a timeline
strip plus a table. It must make three things obvious at a glance — **which row is billing now**,
**what changes next and when**, and **where a gap or overlap exists** (refused at write time, but
show it if legacy data has one). Inline add/close-and-open-next, never a raw amount edit.

---

### UX-03 🔴 The AR / collections dashboard
Voyager 8's A/R and receivables dashboards, done once: aging buckets → drill to invoices → drill to
the tenant. Grouped by **charge type once MF-06 lands** (RR-03). Actions inline: send statement,
apply credit, record payment, flag disputed.

**Today:** `ArAging` exists as a page — this is an upgrade, not a new build.

---

### UX-04 🔴 The Month-End Close dashboard
A checklist that knows its own state, in the order from
[02 §9](02-yardi-money-flow.md#9-month-end-close): billing posted · recoveries/percentage rent
posted · receipts entered · AP posted · automated journals posted · GL tie-out clean · period
closed. Each row shows ✅/⚠️, the count outstanding, and a link to the thing to fix.

**Everything it needs already exists** — `AccountingPeriod`, the monthly-close PDF, the books
tie-out, `PostingDateGuards`. This is assembly, and it is what turns a close from tribal knowledge
into a screen.

---

### UX-05 🔴 Billing run preview (batch before commit)
A dry-run of `MonthlyBillingService::runForPeriod()` rendering the proposed invoices — lease, period,
lines, total — with the skip **reasons** shown (`fit_out`, `off_cycle`, `already_billed`,
`no_applicable_charges`, which the service already returns). The operator reviews, then commits.

**This is the single highest-confidence UI addition in the list**: it is the control that stops a
bad escalation reaching 400 tenants, and the service already returns everything it needs.

---

### UX-06 🔴 Rent roll & lease expiry screens *(the UI half of RR-01/RR-02)*
Rent roll as at a date, with column sort/filter, per-m² columns, totals, and CSV + PDF. Expiry
schedule as a by-year view with area and rent at risk. Both property-scoped, both EN + AR.

---

### UX-07 🟠 Tenant 360
Customer-level rather than lease-level: every lease this tenant holds (current and past), total
exposure, AR, sales trend, occupancy cost %, requests, violations, documents. Yardi's
customer-vs-lease split exists precisely so this view can exist.

---

### UX-08 🟠 CAM reconciliation workbench
One screen for the whole [S11](04-scenarios.md#s11--cam-year-end-reconciliation) flow: pool total →
(later: the GL accounts behind it) → denominator + basis → the allocation table with each tenant's
area, share, cap applied, admin fee, estimate billed, true-up → bill/credit actions → **the
re-estimate proposal** (RC-05). Plus the tenant-facing statement (RC-06) generated from the same
screen.

---

### UX-09 🟠 Critical-dates work-list
One list answering "what needs action in the next 90 days": option notice windows opening/closing,
lease expiries, insurance expiries, contract renewals. Grouped by urgency, each with the action that
resolves it. The nav badge points here.

---

### UX-10 🟠 Global search reaching actions and pages
Extend module 34's provider so typing "rent roll", "close period" or "run billing" navigates, not
just record lookups. Keep the existing rule absolutely: authz + property isolation come from
`getEloquentQuery()`, never re-implemented in the provider.

---

### UX-11 🟡 Saved views
Let a user save a table's filter+column state and set one as their default. The accountant's monthly
pack becomes one click.

---

### UX-13 🔴 Tabbed forms for every multi-concern resource *(operator directive, 2026-08-08)*
**As an** operator **I want** a long resource form split into tabs, each tab one group of related
settings **so that** I am looking at one concern at a time instead of scrolling thirty fields.

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
| `TenantRequestForm` | 24 | 6 |
| `InvoiceForm` | 19 | 4 |
| `TenantForm` | 18 | 4 |
| `CreditNoteForm` | 18 | 4 |
| `VendorBillForm` | 15 | 2 |
| `MaintenancePlanForm` | 15 | 0 |
| `PaymentForm` | 14 | 4 |

Below ~12 fields a tab strip costs more than it saves — leave those as sections.

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
| UX-05 billing preview · UX-03 AR dashboard · UX-04 month-end close | **now** — the data already exists |
| UX-01 lease hub · UX-02 rent schedule grid | phase 1 (charge schedule) |
| UX-09 critical dates | phase 3 (options) |
| UX-06 rent roll / expiry | phase 7 (reports) |
| UX-08 CAM workbench | phase 6 (recoveries) |
| UX-07 tenant 360 · UX-10 search · UX-11 saved views · UX-12 drill-down audit | opportunistic |

**Start with UX-05, UX-03 and UX-04.** All three are assembly over data that already exists, none
of them waits on the schedule rebuild, and each removes a class of operator anxiety on its own.

---

## Sources

- [Yardi Voyager 8 — what's new](https://www.redirectconsulting.com/blog/whats-new-in-yardi-voyager-8-features-ai-and-upgrade-best-practices) and [2026 guide](https://www.redirectconsulting.com/blog/yardi-voyager-8-2026-guide-features-ai-capabilities-operational-impact) — refreshed UI, dashboard-centric shift, contextual navigation, Report Builder
- [Introducing Voyager 8 — BC Solutions](https://www.bcsolut.com/post/voyager8) — A/R dashboard, Month-End dashboard, receivables dashboard
- [Voyager User Interface Overview (Core User's Guide)](https://yardi.westcliff-group.com/voyager/Help/Core%20User's%20Guide/Voyager%20User%20Interface%20Overview.html) — side menu, lookup lists
- [Yardi Voyager reviews — SelectHub](https://www.selecthub.com/p/property-management-software/yardi-voyager/) — the 77% usability figure
- [Yardi Voyager reviews — Capterra](https://www.capterra.com/p/33832/Yardi-Voyager/reviews/) · [G2 pros & cons](https://www.g2.com/products/yardi-voyager/reviews?qs=pros-and-cons) — "clunky", "early 90s interface", excess clicks
- [Re-Leased vs Yardi Voyager](https://www.selecthub.com/property-management-software/yardi-voyager-vs-re-leased/) · [G2 comparison](https://www.g2.com/compare/re-leased-vs-yardi-voyager) — the usability head-to-head
