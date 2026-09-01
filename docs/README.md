# Atriom — documentation

Egyptian mall-management ERP. Operator **Eltizam** runs malls for owners (**Jawad**); **tenants**
are the retailers. Laravel 13 · PHP 8.4 · Filament 4.

> **Read in this order.** Everything below is either *how the system works*, *how each module
> works*, *what is missing*, or *how to run it*. If a document does not answer one of those four
> questions, it should not be here.

---

## Start here — 1 · How the system works

| | Document | Answers |
|---|---|---|
| **1** | [OVERVIEW.md](OVERVIEW.md) | What the system is, the domain, and how the parts fit together |
| **2** | [PROJECT-MAP.md](PROJECT-MAP.md) | Where everything lives — the generated census, every route family, the codebase layout, the scheduled automation |
| **3** | [BUSINESS-RULES.md](BUSINESS-RULES.md) | Every financial rule the system enforces, in plain language, for the operator and accountant to **sign off** |
| **4** | [PROPERTY-ISOLATION.md](PROPERTY-ISOLATION.md) | The invariant that confines every module to one mall — read before adding a property-owned module |
| **5** | [STATUS.md](STATUS.md) | **Status and what's next — the ONE document.** Where the build is, what blocks go-live (infrastructure, credentials, and the decisions only the client can make), and an ordered next step. Absorbed `OPEN-QUESTIONS.md` and `operations/GO-LIVE.md` on 2026-08-24 |
| **6** | [ROADMAP.md](ROADMAP.md) | The single prioritised list — what to build next, and what not to rebuild |
| **7** | [DEMO.md](DEMO.md) | **Showing the system to somebody** — a 30-minute run for a non-accountant, and how to adapt it per audience |
| **8** | [EGYPT-MARKET-FIT.md](EGYPT-MARKET-FIT.md) | **What the operator can change without a developer**, and what Egyptian law and Egyptian mall practice demand of it — the configurability axis, with the statute research behind it |

**Pictures first?** The visual handbook lives *in the panel* at **`/admin/handbook`** (bilingual;
built by `npm run build`). Source: [`visual/`](visual/) — [the whole system on one
page](visual/map.md) · [a month in the life](visual/scenarios.md).

---

## 2 · How each module works

**[`modules/`](modules/README.md)** — 38 modules, one doc each: business rules, data model,
services, screens, **extension points** and gotchas.

**Read the module's doc before changing its logic, and update it in the same commit.** The index
groups them by the money spine · recoveries and variable rent · counterparties · facility and
operations · cross-cutting.

**Teaching somebody the module rather than changing it?** [`training/`](training/README.md) is the
people-facing counterpart — a walkthrough per module for a newcomer with no property or accounting
background, with a hands-on exercise set to run against `LearningSeeder`. It does not restate the
module docs; it links to them.

---

## 3 · What is missing

**[`gap-analysis/`](gap-analysis/README.md)** — **one** document: Atriom measured against **Yardi
Voyager Commercial** (leasing, AR, recoveries, reporting, GL), the **FM specialists** (facility,
vendors, assets) and **Odoo** (the generic ERP layer). Every open gap with its module, severity,
effort and blocker; everything declined, with the reason; and the rows that changed when it was
last re-verified.

Supporting reference — *how the benchmark itself works*, so a claim can be checked rather than
believed: **[`benchmarks/yardi/`](benchmarks/yardi/README.md)** for leasing, AR and recoveries, and
**[`benchmarks/fm/`](benchmarks/fm/README.md)** — IBM Maximo for the work-and-asset core,
ServiceChannel for the contractor loop — for facility and vendors.

**A second, different question — the same discipline.**
**[EGYPT-MARKET-FIT.md](EGYPT-MARKET-FIT.md)** measures Atriom on the **configurability** axis
(*what can the operator change without a developer?*) against **the Egyptian statute book and
Egyptian mall practice**, rather than against a competitor's feature list. A capability can be fully
built and still fail that test. It cites the gap analysis where the two touch, and never restates it.

---

## 4 · How to run it

| Folder | Contents |
|---|---|
| **[`operations/`](operations/)** | The launch gate moved to [STATUS.md](STATUS.md) · [STAGING-CUTOVER](operations/STAGING-CUTOVER.md) — the ordered runbook · [STAGING](operations/STAGING.md) — the box, and which health rows are *supposed* to be red · [PRODUCTION-RUNBOOK](operations/PRODUCTION-RUNBOOK.md) — the per-release sequence · [INFRASTRUCTURE](operations/INFRASTRUCTURE.md) — servers, Cloudflare, backups · [WHAT-WE-NEED-FROM-YOU](operations/WHAT-WE-NEED-FROM-YOU.md) — the bilingual form for the operator's accountant (a FORM, not a status list — STATUS.md remains the authority) |
| **[`qa/`](qa/README.md)** | The pre-staging harness ([PRE-STAGING-QA](qa/PRE-STAGING-QA.md) + [findings](qa/PRE-STAGING-FINDINGS.md)), the [release checklist](qa/RELEASE-CHECKLIST.md), UAT scripts, and the runnable [`scripts/`](qa/scripts/README.md) behind `composer qa` |
| **[`integrations/`](integrations/)** | [Paymob](integrations/PAYMOB.md) · [ETA + Paymob certification](integrations/ETA-PAYMOB-CERTIFICATION.md) · [the public pay link + Apple Pay](integrations/PAYMENT-LINK-APPLEPAY.md) · [push notifications](integrations/PUSH-NOTIFICATIONS.md) · [the in-app assistant](integrations/AI-ASSISTANT.md) — the design; A0 shipped, see [modules/39](modules/39-assistant.md) |
| **[`api/`](api/MOBILE-API.md)** | The mobile API a client codes against, plus the generated [`openapi.json`](api/openapi.json) (`composer api-spec`) |

**The one pre-deploy command:** `php artisan atriom:preflight` — health, the configuration checks, both data audits and a
deep reconcile, in order.

---

## 5 · The books

**[`accounting/`](accounting/README.md)** — written for the accountant, in Arabic and English.

- [README](accounting/README.md) — the plain-language map of the accounting module
- [WALKTHROUGH](accounting/WALKTHROUGH.md) — the bilingual tour to present: concepts, chart, every money flow with its journal entry
- [ACCOUNTANT-BRIEFING](accounting/ACCOUNTANT-BRIEFING.md) — the posting map and role → chart (PDF beside it)
- [EGYPTIAN-TAX-CATALOG](accounting/EGYPTIAN-TAX-CATALOG.md) — the operator's own tax sheet, which the `tax_codes` catalogue must match exactly
- [CHANGE-IMPACT-PLAN](accounting/CHANGE-IMPACT-PLAN.md) — when an edit to a posted document may move the books
- [BANK-RECONCILIATION-PLAN](accounting/BANK-RECONCILIATION-PLAN.md) — the design behind the statement import and matcher

Technical detail is [modules/21](modules/21-general-ledger.md), not here.

---

## 6 · Where the requirements came from

**[`requirements/`](requirements/)** — the client's own words, kept verbatim so nobody works from a
summary. [FUNCTIONAL-REQUIREMENTS](requirements/FUNCTIONAL-REQUIREMENTS.md) (the FR ↔ build-status
map) · [CLIENT-FRD-NOTES](requirements/CLIENT-FRD-NOTES.md) · [CLIENT-DISCOVERY-ANSWERS](requirements/CLIENT-DISCOVERY-ANSWERS.md).

---

## The rules this tree is kept under

1. **Four questions, or it does not belong here.** How the system works · how a module works · what
   is missing · how to run it. Session logs, completed implementation plans, point-in-time sweeps
   and superseded workbooks were removed on 2026-08-19 — git has them if a decision needs
   archaeology.
2. **One home per topic.** Three descriptions of the money model in three states of staleness is
   worse than one, because a reader cannot tell which is current. The money model lives in
   [modules 05–07 and 21](modules/README.md); the gap analysis lives in one file; the go-live gate
   lives in one file.
3. **Never hand-type a count or a registry.** `GeneratedDocsConformanceTest` fails the build when a
   `<!-- GENERATED:… -->` block drifts. Run `atriom:dump-system-census`, `atriom:dump-registries`,
   `atriom:dump-handbook-data` instead — that is how PROJECT-MAP came to claim 28 models when there
   were 61.
4. **A doc is part of "done".** Changing a module's logic means updating its doc in the **same
   commit**.
5. **A claim about the code is checked against the code.** Both directions: the retired documents
   carried rows saying something was missing that had shipped *and* rows saying something shipped
   that had not. Grep before you act, and say what you grepped.
