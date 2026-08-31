# The operator's playbook — tricks, traps, and what is *not* here

**Who this is for.** Somebody who already knows roughly what the screens do and now wants the things
that are only learned by being bitten: what you can change without a developer, what fails **without
saying anything**, how to find out why a number is wrong, and where the honest edges of the system are.

**The other three walkthroughs are the *how*. This one is the *what nobody tells you*.**

- [SPACE-WALKTHROUGH.md](SPACE-WALKTHROUGH.md) — the estate
- [LEASING-WALKTHROUGH.md](LEASING-WALKTHROUGH.md) — the contract
- [RECEIVABLES-WALKTHROUGH.md](RECEIVABLES-WALKTHROUGH.md) — money in
- [PAYABLES-WALKTHROUGH.md](PAYABLES-WALKTHROUGH.md) — money out

---

## Table of contents

| Part | What it answers |
|---|---|
| [1](#1--the-ten-rules-that-cross-every-module) | The ten rules that cross every module |
| [2](#2--what-you-can-change-without-a-developer) | What you can change without a developer |
| [3](#3--twenty-things-that-save-you-an-hour) | Twenty things that save you an hour |
| [4](#4--the-silent-failures) | The silent failures — nothing breaks, nothing says so |
| [5](#5--when-a-number-is-wrong-a-diagnostic-order) | When a number is wrong — a diagnostic order |
| [6](#6--the-refusal-decoder) | The refusal decoder |
| [7](#7--the-rhythm) | The rhythm — daily to annual |
| [8](#8--what-is-not-here) | What is **not** here, honestly |
| [9](#9--decisions-that-are-waiting-on-you) | Decisions that are waiting on **you** |
| [10](#10--things-that-cannot-be-undone-later) | Things that cannot be undone later |

---

# 1 — The ten rules that cross every module

1. **Money records are never deletable — not even by a super admin.** Invoices, payments, journal
   entries, credit notes, vendor bills, expenses, deposits, payroll, cheques. Correct them through
   their own workflow — cancel, void, credit note, reverse — which leaves a document an auditor can
   follow. Master data with history (tenant, vendor, lease, unit, asset, employee) is refused too:
   **deactivate instead.**
2. **A derived number is never typed.** Invoice totals, `paid_amount`, `balance`, a bill's balance,
   a unit's status, a deposit's held figure, a custody's outstanding. If a field is greyed out, that
   is why.
3. **Everything is confined to one property.** You see one mall at a time. A NULL property is a real
   answer meaning *portfolio-wide* — and it is invisible to a naive filter (see 4.6).
4. **A rate is a dated rung, never a column.** VAT, withholding, payroll, utility tariffs. Enter a
   rise **in advance**; a document keeps the rate that was in force on its own date.
5. **Origination only — history never re-rates.** An issued invoice, an approved payroll run, a
   reconciled CAM pool. Nothing rewrites what was already billed.
6. **A closed period refuses.** Every operator-typed date that becomes a journal entry date. A
   *missing* period is allowed; only a **closed** one is refused.
7. **The ledger is derived, not journalled.** Change a document and the poster re-reads it, voids the
   old entry and posts a fresh one — which is why "may this edit move the books?" is a real question
   with a real answer per field.
8. **You cannot grant access you do not hold.** Property assignments are the grant, and the form
   refuses in both directions.
9. **A draft is not a document.** The tenant never sees one, on any surface.
10. **A refusal is usually the feature.** Before working around one, read what it says. Several of
    them exist because the workaround already cost real money once.

---

# 2 — What you can change without a developer

**This is the most under-used part of the system.** Almost everything people ask a developer for is
a row or a setting.

## 2.1 Catalogues — `/admin` → **Setup** (collapsed by default)

| Screen | What a row changes |
|---|---|
| **Charge codes** | Whether a supply is taxable, which tax code it uses, which GL account its revenue hits, its label in both languages. **Adding "key money" is a row** |
| **Tax codes** | The tax catalogue and its **dated rates**. VAT · stamp · schedule · withholding, both directions |
| **Payment rails** | A payment method, its direction, and the ledger account its money lands in. Fawry, Meeza, Vodafone Cash and Aman already exist, switched **off** |
| **Expense categories** | The P&L account a cost books to. **Anything unpointed silently lands in `admin_expense`** |
| **Posting map** | Which chart account each of the ~52 semantic roles resolves to — globally **or per property** |
| **Retail categories** | The tenant trade classification |
| **Rent indices** | Published CPI figures — the source a CPI-linked escalation measures against |
| **Utility tariffs** | Dated tariffs for meter recharges |
| **Request subcategories** | What a tenant may report, and which trade it routes to |
| **Violation categories** | House rules, and the **standard fine** each carries (a prefill, never a recompute) |
| **Trades** | The craft that classifies work orders, plans and equipment — and its **standard hourly rate** |
| **Failure codes** | The reliability vocabulary |
| **SLA policies** | Response and resolution targets |
| **Holidays** | The working calendar — Fri–Sat weekend, holidays, Ramadan short days |
| **Vendor document types** | Which certificates a supplier must hold, and **which of them block dispatch** |
| **Payroll rates** | The dated statutory ladder — insurable band and contribution rates |
| **Approval rules** | The value → approver bands |
| **Document wording** | The standing text on tenant-facing documents (2.3) |
| **Custom fields** | Your own fields on tenants, leases, units, vendors and properties (2.4) |

## 2.2 Settings, on three tiers

Many money terms resolve **lease → property → portfolio**. So the same clause can be a house
default, a mall policy, or one tenant's negotiated term:

| Term | Tiers |
|---|---|
| Late fee percent · grace · minimum · **cap** · **recurrence** | all three |
| Proration method (`actual` / `thirty_day` / `year_365` / `whole_month`) | all three |
| Payment terms days | property → portfolio, applied at **origination** |
| Security deposit months | property → portfolio |
| Monthly billing day | **per property** — the runs fire daily and ask whose day it is |
| Dunning follow-up days and max notices | portfolio |

`/admin/property-overrides` is where the middle tier lives.

## 2.3 The words on your documents are yours

`/admin/document-templates` — twelve blocks, both languages on one row:

- the invoice PDF's footer, **payment instructions** and terms,
- the invoice email body,
- and a body **and subject** each for the overdue reminder, the late-fee notice, the payment receipt,
  the lease-expiry notice, and the **final demand**.

A row with **no property** is the house default that every mall sees; a row naming a mall overrides it
there only — which is exactly what bank details need.

> **Two things this fixed:** the footer used to name three payment rails in hardcoded text while
> rails became a catalogue you can add to; and **no invoice showed bank details at all**, so a tenant
> holding one could not know where to pay.

The **final demand has no fallback wording deliberately.** Write it yourself or it does not go out.

## 2.4 Your own fields

`/admin/custom-fields` — extend **tenant · lease · unit · vendor · property**. Each field appears on
the form, as a (hidden-by-default) list column, as a filter, as a sort, on the importer, in the
export, and in **global search**.

Two rules: the **key** and the **model** are immutable (they are the address of every answer already
recorded); the **label** renames freely and reaches every record at once. Deactivating never blanks an
answer, and deleting is refused once anyone has answered.

> **Deploy step:** after adding a field that should be searchable, run `atriom:rebuild-search`.

## 2.5 Modules you can switch off

**34** optional modules at Settings → Modules, grouped in sidebar order. Switching one off removes its
navigation, its resources, its actions **and its scheduled work**.

Two rules: **only a super admin may move a module switch** (that permission is grantable, and whoever
holds `roles.edit` could grant it to themselves), and **ETA e-invoicing is frozen in code**, not
merely off — a stale settings row cannot bring it back.

## 2.6 Saved views and column layouts

Any list with three or more filters offers **saved views**: filters + search + sort + **which columns
and in what order**, shareable with the team.

One can be the view that **opens** by default. A personal default **beats** a shared one, so marking a
team view never overrules a colleague who chose their own. *"All records"* in the menu is the escape
back to the plain list.

---

# 3 — Twenty things that save you an hour

1. **⌘K / Ctrl-K** opens global search from anywhere. It searches a folded blob, so Arabic spelling
   variants match — «شركة»/«شركه», «أحمد»/«احمد» — and it reaches a tenant's phone, WhatsApp, tax ID,
   commercial register and Arabic trade name, not just the name.
2. **⌘S saves a form. ⌘⇧S saves and creates another.** The full list is in the user menu →
   *Keyboard shortcuts*.
3. **Every list has a Guide button** that explains that screen in both languages — including
   *what moves elsewhere* when you touch it, which nothing else tells you.
4. **`/admin/handbook`** is the whole system as pictures, bilingual, in the panel.
5. **Run the billing preview before the run**, every month. It *is* the run minus the writes.
6. **Read the refusals in the preview** rather than scrolling to the total. `already_billed` and
   `no_applicable_charges` are where lost revenue hides.
7. **`/admin/month-end-close`** answers *"is this month ready?"* in seven ordered rows. Its
   `ledger_in_sync` row catches the same assertion the close itself throws — so a green checklist
   means the close will succeed, not fail at the last click.
8. **On a CAM allocation, use "View working."** Share → allocated → ceiling → capped → absorbed →
   estimate → true-up → VAT → admin fee → net invoiced. The visible columns stop adding up the moment
   a cap bites, which is the one time anyone looks.
9. **Download modal → language picker.** A document is written in its **reader's** language, not
   yours. It pre-selects the tenant's own language; you can override per download.
10. **Re-send an invoice** is a first-class action, and it stamps when it was sent. You do not need to
    download and attach it to your own email.
11. **The tenant statement PDF** answers *"what do I actually owe?"* in one document. Use it before
    a collections call.
12. **AR aging by charge type** shows the disputed figure **beside** the aged one, never netted out.
13. **The record page is where you act.** Row actions moved onto records deliberately — the list finds,
    the record acts.
14. **Filters are remembered per list** — including across sessions. If a list looks empty, check the
    filter bar first. (The search box is deliberately cleared when you switch property.)
15. **Export is available wherever you can read the list**, and it exports **your current filters**,
    not the whole table.
16. **`Sync from ledger`** on a CAM pool — if the pool is derived and has never been sourced, the
    estimate reads 0 and the variance is nonsense until you press it.
17. **Post month** is the honest answer to a late supplier bill, instead of re-dating the document or
    reopening a period.
18. **Add a note before you void anything.** Every reversal takes a reason, and the reason is what a
    colleague reads six months later.
19. **The activity log records almost every column** an operator can change, in both languages, with
    the record named rather than an id. It is the fastest way to answer *"who changed this?"*.
20. **`/admin/configuration-health`** answers *"is this install actually set up?"* — eight checks. It
    is a different question from *"is it alive?"*, and it is the one that bites at cut-over.

---

# 4 — The silent failures

**Every item here is a real one that shipped.** They share one shape: *nothing errors, nothing is
red, and a screen quietly under-reports or a run quietly does nothing.*

## 4.1 A lease that bills nothing

Overlapping charge rows, a gap, or no start date. The run reports `no_applicable_charges` and moves
on. **Find them:** `php artisan atriom:audit-charge-schedules`.

## 4.2 A one-off invoice suppressing the month's rent

A standalone invoice dated into a month the recurring run also bills. It has happened **six** times,
and the sixth (a security-deposit invoice, whose period is the lease's **whole term**) suppressed the
rent for **every month of a three-year lease**, reported as an ordinary `skipped`.

**Symptom:** a lease with no rent invoices and a clean-looking run summary.

## 4.3 A back-dated charge that never bills

The run refuses to bill a month twice, so a charge added into a billed period is raised by nothing.
**You are told at the moment you add it, with the covering invoice named.** Act on that toast.

## 4.4 A stored status going stale on a day when nothing happened

`units.status`, `leases.status` and `rentable_items.status` are functions of *today*. A give-back
effective 1 January and recorded in August left a unit reading `occupied` from January onward. The
nightly `leases:expire` sweep fixes all three — but if it is not running, nothing says so.

## 4.5 A CAM pool that was never sourced

`expense_basis = ledger` means the total is **computed** — *but only when somebody presses Sync from
ledger*. Measured live: actual 500,000 · estimated **0** · variance 500,000, against a true variance
of **154,000**. The allocations were right throughout; only the header lied.

## 4.6 Money filed against no property

`WHERE asset_id IN (…)` never matches NULL. Journal entries filed against no mall were invisible in
**all five** financial statements with nothing on the page to say so. The statements now print a
notice sizing what they left out. **Fix the rows:**
`php artisan atriom:audit-property-dimension`.

## 4.7 A stated CAM share added after the first run

Shares **freeze** on the first reconciliation. A stated share added afterwards does **nothing**, and
nothing on screen distinguishes the two.

## 4.8 An expense category with no ledger account

It silently books to `admin_expense`. Insurance, government fees, bank charges, legal fees and
generator fuel all did, for the life of the six-entry code list.

## 4.9 A drafted purchase request nobody submits

The low-stock scan drafts one per property and **refreshes** it. If nobody submits, nothing is
ordered — the draft simply keeps updating.

## 4.10 GRNI that never clears

A goods receipt entered through the ad-hoc stock action carries no source link, so no bill can ever
clear it. Measured on the demo books: **166,120 EGP of GRNI credits, zero debits.** Route purchases
through a purchase request.

## 4.11 An orphan receipt

A payment allocated to nothing is invisible in the property-scoped UI and unspendable. The form
refuses to create one — but a cleared cheque naming no invoice used to mint exactly that, and **the
tenant was chased while the mall held their cleared cash.**

## 4.12 A frozen tax column on a charge row

`vat_rate` and `vat_applicable` are **overrides**; null is the normal state. A row carrying a value
ignores the catalogue for ever. The schedule table marks such a row ⚠ — look for it.

## 4.13 A scheduled job that is not running

Every automatic behaviour in this system is a scheduled command. If the scheduler is down, nothing is
red — invoices simply do not appear, arrears are not chased, the ledger drifts and no unit is
re-projected. **`atriom:notify-status` posts to Discord only when the set of failing health rows
CHANGES**, so silence means "no change", not "healthy".

---

# 5 — When a number is wrong, a diagnostic order

Work down this list. Nine times in ten it stops at step 3.

| # | Ask | Where |
|---|---|---|
| 1 | **Which mall am I in?** | The property switcher. Half of "the data is missing" is this |
| 2 | **What filters are remembered?** | The filter bar — lists remember filters across sessions |
| 3 | **What does the document itself say?** | Open the record, not the list. The list may be showing a derived or grouped figure |
| 4 | **Which of the four channels settled it?** | An invoice can be `paid` with no cash. Check credit notes, tenant credit and deposits |
| 5 | **Who changed it, and when?** | The Activity tab on the record |
| 6 | **Does the document's entry match the document?** | `php artisan billing:reconcile --deep` names the document |
| 7 | **Is the period closed?** | A refusal you did not see is often a closed period |
| 8 | **Is the pool/estimate derived and unsourced?** | CAM: Sourced-at column |
| 9 | **Is the schedule broken?** | `atriom:audit-charge-schedules` |
| 10 | **Is it filed against no property?** | `atriom:audit-property-dimension` |

## 5.1 The three commands worth memorising

```bash
php artisan atriom:preflight                  # health + config health + both audits + a deep reconcile
php artisan billing:reconcile --deep          # WHICH document disagrees with the ledger
php artisan atriom:config-health              # is this install actually SET UP — 8 checks
```

`atriom:health` answers *"is it alive?"*. `atriom:config-health` answers *"is it set up?"*. They are
different questions and only the first used to be on the release gate — which is how a release could
report clean with **no seller tax number, an incomplete posting map and no open accounting period.**

---

# 6 — The refusal decoder

The per-module decoders are in each walkthrough. These are the ones that cross modules.

| Refusal | Meaning | Do |
|---|---|---|
| A 404 on a property URL | You do not hold that mall | Ask someone who does. You cannot grant yourself one |
| A date is refused | Its accounting period is **closed** | Re-date into an open period, or set a **post month** |
| Delete is not offered at all | It is a money record, or master data with history | Cancel / void / credit / reverse — or deactivate |
| Delete is refused with a list | Something references it | Deactivate instead |
| A field is greyed out | It is derived, or the document is finalised | Use the act that owns it |
| A button is not there for you | Your role does not hold that right — or the module is switched off | Two different causes; check Settings → Modules first |
| A picker is empty | Genuinely no rows, or your filters have narrowed it | Every picker now opens on up to 50 rows, so empty usually means empty |
| *"No options available"* | The register really is empty | Go and create one |
| A save reports the books moved | The edit re-derived a posted entry | Read the figures in the toast — they say what was reversed and what replaced it |

---

# 7 — The rhythm

## Every day (automatic — you read the results)

Billing sweeps · overdue owner alert · overdue tenant reminder · late fees · recurring costs ·
lease expiry + occupancy re-projection · escalations · SLA breach scans · PM generation ·
document-expiry scans · cheque maturity · missing sales declarations · ledger sync

## Every week

- Submit or cancel the drafted purchase requests.
- Fill in supplier references on drafted recurring bills.
- Read what `billing:reconcile --deep` names.
- Check `pdc:scan-coverage` — **which tenants are about to run out of lodged cheques.**

## Every month

| Order | Do |
|---|---|
| 1 | **Billing run preview** per mall → read the refusals → post |
| 2 | Chase sales declarations (turnover rent cannot bill without them) |
| 3 | Record receipts; clear matured cheques |
| 4 | Enter and approve every supplier bill for the month |
| 5 | Settle or return outstanding custodies; run payroll |
| 6 | **`/admin/month-end-close`** → fix every red row |
| 7 | Close the period on Accounting Periods |

## Every quarter

Form 41 — reconcile the withholding tie-out, then issue supplier certificates.

## Every year

CAM reconciliation and true-up · percentage-rent settle-up · the fiscal-year close · re-estimate next
year's service charge.

## Before any release

```bash
php artisan atriom:preflight
```

---

# 8 — What is **not** here, honestly

> Verified against the code on 2026-08-31, not copied from a wish list. The full analysis, with
> severity and effort, is [`../gap-analysis/README.md`](../gap-analysis/README.md).

## 8.1 Buildable, and genuinely not built

| What | Where it bites |
|---|---|
| **Lease document generation and e-signature** | A lease only *holds* an uploaded PDF. Nothing generates one from a template, merges the terms into it, or signs it. A daily workflow for an operator onboarding continuously — **the biggest single gap** |
| **Conditions on a fit-out permit** | The decision ships (approve/reject with a recorded reason). What is missing is *what was granted*: permitted hours, a security deposit, contractor details, and an audit trail of the permit itself |
| **Capex bid / quote comparison** | One vendor per request. No tender, no *"three quotes compared"* on tier-3 spend. Owners ask about this |
| **Barcode parts issue and guided cycle counts** | Issue, receive and count are web forms; counts are ad-hoc adjustments with no guided workflow or freeze |
| **A repeat-violation ladder** | Fines are priced by hand. The register makes the pattern visible and nothing reads it |
| **A consolidated P&L across malls** | The books support it; **no screen offers it.** A combined statement for an owner holding two malls is two PDFs and a spreadsheet today. This reopens the All-Properties decision |
| **A management-fee line** | See 8.3 — it is blocked, not merely unbuilt |

## 8.2 Deliberately declined, with reasons

| Declined | Why |
|---|---|
| **POS / automated sales feed** | Egyptian tenants will not expose a POS. File-first + a mobile declaration is the correct market fit |
| **A technician mobile app** | Technicians use the admin panel under their own role — which makes that role's experience a requirement rather than an afterthought |
| **Bank deposit batches** | Cheques and transfers dominate this market |
| **Interest-bearing / segregated deposits** | Not an Egyptian requirement |
| **Multiple books** | Single book, one chart, property as a dimension |
| **Multi-currency** | EGP only, enforced. There is no FX anywhere, and a currency field that accepted another value would be a lie |

## 8.3 Blocked on somebody else, not on code

| # | What | Blocked on |
|---|---|---|
| **Management fee** | Recorded and **charged by nothing**. The field says so on its face, and a test fails the day someone builds it | The accountant naming the GL account — it is Eltizam's revenue, not the property's, and guessing puts the operator's income in the owner's P&L |
| **Live card payments** | Built, ships **off** | Paymob certification — external |
| **ETA e-invoicing** | **Frozen in code**, shown nowhere. Services and tests kept | A signing certificate and production credentials — *and* lifting the freeze first |
| **Statutory payroll amounts** | The dated ladder is built and ships at **0 · 0 · 0** | The accountant confirming the rates. All three at nil means **net = gross on every payslip and no liability in the books** |
| **Withholding rates** | Engine, quarterly Form 41 and per-supplier certificates all built | Confirming the rates by supply type — published summaries disagree (1 / 2 / 3 / 5%) |
| **The real chart of accounts** | Importer built, order-independent, keyed on `code` | The accountant's actual Egyptian chart (the file supplied earlier was a Saudi contracting template and was rejected) |
| **Opening balances** | Screens and importers built | Your numbers and your cut-over date |

## 8.4 Things that are here and are usually assumed missing

Worth knowing, because they get re-requested:

- **A stacking plan** — `/admin/occupancy-map` (it was called absent by an earlier analysis that
  grepped for the word).
- **CPI / index-linked escalation** — the `rent_indices` register, a lag in months, a base value, and
  a collar. It records a published figure; it does not invent one.
- **A contracted revenue forecast** — `/admin/revenue-forecast`, projected from the charge ladder.
  The speculative half (assumed renewals and re-lets) is deliberately absent: a guessed figure is
  indistinguishable from contracted income on a chart an owner may be shown.
- **A contractor portal** — `/vendor`. A supplier contact signs in and can **accept · update ·
  evidence · quote**. Marking a job *done* is deliberately not one of the four: a contractor saying
  "finished" is a claim; the operator's completion is a decision.
- **Straight-line rent recognition** (EAS 49 / IFRS 16) — built, ships **off**, invoices identical
  either way.
- **Bank reconciliation** — statement import and a matcher.
- **Budget vs actual** — per P&L account, per property, per month, with paste entry.

---

# 9 — Decisions that are waiting on **you**

The full list with what ships if you say nothing is [`../STATUS.md`](../STATUS.md) §2–§4. The ones an
operator feels first:

| Question | What ships if you say nothing |
|---|---|
| **Is base rent taxed now, under Law 157/2025 — and from what date?** | Exempt. It is **configuration**: point the charge code at a tax code, and the rate is a dated rung. No release needed |
| **The final month of an expiring lease — is the tenant credited for days they did not occupy?** | Full month billed, unearned part credited at move-out on the lease's own proration rule. On a 30k lease ending on the 10th: ~20,300 under `actual`, 20,000 under `thirty_day`, **nothing** under `whole_month` |
| **Which SLA priorities run on WORKING time?** | **None — every priority runs on bare hours.** So an urgent job raised Thursday 17:00 is due Friday 17:00 with nobody on site, **and a vendor penalty is charged off that clock** |
| **The returned-cheque fee** | **0**, and the action stays hidden until you price it |
| **A vendor SLA penalty is booked as a cost reduction, so the saving flows into the CAM pool tenants reimburse** — intended? | The benefit reaches tenants |
| **Auto-apply open credit** | **On** (Voyager's behaviour). A credit raised while a charge is disputed will otherwise be consumed by the next invoice |
| **Which roles must have two-factor authentication?** | **Nobody is forced.** Switching it on marches every listed role through TOTP at next login — schedule it |
| **A user with no property assigned — see nothing, or everything?** | The two layers disagree today; the result is an account that can open no page |

---

# 10 — Things that cannot be undone later

Decide these **before the first real invoice.**

| Decision | Why it is one-way |
|---|---|
| **The property code** | It is inside every document number. Changing it starts a second series |
| **The document number scheme** (continuous / annual / monthly) | After the first issued invoice the prefix is printed on documents that cannot be renumbered |
| **The fiscal year's start month** | **Refused once anything is posted** — moving it re-dates every period. Free on an empty install |
| **The seller tax registration** | Until it is set the invoice is titled plainly *Invoice*, not *Tax Invoice* — an unconfigured install issues an **incomplete** document rather than a confidently wrong one. Tenants cannot reclaim VAT against it |
| **The chart of accounts** | Importable and re-importable, but every posted entry points at an account **id**. Load the real chart before you post |
| **The cut-over date and opening balances** | The first trial balance is wrong by exactly the history preceding it |
| **A lease's master unit** | Can never change. Moving out of it is a relocation |
| **A custom field's key** | It is the address of every answer already recorded |
| **A charge code / tax code / rail code** | Stored on every document that carries it. Retire the row; never re-code it |

---

## Related

- [`../STATUS.md`](../STATUS.md) — where the build is, and every open decision
- [`../gap-analysis/README.md`](../gap-analysis/README.md) — the full measured comparison
- [`../EGYPT-MARKET-FIT.md`](../EGYPT-MARKET-FIT.md) — what you can change without a developer, against Egyptian law and practice
- [`../BUSINESS-RULES.md`](../BUSINESS-RULES.md) — every financial rule, in plain language, for sign-off
- The **Guide** button on every screen, and the handbook at `/admin/handbook`
