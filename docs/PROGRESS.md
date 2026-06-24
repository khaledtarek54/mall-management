# Project Progress & Sign-off — Reference

> **Single source of truth for "is the business part done?".**
> Each item moves through: **Built** (code) → **Tested** (automated suite) → **Validated** (you sign off in the running app).
> When every business item below is **Validated ✅**, we close **Part 1 (Business model)** and move to the next part.
>
> - Design & live build status → [FUNCTIONAL-REQUIREMENTS.md](FUNCTIONAL-REQUIREMENTS.md) (§3)
> - How to validate each feature → [VALIDATION-GUIDE.md](VALIDATION-GUIDE.md)
> - Suite: `vendor/bin/pest --parallel` (currently **509 green**)

---

## Part 1 — Business model  ·  status: **building + validating**

Legend: ✅ done · ☐ pending · 🟡 partial · 🔴 not started · ⏸️ deferred

### A. Built — awaiting your validation

| # | Feature / requirement | Built | Tested | Validated (you) | Commit |
|---|---|:---:|:---:|:---:|---|
| 1 | Departments ERP (model, admin UI, RBAC, 5 seeded) | ✅ | ✅ | ☐ | `701d246` |
| 2 | Department staff membership | ✅ | ✅ | ☐ | `4b12538` |
| 3 | Maintenance → departments (assign / redirect / reject) | ✅ | ✅ | ☐ | `4f78c60` |
| 4 | Closed-request immutability | ✅ | ✅ | ☐ | `0fdd558` |
| 5 | Marketing — 5% levy, auto budget, spend + receipts, UI | ✅ | ✅ | ☐ | `2f22fec`→`af097c4` |
| 6 | Owner requests — create + respond, **in admin app** | ✅ | ✅ | ☐ | `4340a39`,`1b7da75` |
| 7 | Tenant commercial register (segel togary) | ✅ | ✅ | ☐ | `a492358` |
| 8 | Scheduled work window (from→to) | ✅ | ✅ | ☐ | `a492358` |
| 9 | Owner model — no portal; owners are admin RBAC users scoped to owned properties | ✅ | ✅ | ☐ | `464241d` |
| 10 | Department access — fixed set; each dept maps to a role; registering a user grants its role (hybrid); **sidebar grouped by department** | ✅ | ✅ | ☐ | `14dcc99` + this change |

→ Walk these through [VALIDATION-GUIDE.md](VALIDATION-GUIDE.md) §§0–8 and tick the **Validated** column here as each passes.

### B. Still to build (business part)

| # | Feature | State | Plan |
|---|---|:---:|---|
| #4 | Overdue → notify **Jawad owners** + maintenance **late fees** | 🟡 | add owners to SLA-breach recipients (small); late fees need decisions **O-3/O-4** |
| #11 | Department-to-department **messaging** | 🔴 | "Message department" action → notify the dept's members |
| #7 | **Master unit** / multi-unit lease | 🔴 | `lease_unit` pivot; isolated schema change |

### C. Deferred — out of the business part by decision

| # | Item | Why |
|---|---|---|
| #9 | Tenant-users (only tenant-admin submits) | your decision — current single tenant login already acts as the admin; full version rewrites mobile auth |
| #12 | Dept requests/payments routed via Accounting | pending your accounting team's workflow |

### Open decisions (needed before some items can finish)

| Ref | Decision needed |
|---|---|
| O-3 / O-4 | Maintenance **late fee** — what triggers it (past work-window vs SLA deadline?) and who is charged + how much |
| Notifications | Owner-requests (and future dept-to-dept) — **bell-only** (current) or **also email**? (email also needs SMTP configured) |

**✅ Part 1 is DONE when:** every row in **A** is Validated, **B** is built + validated, and **C** stays deferred (or is consciously pulled in).

---

## Part 2 — Next (after Part 1 sign-off)

> To be defined once Part 1 is signed off. Likely candidates (not yet chosen):
> production hardening (email/SMTP, queue worker, deployment) · mobile/tenant app · payments go-live (Paymob) · accounting-routing workflow (#12) · reporting/analytics depth.

_Pick the next part here once the business model is signed off._
