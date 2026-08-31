# Training — the people-facing walkthroughs

**What lives here.** One walkthrough per module, written for someone **new to the business** — not to
the codebase. No property, retail-leasing or accounting background assumed.

**What does NOT live here.** Anything a module doc already says. These walkthroughs *link* to
[`../modules/`](../modules/README.md) rather than restating it, because two documents describing one
module is how a stale one survives.

| Document | Covers | For |
|---|---|---|
| [SPACE-WALKTHROUGH.md](SPACE-WALKTHROUGH.md) | Modules 01 · 30 · 35 · 37 — the property, its floors, the unit register, why a unit's status is a **projection**, the two occupancy numbers, dated areas, the two floor plans, rentable items and unit owners | Anyone, before they touch a lease |
| [LEASING-WALKTHROUGH.md](LEASING-WALKTHROUGH.md) | Module 04 — the lease record, its 6 form tabs, its 13 record tabs, all 13 actions, the list, the nightly jobs, and **16 hands-on exercises** | A new team member who has to understand leasing from nothing |
| [RECEIVABLES-WALKTHROUGH.md](RECEIVABLES-WALKTHROUGH.md) | Modules 05 · 06 · 07 · 08 · 09 · 33 — the monthly run, proration, tax on the line, the **four settlement channels**, credit notes, deposits, cheques, the recoveries, the dunning ladder, and what each act does to the books | Whoever raises the invoices and chases the money |
| [PAYABLES-WALKTHROUGH.md](PAYABLES-WALKTHROUGH.md) | Modules 12 · 25 · 29 (+ 22 · 23 · 24 · 26) — the five roads money leaves by, the dispatch gate, the vendor bill, **withholding tax**, voiding a payment, procurement and the approval tier, **GRNI**, custody, and the SLA penalty charged onto a supplier's bill | Whoever pays the suppliers |
| [OPERATOR-PLAYBOOK.md](OPERATOR-PLAYBOOK.md) | Cross-module — what you can change **without a developer**, twenty time-savers, the **silent failures**, a diagnostic order for a wrong number, the daily→annual rhythm, and an honest list of what is **not** here | Anyone already using the system daily |

## How they are written

- **The business first.** Part 0 of each walkthrough explains what the module is *for* before naming
  a single field.
- **Every field, every button.** *What it is · what to type · what it changes · when it locks.*
- **A hands-on exercise set**, run against `LearningSeeder` — the empty mall — so that every number on
  screen is one the reader put there. Each exercise states the expected figures, computed from the
  same formulas the code uses.
- **Refusals are documented as features.** A newcomer meets *"already billed"*, *"off cycle"* and
  *"this unit already has an active lease"* in their first hour; each walkthrough has a lookup table
  that decodes them.

## Related

- [`../DEMO.md`](../DEMO.md) — showing the system to somebody in 30 minutes (a different job: a demo,
  not a course).
- [`../accounting/WALKTHROUGH.md`](../accounting/WALKTHROUGH.md) — the same idea for the books.
- The **visual handbook**, in the panel at `/admin/handbook`.
- The **Guide** button on every list screen, which explains that one screen in both languages.
