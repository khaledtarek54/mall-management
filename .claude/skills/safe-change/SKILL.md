---
name: safe-change
description: THE flow for every bug fix and every feature in Atriom — standard first (Yardi/market), best UI/UX, implement at one seam, prove by mutation, adversarial review, docs, commit, deploy, verify on the box, close the Trello card, recommend what is left. Use for ANY change to behaviour, not only "risky" ones.
argument-hint: <the card or the change> (e.g. "8k2NVX1D" or "raise the marketing levy to 6%")
---

# The flow — every bug, every feature

Khaled restated this on 2026-09-10 and it is not optional. Run the steps IN ORDER. Do not skip
review, and do not report "done" before the box says so.

---

## 0. Understand the ticket

Read the card, **watch the screenshot / recording** — this tester's cards are title + media, and the
detail is in the media. Reproduce on **staging** (`atriom.tri-tech.net`); the card's URL segment
says which property.

**Ask only what the CARD means.** Never ask what the behaviour *should* be — that is answered by
step 1, and it is your call.

## 1. The standard decides the behaviour — Yardi first

1. **Yardi Voyager** is the default. `docs/benchmarks/yardi/`.
2. Then the market: MRI · Entrata · (facility) IBM Maximo, ServiceChannel · (accounting) SAP,
   Oracle, NetSuite, Odoo.
3. Then Egyptian practice, where the Western tools model nothing (post-dated cheques, صيانة).

**Never invent behaviour.** Write the standard down in the commit message and the module doc —
*"Yardi does X, so we do X"*. **Being STRICTER than Yardi is also a deviation: say so and say why.**

## 2. Find the real seam before touching anything

Read `docs/modules/NN-*.md` → *Business rules*, *Extension points*, *Gotchas*. Then **grep for the
SHAPE, never work from the card alone** — on this board every card so far has been a narrower
report of a wider defect. Enumerate the doors onto the thing by grepping the thing, never from the
diff you just wrote.

**Run the doors, do not remember them** — `php artisan atriom:doors <Model>` lists every screen,
importer, exporter, API resource and service that writes that record. This is the most-repeated
defect in this codebase's history (the deposit modal that never got the bank field its six sibling
doors got; the fifteenth document-number allocator in the file below the fourteenth), and a
sentence telling you to enumerate them is not a gate — the command is.

## 2b. Before you commit, ask what the change did NOT touch

`php artisan atriom:doors --check-diff` reads your actual diff and names every door onto a record
you touched that you left alone. It exits non-zero when there is one.

**A non-zero exit is a question, not a verdict.** Leaving a sibling alone is very often right — an
operator's form and a tenant's portal form for one record differ by twenty fields, every one of
them a field a tenant must not be able to set. What is never right is not knowing. Say in the
commit message which doors you left and why; that sentence is the deliverable of this step.

## 3. Implement — one seam, no overengineering

- Business logic in **single-action services** (`app/Services`); pages and controllers stay thin.
- **One seam, not N call sites** (the container binding / one predicate both the button and the
  service read). **DERIVE, never re-list.** **Extract only on the SECOND real call site.**
- **Name the predicate once** so a button and its service cannot drift.
- Match the surrounding code's idiom and comment density. Comments say WHY and what was MEASURED.
- **Remove the stale thing in the same change** — a new design deletes the old.
- Honour the invariants in `CLAUDE.md` (money/AR four channels, posting dates, property isolation,
  deletion policy, `ValueSets`, morph map, locks). When in doubt, read it there rather than here —
  this file must not become a second, drifting copy of them.

## 3b. Configurable — the way the MARKET is configurable (Khaled, 2026-09-11)

**Nothing an operator could reasonably differ on ships as a literal.** Every threshold, period,
basis, default, rate, vocabulary and wording a rule reads comes from a SETTING, a CATALOGUE or a
WORDING BLOCK — the system is dynamic. But **which knobs exist, at which tier, with which default
is decided by the standard, exactly as the behaviour is (step 1)**: Yardi first, then the market.
A switch nobody in the market offers is a decision surface nobody reads, and a rule the market
configures that we hard-code is a deploy for a change the operator should make from a screen.

Ask three questions of every rule in the change, and write the answers into the commit:

1. **Is it configured in Yardi / the market, and at which TIER?** Lease term → the lease's own
   column, defaulted from the property (`PropertySettings::OVERRIDABLE`, with the REASON it may
   differ per building — an allow-list, not a default). Property policy → `PropertySettings`.
   Company policy → the `*Settings` group (`app/Settings/`). A vocabulary → an `IsCodeCatalogue`
   row, never a PHP list. Tenant-facing wording → a `DocumentText` block. Statutory figures →
   dated master-data rungs (`tax_rates`, `payroll_rates`), never settings.
2. **What is the DEFAULT?** The market's (Yardi's), so a fresh install behaves like the reference
   system, and **the client's own rule is what they SET** — a configuration act, recorded in
   STATUS/the module doc, applied on staging by hand and never baked in as the code default.
   Stricter-than-Yardi ships as a setting whose default is Yardi's.
3. **Is it READ, and by every consumer?** A setting that nothing consults is the inert-settings
   defect (`SettingsReachConformanceTest`, `PropertySettingsConformanceTest`); one the scheduler
   cannot honour is not a setting (`BillingDay`); one honoured by one module and ignored by its
   sibling means two things (`sla_working_clock_priorities`). Read at RUN time, never at
   schedule-definition time. Freeze the answer on the row where a later change must not re-price
   work in flight (a lease's terms, an SLA clock, an invoice's rate).

**Do not over-configure.** Two rules that the market treats as one setting stay one; a rule the
market treats as invariant (an invoice is immutable once issued, a deposit is a liability) is
NOT a toggle. Configurability follows the standard in both directions.

## 4. UI/UX is part of the fix, not a follow-up

- A refusal is **the app talking to a person**: translated EN **and** AR, naming the CAUSE and the
  way out. Never a raw column name, never a bare status string.
- The button's `visible()` and the service's guard read **the same predicate**.
- Field help where a bound would otherwise surprise someone (18-word budget).
- Check the screen in **both languages**; a raw `admin.*` key on screen is a shipped defect.

## 5. Prove it — a green test is not proof

- Regression test in `tests/Feature/Regression/`, named as a sentence about the behaviour.
- **MUTATION-PROVE every tooth**: remove the fix, watch that test go red, restore with an EXACT
  reverse edit. **Never `git checkout`** — it wipes uncommitted work in a shared tree.
- If a mutation stays GREEN the test is passing for the wrong reason. Find out why: usually a
  second guard covers for it, or the fixture cannot reach the state. Say which, in the test.
- Pair every refusal with a CONTROL that must still succeed.
- Run **targeted files only** — `vendor/bin/pest <file>`. **Never the full suite.** Kill any stale
  run first. Never Playwright.

## 6. Review before you commit — the review is worth more than the hunt

Launch an **adversarial subagent** on the actual diff (`Agent`, general-purpose): tell it to read
the code on disk and try to break the change — double-booking, concurrency, back-dating, bypassed
guards, an importer or API reaching a shape the form cannot, and comments that are factually false.

Across every sweep so far it has found something real in **every** change, and often the worst
finding was **in the fix itself**. Verify each finding against the code before acting — refute the
wrong ones out loud. Then fix, and add a test per finding.

## 7. Docs are part of "done" — EVERY doc the change touched, in the SAME commit

**A stale doc is worse than a missing one**, because the reader cannot tell which of two statements
is current and acts on the wrong one. So this is not "update the module doc": it is *leave no
document saying something the change just made untrue*.

**Update, in the same commit:**

- the module `docs/modules/NN-*.md` — *Business rules*, *Extension points*, *Gotchas*;
- the cross-cutting home if the rule is cross-cutting — `CLAUDE.md` invariant, and the topic's own
  document (`docs/PROPERTY-ISOLATION.md`, `docs/accounting/CHANGE-IMPACT-PLAN.md`, …);
- `docs/STATUS.md` if the change closes or opens something on it, and `docs/ROADMAP.md` if it
  finishes a listed item — **tick it there, never leave the work done and the row open**;
- `docs/gap-analysis/README.md` if it closes a gap;
- `docs/api/MOBILE-API.md` + the sync brief + `composer api-spec` for ANY `/api/v1` change — the app
  is a second codebase and the docs ARE the sync;
- the GENERATED blocks, by running the command, never by typing:
  `atriom:dump-system-census` · `atriom:dump-registries` · `atriom:dump-admin-manifest` ·
  `atriom:dump-handbook-data`. `GeneratedDocsConformanceTest` fails on drift.

**Then SWEEP for what the change falsified.** Grep the docs for the old behaviour, the old count,
the old class name, the method you just moved or deleted. A rule that moved leaves some docs red and
some quietly wrong, and only the red ones get noticed — the same trap as a moved declaration
blinding a gate. Counts and lists a registry already holds are never hand-typed.

**Edit the existing home; never open a parallel one.** If a topic already has a document, add to it.
Two documents on one subject is how the stale one survives.

## 7b. Before you push — the one command that answers "is it sound?"

`php artisan atriom:stability` runs every check this project has and gives ONE verdict, with an
explicit list of what it could NOT check. Exit **0** pass · **1** fail · **2** INCOMPLETE.

**Exit 2 is not a pass.** It means nothing broke and something was not checked — a tier that
examined nothing, a MySQL tier that silently skipped off MySQL, a browser suite that ran zero
specs. That state is the honest answer for a laptop run and the reason a release must not treat it
as green.

`composer hooks` installs `.githooks/pre-push`, which runs the fast tiers and refuses a red push.
That is the ONLY place a bad release can be blocked: the box installs `--no-dev`, so no step there
can ever run a test.

**Do not run the full suite yourself during ordinary work** — that rule is unchanged. This command
is what Khaled runs deliberately, and what the hook runs in its `--quick` form.

## 8. Commit and push

**The tree is SHARED with other sessions.** `git status` first, commit with **explicit pathspecs**
for your files only, then `git show --stat HEAD` to verify what actually landed. Never commit
another session's staged work. Push to `main`. End the message with:
`Co-Authored-By: Claude Opus 5 <noreply@anthropic.com>`

## 9. Deploy

`ssh root@144.91.115.90` → `/var/www/atriom-staging/current` → `sudo -u atriom-staging ./deploy.sh --yes`.
Push first — the box pulls `origin`. **Three `atriom:health` rows FAIL by design** on posture A
(`backup_capability`, `two_factor`, `demo_accounts`); anything else failing is yours.

## 10. Verify ON the box — this is the step that gets skipped

Not "it deployed". **Drive the behaviour**:

- Behavioural probes inside a **rolled-back `DB::transaction`**, then confirm the soak dataset is
  unchanged. **Never reseed that box.**
- Prove the check is not vacuous: make the bad state and watch it be refused / reported.
- Render the affected pages (expect 200) and grep the HTML for raw `admin.*` keys.
- Both locales for anything an operator reads.
- Logs: zero new ERROR since the deploy; queue 0 pending / 0 failed.

*(There is no production box yet — staging IS the deployed environment the tester works against.)*

## 11. Close the loop on Trello

Comment on the card — **short and precise**: what was wrong, what changed, the commit, what to
re-test on staging. Long reasoning belongs in the commit message and the module doc. **Verify the
SHA with `git rev-parse` before quoting it** (an invented one has had to be corrected in place).
Then move the card to `Done ✅`. A card needing HIS decision stays put, with a comment saying what
is needed.

**No card? Make one.** A change that came from a meeting, a client list or a defect you found
yourself gets a NEW, simple card created straight into `Done ✅` when it is finished — one card per
item, never an edit of an existing card, never a second item folded into one. The recipe is
[`/trello` §4b](../trello/SKILL.md). The board is the record of what shipped, whichever way the
work arrived.

## 12. Say what you found and did NOT do

Report the wider defects you found, what you deliberately left, and the next decision that is his.
Offer the follow-up; do not silently expand scope.
