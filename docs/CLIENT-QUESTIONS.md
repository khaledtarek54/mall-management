# Atriom — open questions for Eltizam

> **What this is.** Every decision we could not make for you, in one place. Each one is a point
> where we had to choose between guessing and asking — and asking is cheaper than being wrong.
>
> **Generated:** 2026-07-17 · **Source of truth:** [BUSINESS-RULES.md](BUSINESS-RULES.md) (the full
> rule-by-rule detail) and the Eltizam FRD's own *Open Items & Follow-ups Required*.

---

## How to read this

Questions are grouped by **who can answer them**, because that is what decides how fast they close.
Within each group they are ordered by what happens if the answer is different from our assumption.

| Marker | Meaning |
|---|---|
| 🔴 **Blocks go-live** | Wrong answer = wrong tax owed, wrong money billed, or a compliance breach |
| 🟠 **Blocks a feature** | We stopped building and are waiting |
| 🟡 **Shapes a default** | Built and working; your answer changes a number, not the code |

Every question states **what the system does today**, so silence is a decision too — if you don't
answer, that is what ships.

---

## A · For your accountant — tax & billing

🔴 **All ten block go-live.** These are the rules the whole billing engine computes from. Nine of
them are *unverified assumptions*: plausible, consistent, and never confirmed by anyone with the
authority to confirm them.

| # | Question | What we do today | If you answer differently |
|---|---|---|---|
| 1 | Is **14% the correct VAT rate** on service charges, utilities and parking — and is **base rent genuinely VAT-exempt** under current Egyptian law? | 14% on services; base rent exempt | Every invoice ever issued has the wrong VAT |
| 2 | Is **percentage rent VAT-exempt**? | 0% VAT | Every percentage-rent invoice under-charges VAT |
| 3 | Are **CAM true-up charges VAT-exempt**? | 0% VAT — *our unverified assumption* | Every reconciliation invoice under-charges VAT |
| 4 | Are **late fees VAT-exempt**? | 0% VAT (penalty interest outside VAT) | Every late fee under-charges VAT |
| 5 | Is the **marketing levy 5% of base rent only** (not services/utilities/VAT), **accrued internally and never shown on the tenant's invoice**? | Yes, both | The levy is mis-calculated, or tenants should see it |
| 6 | Is **CAM allocated pro-rata by leased area**? Does your lease wording actually say "by area"? | Pro-rata by m² | Some leases allocate by turnover or a fixed share — allocations are wrong |
| 7 | **Late-fee policy:** 2% of outstanding, min 50 EGP, charged **once** (not compounding), after a **7-day** grace? | Exactly that | None of these four numbers has a documented legal source — they are our defaults |
| 8 | **Security deposit = 3 months' rent** and **annual escalation = 7%** — your real contract defaults? | Both, baked into every new lease | Every new lease starts wrong |
| 9 | Is the **artificial-breakpoint formula** right for percentage rent — `(sales − threshold) × rate`? | Yes, and leases with no calculation type set use it **silently** | If your leases use the natural breakpoint, percentage rent is wrong |
| 10 | Is the **default payment term 7 days** from issue? | 7 days | Due dates and the whole overdue/late-fee chain shift |

> **Also for the accountant, and not a question but a warning:** **ETA e-invoicing runs in
> mock/test mode.** It is built and covered by tests, but it needs live credentials and a signing
> certificate before it can submit legally-binding documents. Nothing you have submitted through it
> has reached the tax authority.

**How to verify the figures once the rules are confirmed:** run `php artisan billing:reconcile`. It
independently re-derives receivables from source records and prints control totals (invoiced /
collected / credits / outstanding AR / VAT) to reconcile against your own books.

---

## B · For Eltizam's operations lead — the facility-management build

These came out of building the FRD. In each one we deliberately stopped rather than guess.

### 🟠 11 · Do you want to recharge tenant-caused repairs at all? — **we have not built this**

The FRD says the system shall **record** whether the mall or the tenant is financially responsible
(FR-CM-13). It never asks the system to **bill** the tenant — and no requirement anywhere in the
document does.

So we record responsibility and stop there. **Nothing in the system can bill a tenant for a repair.**

If you want the recharge, these must be answered first — each changes the code:
1. Is a recharge **VATable** (14%, as a service), or a cost recovery outside VAT?
2. What is recoverable — **parts only**, or parts + labour + the vendor's invoice?
3. What happens when the **cost changes after** the recharge (a part returned, a purchase removed)?
   A tenant billed for something that didn't happen is the failure mode here.
4. Can the tenant **dispute** it — and does a successful dispute produce a credit note, or a void?

### 🟠 12 · SLA penalties: a cost reduction, and does the benefit reach tenants?

Two questions, and the second decides **who gets the money**. When a contractor misses an SLA and we
deduct a penalty from their bill, our books currently treat it as a **reduction of the maintenance
cost** (Dr Accounts Payable / Cr the same expense the bill charged), with **no VAT**.

- Is that the right treatment, or is a penalty **other income**?
- Those repair costs flow into **CAM**, which tenants reimburse. If a penalty reduces the cost, the
  saving reaches tenants automatically. **Is that what you intend** — or should the mall keep it?

### 🟡 13 · Approval thresholds — are 1,000 / 10,000 the right bands?

FR-CM-11 says "higher-value parts require higher-level approval" and **gives no numbers**. Ours:

| Value (EGP) | Needs |
|---|---|
| 0 – 999.99 | a supervisor |
| 1,000 – 9,999.99 | a manager |
| 10,000 + | senior approval |

They are **data**, so changing them is configuration, not a rebuild.

### 🟡 14 · Does procurement approval follow those same bands?

**The FRD's own open item:** *"The client did not specify a formal approval hierarchy for
procurement itself. Confirm whether procurement approval also follows a price-based manager
hierarchy or a separate rule."*

We defaulted to **yes, identical bands** — it is the only hierarchy you have described. Also worth
confirming: does a large purchase need **more than one** approver? Today it is a level lookup, not a
sequential chain.

### 🟠 15 · Must a vendor bill back an externally-bought part?

When a part is bought outside for a job, we record it on the work order (what, from whom, which
supplier invoice) — but it posts **nothing** to the books, because it never touched our stock. The
accounting document is the **vendor bill**.

Nothing links the two, and nothing requires a bill to exist. So a job's parts cost can exceed what
the books know about it.

- Should recording an external part **require** a vendor bill before the job can close?
- Or is the work-order record a memo, with accounting entering the bill independently?

### 🟡 16 · Low-stock alerts — wanted, and is one threshold per item enough?

FR-INV-03 is the FRD's own *"recommended addition — confirm with client if desired"*. We built it
(a daily bell notification, per mall, switchable off) because an alert cannot do harm.

The design question: the reorder level is **one number per item, applied to every mall**. If a
flagship mall should carry more stock than a small one, the threshold needs a per-property
dimension — that is a database change, so it's worth deciding now.

---

## C · The FRD's own open items

Raised by the FRD itself, still unanswered.

| # | Question | Status |
|---|---|---|
| 17 | **Multi-location warehousing** — are multiple warehouse locations per mall needed? Are **bins/shelves** within a warehouse needed? | 🟠 Not built. Today a warehouse is the finest grain. |
| 18 | **Inter-mall stock transfers** — in scope? | 🟠 Not built. Note: transfer types exist in the code as scaffolding but **nothing creates them** — it looks shipped and is not. Inter-mall transfers also move value between two properties' books. |
| 19 | **Tenant sub-metering** — this module, or leasing/billing? | 🟡 Already built and working (a meter can belong to a unit). The FRD says out of scope. Leave it (harmless when unused) or hide it? |
| 20 | **Export formats for finance** — your stakeholders use Oracle, SAP and Odoo. What format do they need (Excel-compatible? Odoo-importable)? | 🟡 We export Excel/PDF today. |

---

## D · Requirements we cannot build as written

| # | Question | Why we stopped |
|---|---|---|
| 21 | **FR-REQ-01 "delegation (from/to)"** — what does this mean? | No such concept exists anywhere in the system or the rest of the FRD, and we cannot infer it. One sentence from you unblocks it. |
| 22 | **FR-PPM-01 "Fixed maintenance"** — one-time, or periodic-per-asset? | The FRD says **both**, in different sentences. We support periodic; one-time is a different shape. |
| 23 | **FR-USR-01 "Admin (per mall) — full access"** — does "full access" include **deleting records**? | The row's only distinguishing note is import/upload. Deletion is restricted to the system owner by design; confirm you don't mean to change that. |
| 24 | **FR-PROC-05 status history** — is who/when/from→to enough, or do you need **per-step comments and attachments**? | We record who/when/from→to for every step. Comments/attachments are a bigger build. |

---

## What happens if you answer nothing

Everything in **🟡** ships as described — those are working defaults.

Everything in **🟠** stays unbuilt. The significant one is **#11**: we will not bill tenants for
repairs.

Everything in **🔴** is the problem. Section A is not a preference list — those are the numbers the
billing engine computes from, and nine of them are assumptions nobody has confirmed. **They should
be signed off before the first real invoice goes out.**

---

## Sign-off

| | Name | Date |
|---|---|---|
| **Section A** (tax & billing) | *accountant* | |
| **Sections B–D** (operations) | *Eltizam operations lead* | |

**Related:** [BUSINESS-RULES.md](BUSINESS-RULES.md) — every rule in the system, with its risk level
and whether an admin can change it without a developer.
