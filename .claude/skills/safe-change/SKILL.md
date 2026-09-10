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

## 7. Docs are part of "done"

Same commit: the module `docs/modules/NN-*.md`, and the `CLAUDE.md` invariant if the rule is
cross-cutting. Edit the existing home; never open a parallel one. Never hand-type a count a
registry already holds.

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

## 12. Say what you found and did NOT do

Report the wider defects you found, what you deliberately left, and the next decision that is his.
Offer the follow-up; do not silently expand scope.
