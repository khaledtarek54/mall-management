# Purchase / procurement & vendors — Atriom vs Odoo

> Domain 3 of the [Atriom vs Odoo gap analysis](README.md). Atriom side grounded in
> `PurchaseRequestService`, `ApprovalPolicy`, `PurchaseRequest`/`ApprovalRule`, `VendorBillJournalizer`,
> the vendor models, and [`docs/modules/29`](../../modules/29-procurement.md) +
> [`docs/gap-analysis/29`](../29-procurement.md). *(verify)* = version/config-sensitive.

Legend: ✅ full · 🟡 partial/by-config · ❌ absent · ⏭️ out of scope by design

## 1. Capability matrix

| Capability | Atriom | Odoo Community | Odoo Enterprise | Gap note |
|---|---|---|---|---|
| Purchase request / internal requisition | ✅ | 🟡 *(verify)* | 🟡 *(verify)* | **Atriom leads.** First-class internal **request** (justification NOT-NULL, approval before any order). Odoo starts at the RFQ; a distinct requisition needs Purchase Agreements or OCA `purchase_request`. |
| RFQ to **one** vendor (solicit a quote) | 🟡 | ✅ | ✅ | Atriom never *solicits* — it approves a need internally, then records a chosen `vendor_id` + `order_reference`. No quotation document. |
| RFQ to **multiple** vendors + bid comparison | ❌ | ✅ *(verify — Purchase Agreements / Call for Tender)* | ✅ | One vendor per request; no tender/bid-compare. The single biggest functional gap vs Odoo. |
| Purchase order (as a document) | 🟡 | ✅ | ✅ | Atriom's "order" is a **status transition** stamping `vendor_id` + free-text `order_reference`; no generated/emailed PO PDF, no separate PO line entity. |
| Amount-based approval | ✅ | ✅ (double validation) | ✅ | Atriom's `ApprovalPolicy` ladder (3 tiers, fail-closed, strictest-band-wins, tier frozen at request, re-judged on current total) is arguably stricter than Odoo's threshold. |
| Multi-step / sequential approval chain | ❌ | 🟡 (only 2 levels) | ✅ *(verify — Approvals/Studio)* | Atriom resolves **one** approver by amount; no serial chain. Odoo core also caps at two levels; true chains are Enterprise. |
| Goods receipt → stock-in | ✅ | ✅ (Inventory) | ✅ | `receive()` writes source-linked movements, per-line dedupe, property-scoped warehouse. Solid. |
| 3-way match (PO / receipt / bill) | 🟡 | 🟡 (needs Accounting for match status) | ✅ *(verify)* | Atriom links the bill and clears **up to received value**, but **no tolerance, no variance hold, no match status**. Matching is by value, not a gate. |
| GRNI / goods-received-not-invoiced | ✅ | 🟡 *(verify — auto interim needs Accounting)* | ✅ | Dedicated GRNI liability (21701001), **FIFO allocation across bills, capped at received value**. Conceptually = Odoo's Stock-Interim(Received). A real strength; ahead of Community. |
| Bill on ordered vs received qty | 🟡 | ✅ (Bill Control setting) | ✅ | Atriom is effectively bill-on-received (GRNI clears only received value); no toggle. |
| Vendor pricelists | ❌ | ✅ (supplier price/min qty) | ✅ | No per-vendor catalog; `unit_cost` typed per line. |
| Blanket / framework agreements | ❌ | ✅ *(verify — Blanket Order)* | ✅ | No call-off agreements. Note: **`VendorContract` covers the *service-contract* case** (annual HVAC, cleaning) with value + SLA — but is not a purchasing call-off framework. |
| Reorder-driven auto-purchase | 🟡 | ✅ (rules auto-generate RFQ) | ✅ | Atriom alerts only; does not auto-raise a request/RFQ. |
| Vendor lead times | ❌ | ✅ | ✅ | No lead-time modeling; no promised/expected receipt planning. |
| Drop-ship | ❌ | ✅ *(verify)* | ✅ | Not modeled; ⏭️ irrelevant to a mall buying its own spares. |
| Vendor management (contacts/contracts/SLA) | ✅ | 🟡 | 🟡 | **Atriom richer.** Vendor master + typed contacts + property-scoped **auto-expiring contracts** + **SLA penalty charged onto the bill** (FR-CM-08). Odoo's vendor is a `res.partner`; native SLA-penalty-on-bill isn't core. |
| Procurement reporting | 🟡 | ✅ (Purchase Analysis) | ✅ (+ dashboards) | Atriom has status-history via activity log + nav badges, but **no spend/purchase-analytics report**. |

## 2. Architecture read

**The state machine is sound, and correct for the domain.** `requested → approved → ordered →
received` (with `rejected`/`cancelled` terminal) is Odoo's `RFQ → PO → receipt → bill` reordered
around a real distinction: **Atriom approves a *need* before a vendor is even chosen**, whereas
Odoo approves a *priced PO*. For a mall operator whose procurement is "the storeroom needs pump
seals, justify and get sign-off, then someone sources it," the request-first model is the better
fit. FR-PROC-02 is enforced as the *absence* of a `requested → ordered` edge — making "no ordering
without approval" unrepresentable rather than merely discouraged. Every mutation locks its row and
re-checks the transition; receipts dedupe per line. **Keep as-is.**

**The single-approver-by-amount ladder is the right default, but is a ceiling.** `ApprovalPolicy`
is more defensive than Odoo's double-validation (fail-closed on gaps, strictest-covering-band wins,
tier frozen for audit yet re-judged on the current total). Its limitation is structural: it
resolves **one** tier, so it can't express a **sequential chain** (dept head → finance → owner) or
parallel/role-specific sign-off. Odoo core shares this ceiling; only Enterprise's Approvals app
exceeds it. **Keep the policy; reconsider only if serial multi-party sign-off is actually needed** —
and if so, model it as ordered approval *steps*, not more tiers.

**GRNI clearing is the standout, and matches Odoo's own mechanism.** Receipt Dr Inventory / Cr GRNI;
the bill Dr GRNI / Cr AP, allocating received value **FIFO across bills, capped at what the receipt
credited** — functionally Odoo's Anglo-Saxon Stock-Interim(Received). Notably Odoo only auto-posts
that interim entry with the full **Accounting app (Enterprise)**; Community leaves valuation manual.
So on GRNI, Atriom is *ahead* of Odoo Community. Caveat the module's own gap-analysis flags: the
ad-hoc receipt path still writes source-less movements (the demo's 166,120 EGP of un-clearable
GRNI), so the discipline holds only if purchases route through a request. **Keep; finish deprecating
the sourceless receipt path for stock purchases.**

**"One vendor per request, no bid comparison" — is it a real limitation?** For **routine** mall
procurement (spares, consumables, cleaning supplies, small services) — **fine, not a real gap**;
nobody tenders pump seals, and the SLA-bearing `VendorContract` already covers recurring service
vendors. It becomes a **real** limitation for **capital/large one-off spend** (a chiller
replacement, a fit-out) where an owner reasonably expects 3 quotes compared — exactly the spend the
tier_3 band exists to gate. Honest read: acceptable for opex, a governance gap for capex.

## 3. Top 5 real gaps (ranked for a mall operator)

1. **No multi-quote / bid comparison for large purchases** — the one-vendor model gives no "3 quotes
   compared" trail precisely on the high-value spend (tier_3) where an owner most wants governance.
2. **No purchase-spend reporting** — no spend-by-vendor / by-category / by-tier or GRNI-aging report,
   so the operator can't see where money goes without exporting raw rows.
3. **3-way match has no tolerance or variance hold** — the bill clears GRNI by value but nothing
   flags/blocks a bill exceeding the PO/receipt on price or quantity; overbilling passes silently.
4. **Reorder auto-purchase is alert-only** — low-stock fires a notification but never drafts a
   request (Odoo turns the same signal into an RFQ).
5. **The "order" step is a status flag, not a PO document** — no generated/emailed PO with terms and
   expected-delivery date; the supplier-facing paper trail lives in a free-text `order_reference`.

*Uncertainty flags: Purchase Agreements placement (blanket orders / call-for-tender — likely
Community,* verify*), the 3-way-match status + automated GRNI interim postings (hinge on the full
Accounting app = Enterprise), multi-level approval beyond double-validation (Enterprise), and
dropshipping edition. Atriom side verified against source.*
