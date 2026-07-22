# Atriom — MVP-Readiness Program

> Take the whole system to a **high-standard, competitive, first-client-MVP** state — module by module — benchmarked against property **and** facility-management specialists. This doc is the charter for that program: the mission, the per-module pass, the process, and live status. The authoritative per-module findings live in [`docs/gap-analysis/`](gap-analysis/) and the closure ledger [`PROPERTY-FACILITY-CLOSURE.md`](gap-analysis/PROPERTY-FACILITY-CLOSURE.md).

## 1. Mission

- **Client:** Eltizam (operator) running Egyptian malls for owners (Jawad). This is the **MVP for the first real client** — it must be trustworthy on money and pleasant to operate, not a demo.
- **Bar:** a genuinely high-level app with high standards — correct books, verifiable numbers, clean UX, bilingual (EN + RTL Arabic), scalable and maintainable **by default**.
- **Benchmark:** the leasing/AR specialists (Yardi, MRI) **and** the facility-management specialists (Angus, Building Engines, Planon, MRI). For every module we ask: *what do the specialists do that we don't, and is it MVP-critical for this client or a fair deferral?*
- **Moat we are protecting:** Egyptian books (VAT 14% / exemptions, ETA e-invoicing, marketing levy), a real double-entry GL with a property/asset dimension, SLA→AP automation, airtight per-property isolation — the things generic global platforms localize poorly.

## 2. The six-dimension pass (every module)

Each module is taken to production-grade across **all six** dimensions — not just one:

| # | Dimension | What "done" means |
|---|-----------|-------------------|
| 1 | **Business-model gap vs competitors** | Compare against P&FM specialists; list what's missing; classify each gap **MVP-critical** (our client needs it / competitors all have it) vs **deferrable** (with an explicit trigger). "Competitors have it" alone ≠ build it — the test is whether *our* client's reality needs it. |
| 2 | **UX enhancement** | The UX-completeness checklist: **verifiable numbers** (a native "View working" breakdown wherever a computed figure appears), native Filament (no hand-rolled Blade), clear action **feedback** with resulting state, **honest** modal copy, reactive forms, and **EN+AR i18n added in the same change** (no raw keys). |
| 3 | **Module completion** | The missing slices that make it functionally complete for the client's real workflow. |
| 4 | **GL completion & correctness** | Every money source on the `LedgerPoster::JOURNALIZERS` single registry; a real `accounting:sync-ledger` sweep tie-out test per source; closed-period guards (`PostingDate`); `billing:reconcile` ties out. |
| 5 | **Bug hunt** | An adversarial correctness review (multi-agent) before every push — find the input→wrong-output, don't trust "looks right". |
| 6 | **Recommendations** | What would make the module fully functional + competitive beyond the MVP — captured as deferrals with triggers, so nothing is lost. |

## 3. Process

- **Audit** — per-module (or grouped) **read-only** agents produce ranked findings (HIGH / MEDIUM / LOW) against the six dimensions.
- **Triage** — fix HIGH + MEDIUM now; defer LOW **with an explicit trigger**; retire false "X is missing" findings with the reason (the mechanism usually lives in another layer).
- **Adversarial review** before every push (a fresh pass on the diff, not just green tests).
- **Definition-of-done gates:** `vendor/bin/pest --parallel` green · PHPStan no new errors above the baseline · `billing:reconcile` ties out · module doc + memory updated **in the same commit** · pushed to `main`.
- **Standing decisions** (don't re-ask): pick the **MVP subset competitors have**, defer the rest; scalability + maintainability are non-negotiable defaults; no DB-level enums; native Filament over Blade; property isolation + authz double-gating on every write action.

## 4. Module status matrix

Legend: ✅ done · 🔄 in progress · ⬜ not started · — n/a. "Biz/Compl/GL/Bugs" for the AR/leasing spine were taken to CLOSED in the earlier close-out; **UX** is the dimension being added across the program now.

| # | Module | Biz-gap | UX | Completion | GL | Bugs | Overall |
|---|--------|:---:|:---:|:---:|:---:|:---:|---|
| 01 | Properties & Units | ✅ | 🔄 | ✅ | — | ✅ | Closed; UX pass in progress |
| 02 | Tenants | ✅ | 🔄 | ✅ | — | ✅ | Closed; UX pass in progress |
| 04 | Leases | ✅ | 🔄 | ✅ | ✅ | ✅ | Closed; UX pass in progress |
| 05 | Billing & Invoices | ✅ | 🔄 | ✅ | ✅ | ✅ | Closed; UX pass in progress |
| 06 | Payments (+ tenant credit) | ✅ | ✅ | ✅ | ✅ | ✅ | **UX pass done** (`b0740e1`) |
| 07 | Credit Notes | ✅ | ✅ | ✅ | ✅ | ✅ | **UX pass done** (`88814a7`) |
| 08 | CAM reconciliation | ✅ | ✅ | ✅ | ✅ | ✅ | **UX pass done** (`6ba6fb1`) |
| 09 | Tenant Sales & % Rent | ✅ | ✅ | ✅ | ✅ | ✅ | **Annual + UX done** (`8b2ca48`) |
| 10 | Utility Meters | ⬜ | ⬜ | 🟡 | 🟡 | ⬜ | Next |
| 11/26 | Maintenance (CM + PPM) | ⬜ | ⬜ | 🟡 | — | ⬜ | Next |
| 12 | Vendors & Contracts | 🟡 | ⬜ | 🟡 | ✅ | 🟡 | Next |
| 29 | Procurement | ⬜ | ⬜ | 🟡 | ⬜ | ⬜ | Later |
| 32 | Owner Statements | 🟡 | ⬜ | 🟡 | ✅ | 🟡 | Later |
| 33 | Post-dated Cheques | 🟡 | ⬜ | 🟡 | ✅ | 🟡 | Later |
| 10–33 | (remaining) | ⬜ | ⬜ | — | — | ⬜ | Backlog — see the ledger |

*The full ordered list (including 03 Tenant Portal, 15 Owner Requests, 16 ETA, 17 Reports, 30 Areas, 31 Violations, and the generic-ERP layer that is intentionally frozen) is in the [closure ledger](gap-analysis/PROPERTY-FACILITY-CLOSURE.md).*

## 5. Current focus — the "first 8" (the AR / leasing spine)

Modules **01, 02, 04, 05, 06, 07, 08, 09** — the money-critical core the client touches daily. UX pass:

- **Done:** 09 annual (cumulative) % rent — module completion **and** the operator/tenant UX; then the UX pass over **06 tenant-credit**, **07 Credit Notes**, **08 CAM** (each: verifiable "View working" breakdowns, caught `DomainException`→toast instead of 500, native components, richer/branched feedback, honest modal copy, EN+AR keys — with per-module adversarial review + tests).
- **In progress:** UX pass on the remaining four — **01 Properties/Units, 02 Tenants, 04 Leases, 05 Billing/Invoices**.
- **Then:** modules 10+ (Utility Meters → Maintenance → Vendors → Procurement → Owner Statements → PDC → …), each through the full six-dimension pass.

## 6. Recurring gaps found (so the pass stays sharp)

From the first-8 UX pass, the same defects recur — audit every module for them:

1. **Bare, unverifiable numbers** — a computed money figure with no visible working (fixed with a service `explain()` + a native "View working" modal).
2. **Uncaught `DomainException` → Livewire 500** — service guards (closed period, terminal state, freeze) must be **caught** into a clean localized toast + the offending control disabled/hidden.
3. **All-or-nothing** operations where the client needs **partial/granular** control.
4. **Thin feedback** — toasts that omit the resulting state (new balance, which months re-trued, recover-vs-credit).
5. **Wrong/stale copy at a money moment** — modal descriptions that misstate what a button does.
6. **Missing i18n keys** — a key referenced in code but absent in en/ar renders raw to the user; always add EN+AR in the same change.

## 7. Competitive positioning (why this wins the deal)

- **Egyptian-native books** the global platforms fake: 14% VAT vs base-rent exemption per line, ETA e-invoicing, the 5% marketing levy, Arabic-first RTL.
- **A real GL** (double-entry, property/asset dimension, single-registry money sources, tie-out gates) — not a reporting bolt-on.
- **SLA → AP** automation and **CAM/percentage-rent** engines built for retail malls specifically.
- **Airtight per-property isolation** with self-enforcing conformance gates — a multi-mall operator can trust the walls.

---

*Kept in sync as the program advances. Per-module detail: [`docs/gap-analysis/`](gap-analysis/) · business model: [`docs/business-model/`](business-model/) · the single prioritized list: [`ROADMAP.md`](ROADMAP.md).*
