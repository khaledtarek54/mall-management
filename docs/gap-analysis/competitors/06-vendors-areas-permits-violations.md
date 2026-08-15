# Atriom vs mall/retail specialists — Vendors & contracts · areas/routing · permits & violations

> Domain deep-dive benchmarking Atriom against **ServiceChannel** (multi-site vendor/contractor management, compliance/insurance, dispatch, scorecards) and **Facilio** (zones/spaces, tenant + vendor management, tenant experience). Produced 2026-07-18.
>
> Atriom cells are grounded in `docs/modules/12-vendors.md`, `30-areas.md`, `31-violations.md`, `11-maintenance.md` and the source they cite. Competitor cells are from product knowledge (cutoff ~Jan 2026); version/pricing-sensitive claims are marked **(verify)**.
>
> **Legend:** ✅ full · 🟡 partial · ❌ absent · ⏭️ N/A or deferred

---

## 1. Capability matrix

### Vendor & contract management

| Capability | Atriom | ServiceChannel | Facilio | Gap note |
|---|---|---|---|---|
| Vendor master + typed classification | ✅ `Vendor` (contractor/supplier/service_provider/consultant/other), status active/inactive/**blacklisted**, tax_id, legal_name | ✅ | ✅ | Parity on the record. |
| Multiple contacts, primary-contact fallback | ✅ `VendorContact`, `is_primary` w/ oldest-fallback | ✅ (verify) | ✅ (verify) | Parity. |
| Time-bound contracts (start/end, value, lifecycle) | ✅ `VendorContract` draft→active→expired/terminated, per-property `asset_id` | 🟡 mostly a compliance/dispatch tool, not contract-value tracking (verify) | ✅ (verify) | Atriom's per-property contract scoping is a genuine strength for multi-mall. |
| Contract auto-expiry + expiring-soon alert | ✅ `vendors:expire-contracts` daily; nav badge = contracts expiring ≤30d (property-scoped) | 🟡 (compliance-doc expiry, not contract) (verify) | ✅ (verify) | Atriom expires + badges; no *email* renewal reminder yet (bell/badge only). |
| SLA terms captured on the contract | ✅ `sla_penalty_basis` + `sla_penalty_rate` on `VendorContract` (flat / per_day / percent_of_value) | ✅ SLAs per trade/priority | ✅ SLA + escalation engine | Parity; Atriom's basis is contract-configurable, not hard-coded. |
| Per-property SLA overrides | ✅ `SlaPolicy` per asset+priority (override-or-default) | 🟡 (verify) | ✅ (verify) | Clean override model; no per-mall restatement. |
| Vendor penalty on SLA breach (accruing, frozen on close) | ✅ `SlaPenalty` one-row-per-WO, hourly re-compute, frozen `final` at terminal | 🟡 tracks breaches/deductions, mechanics vary (verify) | 🟡 (verify) | Atriom's accrual key is a distinct row (not the alert stamp) — a hard-won design. |
| Penalty **charged onto the vendor's AP bill** | ✅ `ApplySlaPenaltyService` credits the *same expense* the bill debited; GL-tied | 🟡 invoice deduction / dispute credit (verify) | 🟡 (verify) | **Atriom exceeds:** automatic bill-deduction wired to the ledger, not a manual credit. |
| Insurance / COI / license / compliance-doc tracking + expiry + auto-block | ❌ vendors store `tax_id`/`legal_name` only — no COI, no license, no doc expiry, no block-noncompliant | ✅ **Compliance Manager** — COIs, W-9s, licenses, expiry alerts, blocks non-compliant from dispatch | 🟡 doc tracking (verify) | **Biggest single gap** — see §3.1. |
| Vendor scorecards / performance analytics | ❌ only `active_contracts_count` badge + penalty rows; no rollup rating | ✅ provider scorecards (on-time %, response, cost) | ✅ vendor performance (verify) | No renew/replace signal beyond raw penalty rows. |
| Vendor self-service portal / onboarding / marketplace | ❌ vendors are passive master data; assignment notifies **internal** staff, not the vendor | ✅ provider portal + vetted marketplace | ✅ vendor portal (verify) | Atriom has no vendor-facing surface at all. |
| Competitive quote / proposal / bid management | ❌ (procurement module handles PO/RFQ separately; not vendor-side) | ✅ proposal/quote flow | 🟡 (verify) | Vendor work has no bid-compare step here. |
| Dispatch / work-order assignment to vendor | 🟡 `assigned_to_vendor_id` on a request (+ notifies internal staff only) | ✅ full dispatch/accept/ETA | ✅ dispatch + tech app | Assignment is a column, not a vendor-accept loop; no blacklist guard on the picker. |

### Areas / zones → routing & intake

| Capability | Atriom | ServiceChannel | Facilio | Gap note |
|---|---|---|---|---|
| Zone/area register (per property) | ✅ `Area` OWNED-by-asset, per-property unique code, active/retire, soft-delete | 🟡 store/location, not sub-zones (verify) | ✅ space/zone hierarchy (floors, zones) | Facilio's hierarchy is richer (nested spaces, GIS); Atriom is a flat per-mall zone list. |
| Zone → supervisor(s) assignment | ✅ `area_user` many-to-many, picker scoped to the property's own staff | 🟡 (verify) | ✅ (verify) | Parity for the mall need. |
| Unit→zone→request inheritance (auto-tag) | ✅ derived in `TenantRequest::creating` — admin + portal + API all inherit `unit.area_id` | 🟡 (verify) | ✅ (verify) | Model-level derivation = no channel skips it; clean. |
| Auto-assignment to designated supervisor | ✅ FR-REQ-08: exactly-one-supervisor zone auto-assigns; multi-supervisor → notify all, coordinator assigns | ✅ rules-based routing | ✅ auto-assign rules | Atriom's rule is deliberately simple (count==1); no skills/load balancing. |
| Zone supervisor notification fan-out | ✅ `NotifyAreaSupervisorsService` (db + push, no mail), fail-safe, alongside dept routing | ✅ | ✅ | Parity. |
| Skills / availability / geolocation dispatch | ❌ | ✅ (verify) | ✅ (verify) | No skill-matching or load-aware routing. |
| Unknown-caller / phone / walk-in intake | ✅ `caller_name`/`caller_phone`/`caller_notes`; tenant-less requires caller name (model-enforced) | 🟡 (verify) | 🟡 (verify) | Real strength for a mall's on-site reality (walk-ins, non-tenant callers). |
| Multi-channel intake | ✅ portal / whatsapp / phone / email / walk_in / admin channels | ✅ | ✅ | Parity on channel taxonomy (WhatsApp is an intake tag, not a live integration). |

### Fit-out permits & tenant violations

| Capability | Atriom | ServiceChannel | Facilio | Gap note |
|---|---|---|---|---|
| Tenant fit-out permit capture (validity window) | ✅ `permit` request type + `valid_from`/`valid_to` (ordering enforced) | ⏭️ not a landlord tool | 🟡 permit-to-work exists, tenant fit-out (verify) | Captures FR-REQ-13/14's four fields. |
| Permit **approval / grant / reject** workflow | ❌ **capture-only, by design** — no approve/reject/conditions step | ⏭️ | 🟡 approval on permit-to-work (verify) | See §3.2 — a mall fit-out permit is usually a control gate. |
| Contractor permit-to-work / safety permit | ❌ | 🟡 (verify) | ✅ permit-to-work module (verify) | No hot-work/isolation safety-permit concept. |
| Tenant violation register + fine recording | ✅ `Violation` + nullable `fine_amount`, open/resolved, property-scoped, RBAC | ⏭️ | 🟡 (verify) | Register is solid and honest about scope. |
| Violation notice to tenant | ✅ explicit "Send notice" action → db+push, gated in `visible()` **and** `action()`, stamps `notified_at` | ⏭️ | 🟡 (verify) | Well-built (dual-layer gate, failure-contained). |
| Violation → **bill the fine** (post to AR) | ❌ `fine_amount` is recorded, never billed — no Invoice/Charge/GL | ⏭️ | 🟡 (verify) | Deliberate; doc even prescribes the future `Charge` path. See §3.4. |

---

## 2. Architecture read

**For a mall operator, the routing + intake spine is genuinely sound.** The `Unit → Area(zone) → TenantRequest → supervisor` chain is derived in the model's `creating`/`created` hooks, so admin Filament, the tenant portal, and the mobile API all inherit the same zone and fire the same supervisor fan-out — a rule enforced in one channel is not silently skipped in another. Single-supervisor auto-assignment (FR-REQ-08) plus multi-supervisor notify-all is a defensible, low-surprise routing policy. The unknown-caller intake (`caller_name`/`caller_phone`, model-enforced when `tenant_id` is null) reflects how a mall actually receives work — walk-ins and phone calls from people who aren't registered tenants — which neither ServiceChannel nor Facilio center as strongly, since both assume a known store/tenant. This part is at-par with the specialists and, on channel-agnostic derivation, arguably cleaner.

**The vendor-accountability loop is where Atriom quietly exceeds the generic behavior.** SLA terms live on the vendor *contract* (`sla_penalty_basis`, `sla_penalty_rate`), per-property SLA overrides live in `SlaPolicy`, a breach accrues a `SlaPenalty` (a distinct row, re-computed hourly, frozen at terminal), and — the differentiator — `ApplySlaPenaltyService` deducts that penalty **straight onto the vendor's AP bill**, crediting the same expense the bill debited so the ledger stays tied out. ServiceChannel and Facilio surface breaches and deductions, but the automatic, GL-wired bill-reduction (in EGP, bilingual) is a level of vendor-financial-accountability most tools leave as a manual credit or a dispute thread. Combined with per-property contract scoping and auto-expiry, Atriom's *money* story on vendors is strong.

**Where it is genuinely thin is the vendor *lifecycle* around that money.** Atriom knows what a vendor costs and what to dock them, but not whether they are *allowed on site*: there is no insurance/COI, license, or compliance-document tracking, no expiry alerting on those docs, and nothing that blocks a non-compliant or blacklisted vendor from being assigned (the maintenance form will happily assign a blacklisted vendor — the doc flags this). There is no vendor scorecard rolling penalties/on-time/response into a renew-or-replace signal, and no vendor-facing portal, onboarding, or marketplace — vendors are passive master data, and "assignment" notifies internal staff, not the contractor. This is exactly ServiceChannel's home turf (Compliance Manager + provider scorecards + provider portal), so a customer switching *from* ServiceChannel will feel the drop most here.

**Permits and violations are honest registers, not workflows — sometimes too honest.** A fit-out permit is a typed request with a validity window and, by explicit design, **no approval/grant/reject step**; a violation records a `fine_amount` but never bills it. Both choices are defensible readings of a tight FRD, and both are cleanly built (the violation "Send notice" action is dual-gated and failure-contained — better than average). But for a real mall operator, a fit-out permit is usually a *control gate* (contractor access, deposit, permitted hours, sign-off) and an assessed fine is usually money you intend to *collect*. So these read as deliberately deferred depth, not missing plumbing — the extension paths are documented — but they are depth a Yardi/Facilio customer expects.

---

## 3. Top 5 gaps for a mall operator

1. **Vendor insurance / COI / license & compliance-doc tracking (+ expiry alerts + block-non-compliant).** *(§1 row)* A mall is legally on the hook for the contractors it lets touch fire systems, elevators, and electrical; the operator must verify valid insurance and licenses *before* work, and re-verify at renewal. Atriom stores `tax_id`/`legal_name` only and will assign a blacklisted vendor without complaint. This is ServiceChannel's flagship (Compliance Manager). **Effort: M** (doc-collection model + expiry scan reusing the `vendors:expire-contracts` pattern + a dispatch-time guard).

2. **Fit-out permit approval workflow.** A mall fit-out permit gates physical access, work hours, and often a security deposit — it needs a grant/reject/conditions decision and an audit trail, not just a captured validity window. Atriom's permit is capture-only by design. **Effort: M** (a permit sub-lifecycle + approver gate; the `ApprovalPolicy` ladder already exists to borrow from).

3. **Vendor scorecards / performance analytics.** Operators renew or drop contractors on on-time %, breach history, and cost; Atriom exposes only an `active_contracts_count` badge and raw `SlaPenalty` rows with no rollup. **Effort: M** (aggregate WO SLA outcomes + penalties + response times into a per-vendor scorecard view).

4. **Bill the violation fine (post to AR).** When a mall assesses a fine it usually intends to collect it on the tenant's account; Atriom records the number and stops. The module doc already prescribes the correct path (raise a `Charge` on the lease so `Invoice::recomputeTotals()` stays the source of truth). **Effort: S–M.**

5. **Vendor self-service portal + competitive-quote/dispatch-accept loop.** ServiceChannel/Facilio let vendors accept work orders, submit quotes, upload invoices and compliance docs, and post ETAs. Atriom vendors have no portal and no accept/quote loop; dispatch is an internal column change. **Effort: L** (a new authenticated vendor surface — meaningful build, lower priority than 1–4 for a single-operator mall).

---

## 4. Net verdict

**At-par** on zone routing and multi-channel/unknown-caller intake; **ahead** on the SLA-penalty-charged-to-the-AP-bill accountability loop, per-property contract scoping, and Egyptian/bilingual fit; **behind** on vendor *lifecycle* breadth (insurance/compliance-doc tracking, scorecards, vendor self-service) and on permit/violation *workflow* depth (no permit approval, fines recorded-not-billed) — i.e. exactly the ServiceChannel/Facilio surfaces a switching customer would miss.
