# 02 — Yardi Voyager: charge → AR → cash → GL

> The money flow, end to end. Confidence markings per the [README](README.md#sourcing--confidence).
> Atriom comparisons are pointers only — the verdicts live in
> [06-atriom-gap-analysis.md](06-atriom-gap-analysis.md).

---

## 1. The shape of the flow

```
        LEASE CHARGE SCHEDULE                          (date-ranged rows, §01.3.2)
                  │
                  │  monthly posting run, per property, per POST MONTH
                  │  → reviewable BATCH → post
                  ▼
        CHARGE TRANSACTIONS  ── each is an OPEN RECEIVABLE ──►  Dr AR / Cr Revenue (+ Cr Tax)
                  │                                              at the charge code's account
                  │                    ┌──────────────────────────────────┐
                  │                    │  presentation only:              │
                  │                    │  INVOICE / STATEMENT rendered    │
                  │                    │  over the charges                │
                  │                    └──────────────────────────────────┘
                  ▼
        RECEIPT (cash in) ── applied to open charges, in application order ──► Dr Bank / Cr AR
                  │
                  ├─ unapplied remainder  ──►  OPEN CREDIT / PREPAYMENT on the lease
                  │                             (optionally Cr Unearned Rent, a liability)
                  ├─ NSF / returned       ──►  reverses the receipt, re-opens the charges, + NSF charge
                  └─ deposit codes        ──►  Dr Bank / Cr Deposits Held (a liability), deposit register

        Adjustments:  credit charge (negative) · write-off (bad debt) · charge reversal
        Period:       every transaction carries a POST MONTH; closing the month locks it
        Books:        the same event can produce different GL under Accrual / Cash / IFRS books
```

---

## 2. What a charge actually is

A posted charge is a row with: lease · charge code · **amount** · **date** · **post month** ·
due date · tax · description · **open balance**. It is simultaneously:

- **the receivable** — its own open balance, its own age, its own dispute status
- **the GL event** — `Dr Accounts Receivable / Cr <charge code's revenue account>` at post time
- **the recovery/percentage-rent input** — a `CAMEST` charge is what the year-end reconciliation
  compares actuals against; charge codes flagged as deductible reduce percentage rent

**Charge-level AR is the single biggest structural difference from Atriom**, and it buys four
things:

1. **Aging by what is owed, not just by whom.** "60 days past due" reads very differently when it
   is a disputed CAM true-up versus base rent.
2. **Targeted settlement.** A tenant paying "rent but not the marketing levy pending a dispute" is
   a normal instruction Voyager can execute exactly.
3. **Line-level dispute.** Freeze the CAM charge, keep chasing the rent.
4. **Line-level write-off.** Write off the late fee, keep the rent receivable.

The cost is that there is no single legal document per period — which is precisely why an
ETA-filing jurisdiction pushes you the other way. See the [README](README.md) and
[gap §8](06-atriom-gap-analysis.md).

---

## 3. Posting: post month vs transaction date

**Every Voyager transaction carries two time coordinates:** the **date** (when it happened, what
prints on the document) and the **post month / post period** (which accounting period it lands in).
They are independent.

*(cited: "The current Post Period should be entered in the As of Month Filter when running
accounting reports during month-end close"; a documented failure mode of bank reconciliation is
"post-month and cutoff mismatches".)*

This matters more than it looks:

- An invoice dated **28 February**, keyed on **3 March** after February closed, posts to **March**.
  The document keeps its February date for the tenant and for tax; the GL sees March. Nothing is
  refused, nothing is backdated into a closed book.
- Closing a period blocks posting *into* it. Periods can be reopened, but that is a deliberate,
  documented, controlled act *(cited: "Yardi allows you to reopen periods, but this should be done
  sparingly and with proper documentation")*.

> **Updated 2026-08-11 — Atriom now has both coordinates.** The paragraph below described the state
> when this benchmark was written; the post month shipped as story MF-05 and §10 row 2 records it.
> Kept rather than rewritten because the *reasoning* for wanting the second coordinate is still the
> clearest statement of why it matters. `posting_month_overrides` + `App\Support\PostMonth` move the
> **journal entry** without touching the document, so the tenant and the ETA still see its real date;
> the override is applied in `LedgerPoster` where every payload is built, and a CLOSED target month is
> still refused (this reaches an open month with an honest document date, it does not reopen a sealed
> period). Exposed as `PostMonthAction` on the invoice and vendor-bill tables.

*(As written, before MF-05:)* **Atriom has the close guard but not the second coordinate.**
`App\Support\PostingDateGuards` refuses a GL date whose period is closed — which is correct and
well-built — but because `entry_date` *is* the document date, the operator's only options are to
backdate-and-be-refused or to alter the document's real date. Yardi's answer, "keep the date, move
the post month", is not expressible. See [gap §11](06-atriom-gap-analysis.md).

---

## 4. Receipts and how cash is applied

A **receipt** is entered against the **lease** (or the customer): amount · date · **post month** ·
payment method · reference/cheque number · bank account · deposit batch.

Voyager then **applies** it to open charges:

- **Automatically**, by an application order that typically runs: existing **open credits** first,
  then by **charge-code priority**, then oldest-first within a code ***(verify — the order is
  configurable per AR settings, and shops tune it)***
- **Manually**, line by line, when the tenant's remittance advice says what they are paying for

**Unapplied remainder → open credit.** The leftover sits on the lease as a credit balance and
auto-applies against the next posted charge. Configuration decides whether that credit is merely a
negative AR balance or is posted to an **unearned/prepaid rent liability** account —
which is the accounting-correct treatment when a tenant pays January's rent in December
***(verify — the setting exists; its default varies by install)***.

*(cited: Voyager's AR configuration carries settings for "unapplied charge codes and prepayments".)*

**NSF / returned cheque** reverses the receipt, re-opens every charge it had settled, and posts an
`NSF` fee charge. **Deposit batches** group receipts into a single bank deposit so bank
reconciliation ties to what the bank actually saw — a step Voyager treats as first-class and a
frequent source of reconciliation failures when skipped *(cited)*.

**Atriom's equivalents exist and are good.** Payments allocate to invoices through the
`invoice_payment` pivot with `allocated_amount`, guarded by a lock-and-recheck over-allocation
assertion and a cross-tenant guard ([`docs/money/00-money-model.md`](../../money/00-money-model.md)).
On-account credit is a real, separately-journalized document
(`TenantCreditApplication`, `Dr Unearned Revenue / Cr AR`). Bounced cheques run through
`Payment.status = bounced` and the PDC module. What is missing is the *automatic* application
order, the NSF fee, and the bank deposit batch.

---

## 5. Credits, adjustments and write-offs

| Instrument | What it is in Voyager | Atriom equivalent |
|---|---|---|
| **Credit charge** | a negative charge on a charge code — reduces AR and reverses revenue on that code | `CreditNote` + `CreditNoteApplication`, invoice-level. **Stronger than Yardi's**: reversal un-applies the original rather than stacking a second offsetting document |
| **Charge reversal** | undo a posted charge (in an open period) | invoice cancel → auto-reverses applied credit |
| **Write-off / bad debt** | a `WRTOFF` charge code that clears the receivable to a bad-debt expense account, per charge, with an aging-driven work-list | ✅ **BUILT** (2026-08, after this section was written) — `InvoiceWriteOff` is its own dated GL source via `InvoiceWriteOffJournalizer` → `bad_debt_expense`, with a "Write off" action on the invoice. The paragraph below states why it was needed and is kept for that reason |
| **Late fee** | automated per charge code with grace, %-or-flat, per-lease override | `LateFeeService`, idempotent + lock-safe, but **config-global** — no per-lease override |

**Why the write-off had to exist, and was not a feature request.** Cancelling an invoice that was
genuinely earned and genuinely uncollectible reverses the revenue in the *current* period and removes
the AR — which understates prior-period revenue and hides the bad debt. The correct treatment keeps
the revenue, credits AR, and debits bad-debt expense. That is what `InvoiceWriteOff` now does.

**One row here is still open**, and it is on the AP side rather than the AR side: a **vendor payment**
had no reversal at all until 2026-08-11 (`VoidVendorBillPaymentService` — voiding a check is an
everyday Voyager operation). See [the change-impact plan](../../accounting/CHANGE-IMPACT-PLAN.md) for
the general form of this: which changes may move a posted entry, and which must become a new document.

---

## 6. Deposits

A charge code flagged as a **deposit** behaves differently all the way down:

- posting it credits a **liability** (`Deposits Held`), never revenue
- receipts against it enter a **deposit register**: held · applied · refunded · **forfeited**
- **move-out disposition** is a single itemised settlement: the held balance, minus itemised
  deductions (damages, cleaning, unpaid rent, final CAM), equals the refund — produced as one
  auditable document the tenant receives
- interest-bearing and segregated/escrow deposits are supported where jurisdictions require them
  ***(verify — jurisdiction packs)***

**Atriom has the liability treatment right** (`DepositTransaction` → `Dr Cash|Bank / Cr Deposits
Held`, with refund and forfeit paths, all journalized). What it lacks is the **single itemised
disposition** — refund and forfeit are two separate manual events — and any **reconciliation of
the lease's contractual `security_deposit` against the balance actually held**.

---

## 7. Straight-line rent — the lessor's revenue recognition

This is the part of Yardi's money flow that has no Atriom counterpart at all, and it is worth
being precise about, because it is the item most likely to make the owner's accountant unhappy.

### What it is

Under IFRS 16 / IAS 17 lessor accounting for operating leases — and under **Egyptian Accounting
Standard 49**, which follows it — **lease income is recognised on a straight-line basis over the
lease term**, unless another systematic basis better represents the pattern of benefit. Escalating
rents and rent-free periods do *not* change the pattern of benefit; they are financing and
incentive, not economics.

So for a lease with a 3-month rent-free fit-out and 7% annual steps, the landlord's **books**
recognise the *average* rent every month from day one, while the **tenant is billed** the stepped,
abated cash rent. The difference accumulates in an asset/liability:

```
Recognised (straight-line) > Billed (cash)  →  Dr Accrued / Deferred Rent Receivable
Recognised (straight-line) < Billed (cash)  →  Cr Deferred Rent (the balance unwinds)
```

Over the full term the account returns to zero. **The tax invoice is untouched** — this is a
GL-only accrual, so ETA e-invoicing, VAT and what the tenant owes are all unaffected.

### What Yardi does

Voyager Commercial computes a **straight-line rent schedule** per lease over the term — including
free-rent periods and every step — and posts the monthly difference between billed rent and
straight-line rent to a deferred/accrued rent account, with a per-lease per-month schedule you can
report on. Amendments trigger a **recalculation** from the modification date forward.
*(cited: the Commercial brochure lists "Straight-line Rent" as a core function and "easy
straight-line rent adjustments for IFRS".)*

### Worked example

Lease: 3 years, 3 months rent-free, rent EGP 100,000/month escalating 7% each year.

| Year | Months billed | Monthly rent | Cash billed |
|---|---|---|---|
| 1 | 9 (3 free) | 100,000 | 900,000 |
| 2 | 12 | 107,000 | 1,284,000 |
| 3 | 12 | 114,490 | 1,373,880 |
| | | **Total** | **3,557,880** |

Straight-line recognition = 3,557,880 ÷ 36 = **98,830/month**, every month, including the three
rent-free ones.

| Month | Billed | Recognised | Deferred-rent movement | Cumulative |
|---|---|---|---|---|
| 1–3 | 0 | 98,830 | Dr 98,830 | 296,490 |
| 4–12 | 100,000 | 98,830 | Cr 1,170 | 285,960 |
| 13–24 | 107,000 | 98,830 | Cr 8,170 | 187,920 |
| 25–36 | 114,490 | 98,830 | Cr 15,660 | **0** |

**The size of the error in Atriom today:** in year 1 that lease's revenue is overstated by nothing
and understated by 296,490 relative to the correct treatment; by year 3 it is overstated. On a
100-lease mall with 7% escalations and routine fit-out grace, the misstatement is material and it
is *systematic*, not random — it always understates early-year revenue and overstates late-year.

### The decision this forces

**This is the accountant's ruling, not an engineering call.** Two legitimate answers:

- **Yes, book it.** The owner's statutory books are EAS-compliant, the auditor will expect it, and
  the deferred-rent balance is a real balance-sheet line. This becomes a new GL source with its own
  journalizer — one line in `LedgerPoster::JOURNALIZERS` plus its `SOURCE_DATE_COLUMNS` entry, per
  the [GL registry invariant](../../modules/21-general-ledger.md).
- **No, bill-basis is the book.** Many Egyptian SMEs keep tax-aligned books where revenue = invoices
  issued, and the straight-line adjustment (if any) is an audit-time journal outside the system.

**Either answer is fine. Not choosing is not.** Route it through
[`docs/accounting/ACCOUNTANT-BRIEFING.md`](../../accounting/ACCOUNTANT-BRIEFING.md) with the worked
example above; it is question 1 of this cycle. Note that the *prerequisite* for ever answering
"yes" is the rent schedule — you cannot straight-line a rent you cannot see three years ahead.

---

## 8. Books — one event, several ledgers

Voyager supports multiple **books**: Accrual, Cash, Tax, Budget, and IFRS/GAAP variants, so the
same transaction produces different GL under different bases *(cited: "multiple GAAP, multi-language
and multi-currency")*. Straight-line rent, for instance, exists in the accrual book and not the cash
book.

**Atriom is single-book**, and for a single-entity EGP operator that is the right simplification —
adding books would be a large cost for an audience of one. It is listed here for completeness, and
because it is the mechanism by which Yardi keeps straight-line rent from contaminating the cash
view. If Atriom books straight-line rent, it does so in the one book it has, which is a reason to
make the accountant's ruling explicit rather than implicit.

---

## 9. Month-end close

The Voyager sequence, in order — each step gated on the previous:

1. Post all charges for the month (per property).
2. Post recoveries estimates / percentage-rent overage where they bill this month.
3. Enter and apply all receipts; reconcile deposit batches to the bank.
4. Post AP (vendor invoices) and any accruals.
5. Post straight-line rent and any other automated journals.
6. Bank reconciliation — with unposted GL transactions and post-month mismatches as the two classic
   blockers *(cited)*.
7. Review AR aging, tenant statements, and the rent roll.
8. **Close the post month.** Further postings to it are refused unless deliberately reopened.
9. Financial statements as of the closed period.

**Atriom's close is comparable in kind** — `AccountingPeriod`, the monthly-close PDF, the GL
tie-out, `PostingDateGuards` refusing a closed period — and its *conformance-gated* GL registry
(every money source must be registered, or the build fails) is stricter than what a typical Voyager
install enforces. The gaps here are §3 (post month) and §5 (write-off), not the close itself.

---

## 10. What Yardi does that Atriom does not — **re-verified against the code 2026-08-11**

The table below was written at the start of the cycle and had gone substantially stale: seven of its
ten rows are now built. Re-checked item by item against the source rather than trusted, because a
gap list nobody re-reads is how a team builds something twice.

| # | Capability | Status | Where |
|---|---|---|---|
| 1 | Charge schedule (date-ranged, many rows per code) | ✅ **BUILT** | `ChargeScheduleService`; a change closes the row in force and opens the next |
| 2 | Post month independent of document date | ✅ **BUILT** | `App\Support\PostMonth` + `posting_month_overrides`; `PostMonthAction` on the document |
| 3 | Straight-line rent / deferred rent | ✅ **BUILT** (ships OFF) | `StraightLineRentService`, RA-02 — off until the accountant rules |
| 4 | Bad-debt write-off | ✅ **BUILT** | `InvoiceWriteOffJournalizer` → `bad_debt_expense` |
| 5 | Receipt application order + **open-credit auto-apply** | 🟡 **HALF** — see below | `InvoiceItemSettlement::TYPE_PRIORITY` orders the lines; `ApplyTenantCreditService` applies a credit but only when an operator asks |
| 6 | Bank deposit batches | ⚪ **not worth it** | one operator, one bank; the receipt already carries its method and date |
| 7 | Itemised deposit disposition at move-out | ✅ **BUILT** | `MoveOutStatementService` + `SettleMoveOutService` (MF-02/MF-03) |
| 8 | Charge codes as configurable data (code → GL account) | ✅ **BUILT** | `charge_codes` + its screen; adding a code is a row, not a deploy |
| 9 | Multiple books (accrual / cash / tax) | ⚪ **not worth it** | single-entity, single-currency; the cost is large and the audience is one |
| 10 | Rate-based charges (EGP/m²/yr) that re-derive on an area change | ✅ **BUILT** | `LeaseSpaceChangeService::applyRentChange` re-derives from the rate at the effective date (LS-04) |

### The one real remainder: item 5

The mechanism exists and the hard part is done — applying an on-account credit is its **own dated GL
source** (`Dr Unearned / Cr AR`, posted at application time, with the over-allocation guards), which
is the part that took two attempts to get right. What is missing is only the automatic trigger:
Yardi applies open credit to the next charge without being asked, and Atriom waits for an operator.

**That is a business decision, not an engineering one, and it should stay open until the operator
makes it.** Auto-applying is not obviously correct: a credit raised in dispute, or one the tenant
expects refunded, silently disappearing into next month's rent is a support call and occasionally a
legal one. Yardi's default is configurable per property for exactly that reason. Recommend asking the
operator whether they want it, rather than shipping either behaviour as an assumption.

### On the module as a whole

Nothing in the GL warrants a rebuild. The registry-and-gate design — one journalizer registry that
all four dispatch paths derive from, posting roles resolved through an editable map, posting-date
guards declared per source, and conformance tests that fail the build when a new money source ships
unclassified — is **stricter than a typical Voyager install**, which enforces none of it in software.
The honest summary is that the accounting core is ahead of the benchmark on control and behind it
only on §8 (multiple books), which is a deliberate simplification for a single-entity operator.

---

## Sources

- [Yardi Commercial Suite brochure](https://resources.yardi.com/documents/commercial-suite-brochure/) — straight-line rent, IFRS, multi-GAAP, correspondence/invoicing
- [YARDI VOYAGER Commercial Property Management Software](https://silo.tips/download/yardi-voyager-commercial-property-management-software) — AR aging drill-down, integrated accounting
- [Yardi Voyager Charge Procedures](https://yardi.westcliff-group.com/voyager/Help/Residential%20User's%20Guide/Charge%20Procedures.html) — posting monthly charges
- [Best practices for Yardi Voyager period-end closings — 33Floors](https://33floors.com/best-practices-for-yardi-voyager-period-end-closings/) — post period, reopening, GL year close
- [8 common Yardi Voyager bank reconciliation failures](https://www.relayhumancloud.com/blog/yardi-bank-reconciliation-failures/) — post-month/cutoff mismatch, unposted GL, deposit batches
- [Yardi Voyager for property managers](https://www.outsourcedbookeeping.com/yardi-voyager-for-property-managers-complete-guide-to-accounting-reporting/) — automatic posting of rent charges, late fees, deposits to the GL
- [Yardi Core Setup Guide (AR configuration: unapplied charge codes, prepayments)](https://www.slideshare.net/slideshow/core-setup-guide7sjpdf/261865070)
- IFRS 16 / IAS 17 lessor operating-lease income recognition; **Egyptian Accounting Standard 49** (leases) — the straight-line requirement in §7
