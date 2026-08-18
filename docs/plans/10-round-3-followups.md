# Plan 10 — what round 3 left, and what to do about it

> **Written 2026-08-18**, at the close of round 3 (all 37 modules gap-analysed). This is the
> follow-up list: everything found and *not* fixed, plus what the benchmarks say should exist that
> does not. Priorities use [ROADMAP](../ROADMAP.md)'s conventions — 🔴 P0 blocks go-live · 🟠 P1
> matters in the first weeks · 🟡 P2 later; owner 🧑‍💻 code · 🔑 external decision · ⚙️ ops.

## 0. How this plan is grounded

Every row below is either **cited to the repo's own benchmark material** —
[competitors/](../gap-analysis/competitors/) (Yardi Voyager · Re-Leased · AppFolio · Facilio ·
ServiceChannel · Maximo) and [benchmarks/yardi/](../benchmarks/yardi/) — or **explicitly marked as
having no benchmark**. Nothing here is a requirement invented from general intuition.

**Two cautions carried forward from round 3.** The *Atriom* column of `competitors/03` and `/06` is
**stale** — both rank as top gaps things that have since been built — so only the *competitor*
columns are used as the yardstick and the Atriom side is re-derived from code. And **post-dated
cheques have no benchmark at all**: they are a MENA instrument the Western tools do not model, so
module 33's rows below are judged against the code and Egyptian practice, and say so.

## 1. Headline: the benchmark's ranked gaps are nearly exhausted

Of the ten gaps the two relevant competitor analyses ranked, **eight are now built** — four of them
during round 3.

| Benchmark gap (as ranked) | State |
|---|---|
| 03 #1 Owner statements + disbursements *(L)* | ✅ built (module 32) |
| 03 #2 Post-dated cheque register *(M)* | ✅ built (module 33) |
| 03 #3 Utility tariff / recharge automation *(M)* | ✅ built — `BillMeterReadingService` bridges reading → charge |
| 03 #4 Deposit balance + reconciliation + itemised refund *(S)* | ✅ built — held/agreed/shortfall + GL tie-out on the register |
| 03 #5 Live, richer tenant payment rail *(M)* | 🔑 **blocked** — Paymob certification, not code |
| 06 #1 Vendor COI / compliance-doc tracking *(M)* | ✅ built — `VendorDocument` + `isDispatchable()` dispatch guard |
| 06 #2 Fit-out permit approval workflow *(M)* | 🟡 **mostly built** — see §3.1 |
| 06 #3 Vendor scorecards *(M)* | ✅ built in round 3 — the service existed, the screen did not |
| 06 #4 Bill the violation fine *(S–M)* | ✅ built, and extended to owner-occupiers in round 3 |
| 06 #5 Vendor self-service portal *(L)* | ⬜ **open** — see §3.2 |

**So the remaining work is not parity.** It is two product decisions, one external blocker, one
sizeable new surface, and hygiene.

## 2. 🟠 P1 — decisions that block real work

These are small in code and blocked on a call only the operator can make. **Each is one decision,
not a project.**

### 2.1 🔑 Which properties are public? *(module 36)*

`GET /api/v1/public/malls` returns every active property. There is no `is_publicly_listed` column, so
withholding one means deactivating it — which breaks every operational screen.

**Benchmark:** none directly; the competitor docs do not cover a shopper feed. The *internal*
precedent is the argument: a **store** can be withheld (`tenants.is_listed`), and module 36 §9.5
deliberately prevents mapping a retail chain across the portfolio from a public URL. The same
instinct, one level up, is missing.

**Decision required:** default listed (nothing changes for anyone) or default unlisted (the feed
empties until each property is opted in). **Then:** column + migration + a toggle on the property
form + the filter in `ListPublicMallsController` and `resolveMall()`.
**UI/UX:** a toggle in the property's "Public presence" area, EN+AR, with helper text stating the
consequence — *"Shoppers can find this mall in the app"* — because a switch that publishes something
must say what it publishes. **Effort: S.**

### 2.2 🔑 Can an owner-occupier rent a parking bay? *(module 35)*

`lease_rentable_item` is lease-keyed, so a unit owner cannot hold a bay. **Not the same bug** as
round 3's lease-shaped defects: those inferred a lease where the money core already took a
`BillableAgreement`, so the fix was a lookup. Here the *relationship* is lease-shaped.

**Benchmark:** [09-yardi-space-and-parking](../benchmarks/yardi/09-yardi-space-and-parking.md) treats
parking as licensable to a party, not strictly to a lease.
**Decision required:** whether an owner's bay charge rides his monthly assessment run.
**Then:** a polymorphic holder (or a second pivot column) + the assign service taking a
`BillableAgreement` like every other money path. **Effort: M** (migration + backfill + service).
**UI/UX:** the assign action moves from the lease page to *both* holders; the picker must show which
agreement holds a bay so the register stays readable.

### 2.3 🔑 The management fee *(modules 32 + 37)*

Configurable per ownership, described on screen, charged by nothing. **Blocked on two accountant
answers** — which GL account takes management-fee income (it is Eltizam's revenue, not the
property's, so a guess puts the operator's income in the owner's P&L), and the sinking-fund
liability account. Recorded in [OPEN-QUESTIONS §B2.1/B2.2](../OPEN-QUESTIONS.md).

**Benchmark:** competitors/03 — an owner statement is *income − expenses − management fee = net*;
Yardi Investment Manager and AppFolio both compute the fee. **Atriom is the only one that does not.**
**Interim (done):** the field now says it is recorded and not billed, pinned by a characterisation
test that fails the day the fee is built. **Effort once unblocked: M.**

## 3. 🟡 P2 — benchmark parity, in priority order

### 3.1 Fit-out permit: conditions, not just a decision *(benchmark 06 #2, M)*

More built than the benchmark row suggests: a permit is a typed request with a **validity window**
and, since 2026-08-15, a recorded **approve/reject decision with a mandatory rejection reason**.

What the benchmark describes and Atriom lacks: **conditions on the grant** — permitted working
hours, a security deposit, contractor details — and an audit trail of the permit itself rather than
of the request carrying it.

> **Fix now, one line:** `TenantRequestForm` still comments *"NO approval step"*, which stopped being
> true on 2026-08-15. Stale prose of exactly the class that cost a wrong answer twice in round 3.

**UI/UX:** conditions belong on the *decision* modal, not the intake form — they are what the
operator grants, not what the tenant asks. The tenant must see them in the portal and on the mobile
permit card, or a condition nobody reads is a condition nobody keeps.

### 3.2 Vendor self-service portal *(benchmark 06 #5, L)*

Vendors have no surface: no accept/quote loop, no document upload, no ETA posting. Dispatch is an
internal column change. ServiceChannel and Facilio treat this as core.

**Note the existing decision, do not re-litigate it:** the FRD's vendor CSV upload was deliberately
*not* granted via `imports.execute`, because that is the admin import right and handing it to an
external party widens a tightly-held gate. A vendor surface needs **its own permission set**.
**UI/UX:** a fourth auth surface (`/vendor`), so it inherits the full checklist — screen guides,
EN+AR, `EntitySelect` pickers, actions gated in both `visible()` and `action()`.

### 3.3 Contractor permit-to-work / safety permits *(benchmark 06 row, absent)*

No hot-work or isolation permit concept. A mall operator is legally exposed here. **Effort: M**,
and it composes with §3.1 — both are "a permission to do physical work, with conditions".

## 4. 🟡 P2 — hygiene the round argues for

| Item | Why | Effort |
|---|---|---|
| **A workability gate** | The reachability gate proves a service *can* be started; the NSF fee proved that is not the same as it *working* — it was reachable and refused every real input. **Prototyped 2026-08-18 and NOT shipped — see §4.1** | M, blocked on a parser |
| **PDC series exhaustion** | No alert when a tenant's lodged cheques run out before the lease term. No benchmark (see §0); Egyptian practice is a year lodged up front, so running dry mid-term is the normal failure | S |
| **Repeat-violation ladder** | Fines are priced by hand; the register makes the pattern visible and nothing uses it. No benchmark row | S |
| **Search `LIKE` at scale** | Every blob search is a leading-wildcard `LIKE` — correct, unindexable, unmeasured at real row counts | S to measure first |
| **Bounce-after-clearing** | Modelled as impossible; a bank can return a cheque after provisional credit. The remedy (void the payment) is documented and honest | S |

### 4.1 Why the workability gate is not in this branch

Two shapes were prototyped against the real tree, and the result is worth recording so nobody
re-runs it.

**Shape A — "a fixture writes a column no `app/` code writes."** Scoped to the eight money models it
returned **3 hits and no false positives**, and all three were real dead keys (below). Scoped
repo-wide it is unreliable for a different reason: plenty of production writes are property
assignments (`$model->col = …`) rather than array keys, so the "app never writes it" half needs to
understand PHP, not match text.

**Shape B — "a fixture writes a column that does not exist."** Sharper and zero-ambiguity in
principle. In practice a regex cannot delimit a `Model::create([...])` block: the non-greedy match
runs past the closing bracket and swallows the next call, so keys get attributed to the wrong model.
It reported **820 "ghosts", essentially all of them mis-attribution** — the tool was wrong, not the
codebase.

**What it needs:** `nikic/php-parser` (already a transitive dependency) to walk real AST nodes —
find `StaticCall` to `create` on a model class, read the `Array_` argument's keys, compare against
`Schema::getColumnListing()`. That is a genuinely useful gate and a small build **once parsed rather
than matched**. Shipping the regex version would have been worse than nothing: a gate that names the
wrong file teaches people to ignore gates.

**What the prototype did earn**, fixed here: `PurchaseInputTaxCodeTest` set `bill_number` three
times — not a column (`VendorBill` auto-allocates `number` via `AllocatesDocumentNumber`), so it was
silently dropped. And four fixtures written during round 3 — mine — set `tax_amount` on invoices and
credit notes, where the column is `vat_amount`. Harmless in effect, wrong as a record of intent, and
exactly the class the gate exists to catch.

## 5. ⚙️ Not mine, but blocking the build

**`origin/main` is red on four conformance gates** — morph map, change impact, report catalogue, E2E
manifest — all arriving with `bb91194a`. Verified on `origin/main` alone. Three need decisions from
whoever wrote that code; `ChangeImpact` in particular is a judgement about whether a new field may
move posted books, and guessing is worse than leaving it red. **The manifest one is a regeneration.**

## 6. UI/UX — the thread through all of it

Round 3's defects were not, in the main, wrong arithmetic. They were **capabilities with no surface,
and surfaces that lied**:

- unit owners billed by nothing, resale recordable nowhere, a scorecard with no screen, a statement
  whose button left with a deleted panel — *four services fully built and unusable*
- a fee field reading *"our cut of what we collect"* while nothing collected it
- an owner's credit note absent from its register, their assessments absent from their own tab, an
  owner in arrears not flagged delinquent — **failures that render as empty space**, which nobody
  reports, because there is nothing on screen to report

**The rule this yields:** a finding is not closed until an operator can *see* it working. Every item
in this plan therefore carries the standing checklist —

1. **Reachable** — a screen, action or schedule reaches it; the reachability gate now enforces this
2. **Both languages**, at parity, with `Lang::has(..., fallback: false)`
3. **A screen guide**, registered *and mounted* (`ScreenGuideConformanceTest` checks both)
4. **Field help ≤ 18 words**, in the right home — constraint visible, rationale on hover
5. **`EntitySelect`, never `Select`**, for anything picking a record
6. **Gated in `visible()` *and* `action()`**, with the predicate named once
7. **Refusals render as a toast**, not an error page
8. **Empty states that say what to do**, since absence is how these defects present

And one addition round 3 earned: **when a capability is deferred, the screen must say so.** A field
that describes a charge nobody makes is worse than a missing field — the operator configures it,
believes it, and finds out at reconciliation.

## 7. Deliberately not on this plan

- **Waterfall / preferred-return distributions** — Yardi Investment Manager's deeper tier; out of
  scope for a single-owner-per-mall operator. Revisit only if Eltizam takes co-investors.
- **Cash-basis owner reporting** — a real receipts-and-payments build, deferred by design with its
  trigger recorded; not a flag.
- **Zone-based auto-assignment** — areas notify supervisors rather than assign. The module treats
  notification as the deliberate scope and the benchmark does not contradict it.
- **Anything ETA** — on hold by standing instruction.
