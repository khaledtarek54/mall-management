# Showing the accountant that the books are reliable — a 20-minute run

> Companion to [ACCOUNTANT-BRIEFING.md](ACCOUNTANT-BRIEFING.md) (the bilingual hand-out; PDF beside
> it). This is the *demo*: what to open, in what order, and what each step proves.
>
> **Showing someone who is NOT an accountant?** Use [../DEMO.md](../DEMO.md) instead — same idea,
> different question (does one event travel end to end, rather than do the books hold).
>
> **Every figure below was run on the demo data on 2026-07-31.** If you reseed, the numbers move —
> re-run the two commands and read the new ones off the screen.

An accountant is not persuaded by features. They are persuaded by **being unable to break it**, and
by recognising their own vocabulary. So the run below is mostly you inviting them to attack, and
the system declining.

---

## 0 · Before they arrive (2 minutes)

```bash
php artisan billing:reconcile     # expect: ✓ Books tie out — all checks passed
php artisan atriom:health         # expect: no FAIL rows that matter on this machine
```

If `billing:reconcile` reports anything other than all-green, **stop and fix it before the meeting**
— that command is the centrepiece and it must pass live.

---

## 1 · Start where they think: the sidebar (1 min)

Open `/admin`. Don't narrate the software. Let them read the left-hand nav:

**Leasing · Receivables · Payables · General Ledger · Operations · Facility · Inventory & Assets ·
HR & Payroll · Marketing · Settings**

Say: *"The system is organised the way the money moves."* Then open **General Ledger** and let them
see their own shelf — Chart of Accounts, Journal Entries, Accounting Periods, Owner Statement Runs.

**Why it lands:** they are looking for whether this was built by someone who understands books or
someone who understands screens. The word "Receivables" sitting above "Payables" above "General
Ledger" answers that before you speak.

---

## 2 · The trial balance balances (2 min)

**General Ledger → Journal Entries.** Show the list, then the totals.

> On the demo data: **683 journal entries, all posted. Debits 24,704,432.20 — credits
> 24,704,432.20. Difference 0.00.**

Say: *"Every entry in this system is double-entry. Nothing posts unless it balances."*

**Why it lands:** it is the first thing an accountant checks in any system, and most demos cannot
show it.

---

## 3 · Hand them the tie-out (4 min — the centrepiece)

Run this **on screen**:

```bash
php artisan billing:reconcile
```

It re-derives the receivables **independently of the ledger** and checks the two agree:

| Check | What it proves |
|---|---|
| Invoice total = line subtotal + VAT | no invoice header drifted from its lines |
| Paid = captured payments + applied credits | the paid figure is derived, never typed |
| Balance = total − paid | no manual balance override exists |
| No payment allocated beyond its amount | a receipt cannot over-settle |
| Marketing accrued = billed levies | the levy accrual matches what was billed |
| CAM allocations tie to the pool | the year-end recovery reconciles |
| **GL AR/AP ties to source documents** | **the ledger and the subledger agree** |

Then the control totals, which are what they will actually want to reconcile against:

> 279 invoices · invoiced **10,795,711.06** · collected **9,442,293.93** · credits applied
> **11,396.86** · outstanding AR **1,353,417.13** · VAT **199,596.91**

Say: *"These are the numbers to reconcile against your books. The command re-derives them from the
source documents, so if the ledger and the documents ever disagree, it says so — I am not asking
you to trust the ledger's own opinion of itself."*

**Why it lands:** you handed them an audit tool instead of a dashboard.

---

## 4 · Try to break it — invite them (6 min)

This is the part that convinces. **Hand them the mouse.**

### a) "Delete this invoice"

Open any paid invoice. **There is no delete button.** Not for you, not for the system owner.

Say: *"A financial document is a record of something that happened. You cancel it or you credit-note
it, and the trail survives. The only way to change what a tenant owes is to leave a document
explaining why."*

Show the correction path instead: **Cancel** (voids the GL entry) or **Credit note** (un-applies
against the original). Same for payments — **Void** reverses the GL and re-opens the invoice.

### b) "Post something into a closed period"

**General Ledger → Accounting Periods.** `2024-01` is **closed**.

Try to date anything into it. The system refuses:

> *Accounting period 2024-01 is closed, so this cannot be posted to the ledger. Reopen it, or use a
> date in an open period.*

Say: *"Once you close a month, it is closed. Nothing back-dates into it — not an invoice, not a
payment, not a vendor bill, not a stock movement."*

### c) "Change a posted journal entry"

Open a posted entry. It is read-only; the Save action is gone. Corrections are a **reversing entry**,
which is what they would do on paper.

**Why this section lands:** you are not claiming the system is careful. You are letting them fail to
be careless.

---

## 5 · The chart of accounts is theirs (3 min)

**General Ledger → Chart of Accounts.** Show the tree, the account codes, and that postable leaf
accounts are distinguished from rollups.

Then the point that matters most to them: **Settings → Tax**, and the account mappings — *which
account each kind of money posts to* is configuration they own, not code.

Say: *"You tell us the account, we post to it. If your chart differs from this starter one — and it
will — you are not waiting on a developer."*

Then open the briefing PDF at **§4 (role → account mapping)** and walk it with them. That is the
document you want them to take away and mark up.

---

## 6 · Be straight about what is not done (2 min)

Say these unprompted. Credibility comes from the gaps you volunteer, and an accountant will find
them anyway:

- **e-invoicing is switched off and frozen in the code** — nothing has ever been submitted to the tax
  authority, and the module is deliberately unreachable rather than merely disabled. Do not present
  it as available or nearly-available.
- **The chart of accounts is a starter chart**, not theirs. Replacing it is the first thing we want
  from them — and it can now be **imported** from their own file rather than keyed.
- **Tax treatment is a row and the rate is a dated rung** — if 14% is wrong for any supply, or if
  Law 157/2025 makes rent taxable from a date, that is a field and a row, not a release.
- **Payroll tax brackets are not modelled progressively** yet; we need their confirmed brackets. The
  statutory numbers ARE a dated ladder now, so a January change is enterable in advance.
- **The open questions are in [OPEN-QUESTIONS.md](../OPEN-QUESTIONS.md)**, which was re-verified
  against the code on 2026-08-23 and is now considerably shorter — two dozen rows closed because the
  system already answered them. Section 1 is what blocks the first real invoice; most of it is theirs.

---

## What to do with what they say

Anything they answer goes straight into `docs/OPEN-QUESTIONS.md` **in the meeting** — the row, their
answer, the date. The `A·` rows are the accountant's, and they are the single thing between the
accounting module being correct and being finished.

If they push on something not covered here, do not guess in the room. Write it down as a new row.
The one thing that will cost credibility is an invented answer they later discover was wrong.
