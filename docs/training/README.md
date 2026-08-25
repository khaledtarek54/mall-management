# Training — the people-facing walkthroughs

**What lives here.** One walkthrough per module, written for someone **new to the business** — not to
the codebase. No property, retail-leasing or accounting background assumed.

**What does NOT live here.** Anything a module doc already says. These walkthroughs *link* to
[`../modules/`](../modules/README.md) rather than restating it, because two documents describing one
module is how a stale one survives.

| Document | Covers | For |
|---|---|---|
| [LEASING-WALKTHROUGH.md](LEASING-WALKTHROUGH.md) | Module 04 — the lease record, its 6 form tabs, its 13 record tabs, all 13 actions, the list, the nightly jobs, and **16 hands-on exercises** | A new team member who has to understand leasing from nothing |

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
