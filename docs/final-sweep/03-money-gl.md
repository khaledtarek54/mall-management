# Final sweep — money cycles & the general ledger

> Part of [the final pre-staging sweep](README.md). Sources: six independent audits (AR/collections,
> recoveries, GL parity, GL mechanics, adversarial money bug-hunt, Egyptian tax) plus the lead's own
> verification pass. **Every CRITICAL and HIGH below was re-verified in the code by the lead**; the
> verification status is stated per finding, and claims that did not survive checking are in
> [§6 Retired claims](#6-retired-claims).

## 0. The one-paragraph verdict

**The core is sound and must not be rebuilt.** The four-channel AR invariant holds in every
calculation that *moves* money — `recomputeTotals()`, `capturedCashPaid()`, the cancel release, both
over-allocation guards and `InvoiceItemSettlement` all count all four, and the item outstandings
provably sum back to `balance`. Two separate adversarial agents attacked the settlement core,
proration, the billing run-lock, late-fee idempotency, credit-note apply/reverse, GL atomicity,
concurrency, immutability and closed-period bypass, and **could not break any of them**. The
posting engine is at or above Yardi Voyager. The VAT origination layer is the strongest single thing
in this codebase: all 18 origination points resolve through `Vat::rateForType()`/`onType()`, and
there is **no tax-rate literal in any computation anywhere in `app/`**.

**Every defect is at an edge** — code that decides *which* documents are still receivable, *which*
rows a total should include, or *which* status a sum should filter on. Three root causes generate
most of the list, and fixing those three closes far more than fixing ten symptoms:

| Root cause | What it generates | Where |
|---|---|---|
| **A · `void()` leaves the original's lines in place** | 4 consumer services compute `(new − original)` on every correction and go negative on every cancel | [§1.1](#11-critical--the-posted-only-divergence) |
| **B · A write-off relieves the GL but not the sub-ledger** | Permanent tie-out break, uncapped double write-off, collectable written-off debt | [§1.2](#12-critical--write-offs-are-half-applied) |
| **C · A record is cloned by a hand-written literal array** | Renewals silently lose negotiated terms; imports produce leases that bill nothing | [§1.3](#13-critical--cloning-by-literal-array), and [01-yardi-parity](01-yardi-parity.md) |

---

## 1. CRITICAL

### 1.1 CRITICAL — the `posted`-only divergence

- **Remedy class:** BUGFIX · **Effort:** S per site, M with the shared fix and test · **Verified:** yes, by the lead

`JournalPostingService::void()` ([app/Services/Accounting/JournalPostingService.php:183-235](../../app/Services/Accounting/JournalPostingService.php#L183-L235))
posts a **reversal** entry with `status='posted'` and flips the original to `status='void'`. **The
original's lines stay in `journal_lines`, dated in the original period.** Original + reversal net to
zero *only if both are counted*. `LedgerReportService` knows this and uses
`private const REPORTABLE = ['posted', 'void']` ([LedgerReportService.php:29](../../app/Services/Accounting/LedgerReportService.php#L29)).

Four consumer services filter `status = 'posted'` only, so each computes `(new − original)` on every
re-derive and goes **negative** on every cancel:

| Service | Line | What it feeds |
|---|---|---|
| `SyncCamPoolFromLedgerService` | [:90](../../app/Services/SyncCamPoolFromLedgerService.php#L90), [:149](../../app/Services/SyncCamPoolFromLedgerService.php#L149) | **`cam_expense_pools.total_actual_expense` — the CAM recovery basis tenants are billed off** |
| `ReconcileBankStatementService` | [:77](../../app/Services/Banking/ReconcileBankStatementService.php#L77), [:86](../../app/Services/Banking/ReconcileBankStatementService.php#L86) | the bank reconciliation's "ledger balance" |
| `MatchBankStatementLineService` | [:60](../../app/Services/Banking/MatchBankStatementLineService.php#L60) | match candidates (offers reversals, hides originals) |
| `VatReturnService` | [:117](../../app/Services/Reports/VatReturnService.php#L117) | **`input_vat` on the return filed with the tax authority** |

This is not an edge case: `LedgerPoster::sync()` calls `void()` on **every** re-derive, which is the
normal operating mode of a derived ledger.

**Failing scenario (CAM, the worst).** A 100,000 EGP cleaning bill posts in March. The operator
cancels it. `sync()` voids the entry — the original is now excluded, the reversal (`Cr Cleaning
100,000`) is included — and the pool syncs to `total_actual_expense = −100,000` where the truth is
`0`. The annual true-up then issues **every tenant in the pool a credit note for their share of
100,000 EGP the landlord never over-collected**. The re-derive variant under-recovers by the
original amount instead.

**Why no gate catches it:** the entry balances, `glTieOut()` watches only the AR and AP control
accounts, and `wouldChange()` is false. The service's own docblock at
[:74](../../app/Services/SyncCamPoolFromLedgerService.php#L74) states the wrong rule ("a voided one
never was").

**Fix.** Promote `REPORTABLE` to a shared constant on `JournalEntry` (or `LedgerPoster`) and make
every line-summing query use it. Add one test asserting the bank rec's ledger balance equals
`LedgerReportService::accountLedger()` for the same account — **that single assertion would have
caught all four sites at once.** Audit the remaining `posted`-only filters
(`YearEndCloseService:43`, `PeriodService:74`, `BooksReconciliationService:284`,
`LedgerPoster:229`/`:336`) and annotate each as deliberate, so the next reader can tell the two apart.

### 1.2 CRITICAL — write-offs are half-applied

- **Remedy class:** BUGFIX · **Effort:** M · **Verified:** yes, by the lead

`WriteOffInvoiceService` ([:93-101](../../app/Services/WriteOffInvoiceService.php#L93-L101))
deliberately does not touch `balance` — the comment explains that balance is derived from payments
and credit, and a write-off is neither — and sets `status = 'written_off'` **only on a full
write-off**. Meanwhile `InvoiceWriteOffJournalizer` posts `Dr bad_debt_expense / Cr AR` for the
**partial** amount regardless. Three defects follow:

**(a) The GL and the sub-ledger diverge permanently.** Write off 5,000 of a 20,000 invoice: the GL
relieves 5,000, the invoice still reads `balance 20,000 / overdue`. `glTieOut()`
([BooksReconciliationService.php:280](../../app/Services/Reconciliation/BooksReconciliationService.php#L280))
is off by 5,000 from then on. Aging, the tenant statement and the owner statement all still claim
20,000, `LateFeeService` charges a fee on the written-off portion, and dunning continues.

**(b) Partial write-offs are uncapped and un-netted.** [:44](../../app/Services/WriteOffInvoiceService.php#L44)
caps against `balance`, which a write-off never changes, and prior write-offs are never subtracted.
`EditInvoice.php:245-246` re-offers the **full** balance as both default and max. So "write off the
rest" accepts 20,000 again → **25,000 of bad debt against a 20,000 receivable**, the invoice flips to
`written_off` and is then excluded from `expectedAr`, leaving a permanent −5,000 AR delta with no
document behind it. `InvoiceWriteOffTest.php:207` ("cannot be written off twice") exercises only the
full path and stays green.

**(c) A written-off invoice is offered in the payment allocation picker.**
[PaymentForm.php:160-167](../../app/Filament/Admin/Resources/Payments/Schemas/PaymentForm.php#L160-L167)
filters `balance > 0` with **no status filter**, and neither `CreatePayment` nor
`assertInvoicesNotOverAllocated()` (which compares against `total`) closes it. Allocate a receipt to
a written-off 20,000 invoice → GL AR goes to −20,000 while bad-debt expense stays booked for a debt
that was in fact collected. **The sibling path does filter** —
[PostDatedChequeForm.php:44](../../app/Filament/Admin/Resources/PostDatedCheques/Schemas/PostDatedChequeForm.php#L44)
carries `whereIn('status', ['issued','partially_paid','overdue'])` — which is what makes this an
omission rather than a design.

**Fix.** Decide one model and apply it everywhere: either a write-off reduces `balance` (and
`recomputeTotals()` learns a fifth input — **note the invariant cost of that choice**), or it does
not and *every* consumer filters `written_off`. Given the CLAUDE.md invariant, the second is
cheaper and safer. Cap the write-off at `balance − Σ prior write-offs`, and add the status filter to
the payment picker.

### 1.3 CRITICAL — cloning by literal array

- **Remedy class:** REIMPLEMENT (derive, don't enumerate) + a conformance gate · **Effort:** M · **Verified:** yes, by the lead

Covered in full in [01-yardi-parity §Leasing](01-yardi-parity.md). Summarised here because the money
consequences are severe: `LeaseRenewalService` drops 14 of 43 fillable columns and three child
collections, so a renewal can lose its escalation, its CAM cap, its percentage-rent ladder and its
parking assignments — and `LeaseImporter` bypasses `seedStandardCharges()` entirely, so imported
leases bill **nothing**.

### 1.4 CRITICAL — the `pay-demo` endpoint is live in production

- **Remedy class:** REMOVE (or hard-gate) · **Effort:** S · **Verified:** yes, by the lead

**This is the single most severe finding of the sweep.**

[routes/api.php:159-161](../../routes/api.php#L159-L161) registers
`POST /api/v1/me/invoices/{invoice}/pay-demo` **unconditionally** inside the authenticated tenant
group. Its only environment gate is in the controller
([DemoPayInvoiceController.php:39-44](../../app/Http/Controllers/Api/V1/Tenant/DemoPayInvoiceController.php#L39-L44)):

```php
if (config('integrations.paymob.enabled')) {
    return response()->json([...], 409);
}
```

`config/integrations.php:17` is `'enabled' => env('PAYMOB_ENABLED', false)`, and `.env.example`
ships `PAYMOB_ENABLED=false` at both `:22` and `:212`. **The gate is inverted with respect to
safety: the endpoint is live precisely when Paymob is off, and Paymob-off is the go-live state.**
There is no `app()->environment()` check on the route, the controller, or the action — I grepped all
three.

`RecordDemoPaymentAction::handle()` then creates a real `Payment` (`status = 'captured'`,
`method = 'card'`, `gateway = 'demo'`), allocates the full balance, and lets `Payment::saved`
recompute the invoice and notify the tenant. The GL posts `Dr Bank / Cr AR`.

**Failing scenario.** An authenticated tenant POSTs to the endpoint for their own invoice
`INV-AW-202608-0001` (12,780.00). The invoice flips to `paid`, balance 0, a receipt is emailed, and
the ledger records `Dr Bank 12,780 / Cr AR 12,780`. No money exists. `billing:reconcile` stays
**green** — every internal relationship is consistent, which is exactly why nothing catches it.
Nothing in `atriom:health` or the go-live checklist flags it.

**What limits the damage:** the tenant-scoping is correct (404 on another tenant's invoice, closing
enumeration), the status and balance guards are correct, and the row is stamped `gateway = 'demo'`
with `gateway_response.demo = true` — so fabricated payments are *identifiable* afterwards. That
makes cleanup possible; it does not prevent the loss.

**Fix.** Gate on `app()->environment(['local', 'testing'])` **and** a dedicated
`DEMO_PAYMENTS_ENABLED` flag defaulting false — not on the Paymob flag, which is a different
question. Then add an `atriom:health` check that **FAILS** in production while the endpoint is
reachable, mirroring the pattern the project already uses for `SECURITY_FORCE_2FA_ROLES`, so the
dangerous state can never again be silent.

### 1.5 CRITICAL — two CAM pools on one property both consume the same estimate

- **Remedy class:** BUGFIX · **Effort:** S–M · **Verified:** yes, by the lead

[SyncCamPoolFromLedgerService.php:217-231](../../app/Services/SyncCamPoolFromLedgerService.php#L217-L231)
scopes "estimate already billed" by `units.asset_id`, invoice status and
`CamExpensePool::ESTIMATE_ITEM_TYPES` — a single **global** constant `['service_charge']`
([CamExpensePool.php:37](../../app/Models/CamExpensePool.php#L37)) — with **no pool discriminator and
no participant filter**, even though `pool_code` is a first-class column. And
[CamExpensePoolForm.php:130](../../app/Filament/Admin/Resources/CamExpensePools/Schemas/CamExpensePoolForm.php#L130)
**defaults `estimate_basis` to `BASIS_BILLED`** — the risky basis is the default.

**Failing scenario.** A property runs a `cam` pool and a `tax` pool for 2026. The `tax` pool
subtracts the tenant's entire year of billed service charge a second time: allocated 20,000 −
estimate 100,000 = **−80,000** → an issued credit note, auto-applied FIFO against live AR.
`SeveralRecoveryPoolsTest` pins every fixture to `BASIS_STATED`, so nothing sees it.

**Fix.** Make the estimate charge-code set per-pool. As an interim, default non-`cam` pools to
`stated`.

### 1.6 CRITICAL — input VAT recovered in full while most output is exempt

- **Remedy class:** ACCOUNTANT-DECISION → then REIMPLEMENT · **Effort:** M–L · **Verified:** yes (absence proven)

`VendorBillJournalizer.php:98-105` and `ExpenseJournalizer.php:57-64` debit the whole bill VAT to
`vat_recoverable`, and `VatReturnService.php:48,89` claims all of it. But base rent — the largest
revenue line — is exempt (`ChargeCodeSeeder.php:35`), as are the marketing levy, percentage rent,
parking, late fees and fines. Searched `apportion`, `partial.?exempt`, `recoverable ratio` and
`de.?minimis` across `app/`, `database/` and `config/`: **zero hits**.

A partially-exempt taxpayer may not deduct input tax attributable to exempt supplies. Mall-wide
security, cleaning and maintenance VAT is being fully deducted every period — a systematic
under-declaration.

**This is an accountant ruling before it is code**, and it is entangled with the question below.

### 1.7 CRITICAL (as a question, not a bug) — is base rent actually VAT-exempt?

- **Remedy class:** ACCOUNTANT-DECISION · **Effort:** — · **Verified:** the code's certainty is verified; the tax position is not ours to settle

`ChargeCodeSeeder.php:33-35` calls base-rent exemption *"the oldest rule in the system and the one an
accountant is least likely to change."* Egyptian VAT Law 67/2016 exempts **residential /
non-commercial** real-estate rental; commercial, industrial, professional and administrative letting
is generally standard-rated — and mall retail units are commercial.

If the accountant rules it taxable, every rent line in history under-collects 14% — **and it inverts
§1.6**, because a fully-taxable operator has no apportionment problem at all.

**The architecture is one row-edit away from either answer** (`charge_codes.vat_treatment`, no
deploy), which is exactly right and is why this is a question rather than a defect. The only code
change warranted today is softening that seeder comment to match `OPEN-QUESTIONS A1.1`, so nobody
reads certainty into an open item.

---

## 2. HIGH

### 2.1 The VAT return: unreachable, and wrong when reached

- **Remedy class:** WIRE + BUGFIX · **Effort:** S · **Verified:** yes, by the lead

Two defects in one service, both confirmed independently by three agents and the lead.

**(a) No operator can open it.** `app/Services/Reports/VatReturnService.php` is complete, documented
and tested, and has **zero callers** — no Filament page, no route, no nav entry, no console command,
no export. Its 15 sibling report services all have a page; there are pages for Trial Balance, Balance
Sheet, Cash Flow, AR Aging, Rent Roll and ten more. Egypt files VAT monthly. `ROADMAP.md` records
this as "✅ shipped".

**(b) Its control breaks, and its base is overstated, on any credit note.**
[VatReturnService.php:51-58](../../app/Services/Reports/VatReturnService.php#L51-L58) builds the
documents side from `Invoice` **only**, while the ledger side (`:47`) is net of credit notes, which
`CreditNoteJournalizer.php:66-72` correctly debits. So:

- `output_vat_difference = −(credit-note VAT)` and `ties_out` is **false in every period containing
  a VAT-bearing credit note** — a control that cries wolf trains the operator to ignore it;
- `base_standard` / `base_exempt` (`:60-78`) never net credit notes either — **those are numbers that
  go on a filed return.**

This is live, not latent: three paths issue VAT-bearing credit notes routinely — CAM negative
true-up at the pool's `recovery_vat_rate` (column default 14.00), move-out unearned credit, and
manual notes that inherit the invoice's rate. `tests/Feature/Regression/VatReturnTest.php` has five
cases and **not one issues a credit note**.

Note this is a bug *inside a correct design*: reading the return from the ledger and checking it
against the documents is the right architecture, and the tie-out genuinely does catch an unposted
invoice (`VatReturnTest.php:92-106` proves it). Add credit notes to the documents side and to both
base buckets — roughly 15 lines and one regression test. **Cheapest high-value fix in this file.**

### 2.2 `billing:reconcile` counts two of the four settlement channels

- **Remedy class:** BUGFIX · **Effort:** S · **Verified:** yes (two agents, concurring)

[BooksReconciliationService.php:74-86](../../app/Services/Reconciliation/BooksReconciliationService.php#L74-L86)
derives `paid = allocations + credit_applied_amount`, omitting `TenantCreditApplication` and
`DepositApplication` — while its own docblock claims it "mirrors `recomputeTotals()` exactly". With
`auto_apply_tenant_credit` defaulting **true**, every applied tenant credit and every netted move-out
deposit fabricates a discrepancy and flips the whole run to `ok = false`.

The trust tool cries wolf on the two newest money features, which buries any real drift. Unpinned by
any test. This is precisely the "four channels, four downstream sites" invariant in CLAUDE.md — the
reconciler is a fifth site nobody added.

### 2.3 No bank-account dimension — the bank reconciliation cannot be operated

- **Remedy class:** REIMPLEMENT · **Effort:** M · **Verified:** partially (agent-verified; lead confirmed the `posted`-only half)

No money document carries a `bank_account_id` (grep across all migrations and models returns only
`bank_statements`). Every journalizer resolves the *role* `bank`, and `AccountMappingSeeder.php:20`
maps that role globally to one chart leaf. `ReconcileBankStatementService::for()` and
`candidatesFor()` filter on `ledger_account_id` alone — **no asset filter** — and
`bank_accounts.ledger_account_id` has no unique index.

In the **default seeded configuration**, Mall A's statement page offers Mall B's receipts as match
candidates — a property-isolation breach inside the accounting layer — and A's "difference" contains
B's movement. Slice 1's own migration docblock recorded this as deferred; slices 5 and 6 were then
marked ✅.

### 2.4 A cross-property payment's entry is invisible to both properties

- **Remedy class:** BUGFIX · **Effort:** S–M · **Verified:** agent-verified

`PaymentJournalizer.php:53-54` sets the entry's `asset_id = null` for a payment spanning properties.
Every report filters `whereIn('je.asset_id', …)` (`LedgerReportService.php:416`), which never matches
NULL. Per-property AR is therefore permanently overstated, and `glTieOut()` runs consolidated so it
cannot see the gap. `journal_lines.asset_id` already exists and no report reads it — the fix is to
dimension at the line level and report from there.

### 2.5 The `DepositApplication` posting-date exemption is factually false

- **Remedy class:** BUGFIX + registry correction · **Effort:** S · **Verified:** yes, by the lead

[PostingDateGuards.php:128-132](../../app/Support/PostingDateGuards.php#L128-L132) exempts
`DepositApplication` with `system:` — *"entry_date is stamped at application time by
ApplyDepositToInvoiceService and is not operator-typable."* **It is operator-typable.**
`ApplyDepositToInvoiceService` writes `'entry_date' => $on->toDateString()` where `$on` is a
**parameter** ([:79-80](../../app/Services/ApplyDepositToInvoiceService.php#L79-L80)), and
`SettleMoveOutService:71` passes `$settlementDate` — which comes from an **unconstrained**
`DatePicker::make('settlement_date')` on the Lease resource
([LeasesTable.php:879-882](../../app/Filament/Admin/Resources/Leases/Tables/LeasesTable.php#L879-L882))
with no `minDate` and no period check. Grepping both services for `PostingDate` / `assertOpen`
returns **nothing**.

`SettleMoveOutService` even carries a comment stating "the application posts on the settlement date,
and a closed period refuses it" — describing a refusal that does not exist.

**Failing scenario.** Back-date a settlement into a closed March: 120,000 of arrears net off the
deposit, AR closes, and the GL post is silently refused inside the best-effort sync job → a 120,000
tie-out gap. Second half: `settleOpenAr` runs *outside and before* the outer transaction, so when the
guarded `DepositTransaction` then throws on the same closed date, the rollback leaves the deposit
already spent while the operator sees a refusal.

**Why the gate is blind:** the conformance test checks the registry's own declarations, and the field
lives on a *different* resource under a *different* name. A `system:` exemption asserting a safety
property that does not hold is worse than a missing entry — the gate reports coverage.

### 2.6 `VendorBill` cascades to payments but not to SLA penalties

- **Remedy class:** BUGFIX · **Effort:** S · **Verified:** agent-verified

`VendorBill::ledgerChildRelations()` ([:139-142](../../app/Models/VendorBill.php#L139-L142)) returns
`[$this->payments()]`; `penalties()` exists at `:188-189` and is omitted — yet
`SlaPenaltyJournalizer` derives its **entire** payload from the parent bill.

Re-home a bill from one property to another (`asset_id` is DERIVED and editable): `VendorBill::updated`
bumps the payments only, the penalty's `updated_at` never moves, and the 2-day sweep window never
sees it. The first property keeps an 8,000 EGP expense credit and AP debit for a bill that is not
theirs. It self-heals only on the Friday `--all` run, and **never** if the month closes first. This
is the third instance of the child-source cascade class the project has already fixed twice.

### 2.7 Voiding a monthly invoice permanently blocks re-billing that lease-month

- **Remedy class:** BUGFIX · **Effort:** S · **Verified:** yes, by the lead

[MonthlyBillingService.php:688-701](../../app/Services/MonthlyBillingService.php#L688-L701) —
`alreadyBilledForMonth()` has **no status filter**, so a `cancelled` invoice still satisfies it, on
both the bulk run and the manual action. Void a wrong August invoice intending to regenerate it and
both paths report `skipped: already_billed` **forever**. Silent lost revenue, indistinguishable in
the run summary from a correctly-billed lease.

Related, same method: **`nsf_fee` is missing from the exclusion list** while the NSF invoice is dated
to the current month (`BillBouncedChequeFeeService.php:89-90`), so an NSF-fee invoice suppresses that
month's base rent. That is the fourth instance of a class already fixed for `percentage_rent`,
`utility` and `violation_fine` — and the last of those has a named regression test with no `nsf_fee`
twin.

### 2.8 Late fees recognise revenue in the invoice's original month

- **Remedy class:** BUGFIX · **Effort:** S · **Verified:** agent-verified

`LateFeeService.php:129-152` adds the fee as an item on the **original** invoice, and
`InvoiceJournalizer.php:152` dates the entry from `issue_date`. April's penalty therefore becomes
January revenue — **restating a month already reported to the owner, from a 04:00 cron**. No
closed-period test exists for it.

The correct pattern is already in this codebase twice: CAM true-ups and percentage-rent overages each
raise their own invoice, dated now. Late fees should do the same.

### 2.9 Re-running CAM generation writes a fabricated `landlord_unrecovered_amount`

- **Remedy class:** BUGFIX · **Effort:** S · **Verified:** agent-verified (two agents, concurring)

`CamReconciliationService.php:200-202` skips already-billed allocations *before* `:233` accumulates
`$allocatedTotal`, but `:257` writes the remainder **unconditionally**. Re-generate a 1,000,000 pool
after billing 2 of 4 leases and the pool is stamped `landlord_unrecovered_amount = 500,000` on a
fully-recovered pool; `billing:reconcile`'s CAM check then false-fails by that amount. One click
does it — `canGenerate` allows `reconciling`, and billing does not change pool status.

### 2.10 Annual percentage rent silently drops the deduction clause

- **Remedy class:** BUGFIX · **Effort:** S · **Verified:** yes, by the lead

`calculate()` applies `netOfDeductions()` at
[PercentageRentCalculationService.php:46-48 and :52-54](../../app/Services/PercentageRentCalculationService.php#L46-L54).
`retrueAnnualYear()` computes its marginal from `overage()` directly at
[:279](../../app/Services/PercentageRentCalculationService.php#L279) and **never calls
`netOfDeductions()`**. A lease with `percentage_rent_frequency = 'annual'` plus
`percentage_rent_deductible_types` is therefore **billed gross while the UI shows net**.

### 2.11 WHT is computed on the VAT-inclusive payment

- **Remedy class:** BUGFIX · **Effort:** S · **Verified:** agent-verified

`VendorBillService.php:66-78` passes `min($amount, $bill->balance)` — derived from `total`, i.e. net
**plus** VAT — into `WithholdingTax::on()`, which applies the rate to that gross
(`WithholdingTax.php:73`). The word `vat` does not appear in that file. The Egyptian WHT base
excludes VAT: at 3% on a 100,000 net bill that is 3,420 withheld against 3,000 due.

Mitigated only by `wht_enabled = false` today — **so fix it before it is switched on**, not after.

### 2.12 The document titled "Tax Invoice" carries no seller tax registration number

- **Remedy class:** EDIT · **Effort:** S · **Verified:** agent-verified

`invoices/pdf.blade.php:178` renders the title; the seller block (`:167-176`) prints name, address
and city only. **There is no seller TRN anywhere in the data model** — `Asset` has no tax field, and
the only issuer TRNs are placeholders in `EtaSettings.php:18` / `config/eta.php:36`, neither
rendered. Buyer TRN is optional and conditionally printed. There is no taxable-value-per-rate
summary. **A tenant cannot support an input-VAT deduction from this document.**

### 2.13 A lease renewal strands the security deposit on the old lease

- **Remedy class:** BUGFIX · **Effort:** S · **Verified:** agent-verified

Renewal creates a new `Lease` row and copies only the *contractual* deposit fields; `depositHeld()`
filters on `lease_id` and never walks `previous_lease_id`. The renewed tenancy holds nothing: the
deposit cannot be netted against arrears, and at final move-out the tenant is refunded zero while
"Deposits Held" carries the cash against a dead lease.

### 2.14 Reports and statements disagree about which invoices are receivable

- **Remedy class:** EDIT (one shared predicate) · **Effort:** S · **Verified:** agent-verified

`AssetStatementPdfService.php:30-34,68-73` — the owner-facing property statement — excludes only
`cancelled` and `credited`, so it counts **`draft` and `written_off`** invoices as outstanding AR.
The tenant-facing sibling correctly excludes `written_off`. One 45,000 draft plus one 20,000
written-off debt overstates the owner's AR by 65,000 against the GL-derived owner statement for the
same property. The same predicate defect appears in `ActionRequired.php:97-98`,
`InvoiceResource.php:96-99` and `InvoicesTable.php:272`.

**Note:** `AssetStatementPdfService` is itself orphaned — see [04-architecture-ops](04-architecture-ops.md).
Decide whether to wire or delete it *before* fixing its predicate.

### 2.15 Statements are calendar-year only — no monthly trial balance, P&L or balance sheet

- **Remedy class:** EDIT · **Effort:** S–M · **Verified:** yes, by the lead

[ScopesLedgerReport.php:67-76](../../app/Filament/Admin/Pages/Concerns/ScopesLedgerReport.php#L67-L76)
hardcodes `Carbon::create($this->year, 1, 1)` to `Carbon::create($this->year, 12, 31)`;
`BalanceSheet.php:108` is always as-of 31 December. **The operator runs a monthly close and cannot
print that month's trial balance, income statement, balance sheet or cash flow.**

The *services* already take ranges — only the pages don't, which is what makes this cheap.
Secondarily, `FiscalYear` has `starts_on`/`ends_on` that these pages ignore, so a non-calendar fiscal
year reports the wrong window entirely.

### 2.16 Only two of seven control accounts have a sub-ledger tie-out

- **Remedy class:** EDIT · **Effort:** M · **Verified:** agent-verified

`glTieOut()` covers AR and AP. Nothing reconciles deposits held, inventory, GRNI, fixed assets and
accumulated depreciation, custody, employee advances, or due-to-owner. Each is a control account
whose sub-ledger can drift silently.

### 2.17 Period close/reopen is not audit-logged

- **Remedy class:** EDIT · **Effort:** S · **Verified:** agent-verified

`AccountingPeriod` and `FiscalYear` carry no `LogsActivity`, no reason and no actor. `reopenPeriod()`
runs no gate and does not check the fiscal year — reopening inside a closed year leaves the
retained-earnings roll stale, and `YearEndCloseService::close()` is idempotent so re-closing will not
repair it. "Who reopened January, and why" is unanswerable.

### 2.18 The tie-out delta is never persisted or alerted, and `billing:reconcile` is not scheduled

- **Remedy class:** EDIT · **Effort:** S · **Verified:** agent-verified

`SyncLedgerCommand.php:217-241` only `warn()`s the GL↔AR/AP delta. The two persisted keys are both
about *documents that threw* — and this bug class throws nothing, so `recordAndAlertFailures()`
returns early. `billing:reconcile` appears nowhere in `routes/console.php`. Partial mitigation: the
month-end checklist calls `BooksReconciliationService::run()`, so a diligent operator sees it once a
month.

### 2.19 The CAM tenant statement does not reconcile to the invoice it explains

- **Remedy class:** BUGFIX · **Effort:** S · **Verified:** agent-verified

`CamStatementPdfService.php:151` omits recovery VAT from `total_due` while the invoice adds it
(`CamReconciliationService.php:672`), and `statement.blade.php:281-285` nets the admin fee out of a
credit that is actually billed as a separate invoice. The in-app `explainAllocation()` gets both
right — **so the PDF and the modal disagree**. This is the document a tenant's audit clause attaches
to.

### 2.20 A lease that ends mid-year gets no CAM allocation at all

- **Remedy class:** DEFER (decision needed) → then EDIT · **Effort:** M · **Verified:** agent-verified

`participants()` (`CamReconciliationService.php:289-297`) filters `status = 'active'`. The survivors'
shares still sum to 100%, so they absorb the departed tenant's months and **the tie-out stays green**.
This contradicts the service's own docblock at `:649-660` ("the most likely under-collected tenant
has an ended-term lease"). Recorded as gap-analysis F-28 and never decided — it needs an operator
ruling on whether departed tenants are billed a partial-year true-up.

### 2.21 `sales:estimate-missing` runs before the chase and locks the tenant out

- **Remedy class:** EDIT · **Effort:** S · **Verified:** yes, by the lead

`sales:estimate-missing` is scheduled `monthlyOn(8, '07:30')`; `sales:scan-missing-declarations` is
`monthlyOn(10, '08:00')` ([routes/console.php:164-175](../../routes/console.php#L164-L175)) — **the
estimate fires two days before the chase.** `Lease::missingSalesDeclarationsFor()`
([:1114-1119](../../app/Models/Lease.php#L1114-L1119)) does not exclude `is_estimate` rows, so once an
estimate exists the lease is no longer "missing": the reminder and the dashboard card go quiet, and
the tenant then cannot upload their real report (portal `unique` rule +
`CreateSalesDeclarationAction:42-49`). The module doc claims the estimate runs "a week after the
chase".

### 2.22 Sub-metered utility can be recovered twice

- **Remedy class:** EDIT · **Effort:** M · **Verified:** agent-verified, config-dependent

Once as a `utility_revenue` recharge, and again inside a ledger-sourced CAM pool whose accounts
include the electricity expense. There is no "direct/metered" recovery type, and
`cam_allocations.exclusions` is never written or read. Silent, and the tie-out cannot see it.

### 2.23 Collections, batching and delivery gaps

- **Remedy class:** DEFER mostly · **Effort:** M–L · **Verified:** agent-verified

Grouped because they are scope, not defects: **no dunning ladder** (one stamp, one notice, ever —
`RemindOverdueTenantsCommand.php:29,74`); **no batch identity on the billing run** (no batch id, no
bulk void, failed lease ids to OpsLog only); **late fee fires once per invoice for all time** (no
recurrence, compounding or cap); **no pure prepayment receipt**; **no cash refund of on-account
credit**; **no invoice delivery tracking** (no `sent_at`/`delivered_at`, no bounce handling, no
resend); **no payment plan, collections status or credit limit**.

Yardi ships a full collections module. Decide the MVP subset — a dunning ladder and delivery
tracking are the two an Egyptian mall operator will miss first.

---

## 3. MEDIUM

Grouped by theme. Each was reported with a `file:line`; see the per-agent files in the scratchpad for
full detail.

**Precision and rounding.** CAM re-run reads the share back out of `decimal(7,4)` and multiplies it
into money (`CamReconciliationService.php:101,122`) — a 12M pool over 3 leases drops 4.00 on a second
button press · straight-line rent uses `round(total/months,2)` with no final true-up, leaving 0.08 in
Deferred Rent forever · annual %-rent marginals use `round(A−B)` instead of `round(A)−round(B)`, so
the year's issued invoices do not sum to the annual overage · `decimal(5,2)` on
`percentage_rent_rate`, `escalation_rate` and `ownership_percentage` — 0.375% stores as 0.38 (6,000/yr
on 120M of sales) and 7.125% stores as 7.13 and then compounds · CAM slices have no remainder pass and
the tie-out tolerance was widened to accept the loss.

**Soft deletes and status predicates.** `monthlyClose` uses three different soft-delete treatments in
one report (`ReportService.php:64` vs `:886-908` vs `:929-948`) · collection-rate numerator counts
cash allocations only and ignores soft deletes while its denominator does neither · CAM
`estimate_basis = billed` counts soft-deleted **and draft** invoices, under-billing every
participant's true-up · overdue nav badge and `overdue_only` filter count drafts and written-off
invoices · a raw join ignores `payments.deleted_at` in `InvoiceItemSettlement`.

**GL mechanics.** `matches()` ignores line-level dimensions (`LedgerPoster.php:351-389`), so
`DepositTransaction.lease_id`/`tenant_id` and `Payment.tenant_id` — all classified **DERIVED** — can
never actually re-derive, and `ChangeImpactConformanceTest`'s four checks include **none that proves a
DERIVED field re-derives** · close-gate off-by-one on a DATETIME (`PeriodService.php:101`) hides a
`SlaPenalty` applied late on the last day · close gate (b) is blind to post-month overrides ·
period close is neither transactional nor locked and never checks that the period balances ·
`YearEndCloseService::reopen()` silently unlocks December and never re-closes it · no unique index on
`(source_type, source_id)`, no CHECK constraint on one-sided or negative amounts ·
`MatchBankStatementLineService:60` offers reversal lines and hides originals.

**Tax.** Zero-rated reports as exempt (`VatReturnService.php:72` buckets on `vat_rate > 0`, though the
schema stores the distinction precisely because it is unrecoverable later) · no reverse charge on
imported services · advance receipts create no VAT tax point · payroll income tax is flat, not
bracketed (confirms "4d deferred"; no bracket table exists) · social insurance ignores the capped
subscription wage · the end-of-service account is seeded with **no posting role, so nothing can ever
reach it** · WHT is never certificated, reported or remitted (`withholding_tax_payable` is credited
and debited nowhere) · a foreign-currency lease would corrupt both the ledger and the return — currency
columns exist, **no FX rate anywhere**, 201 hardcoded `money('EGP')` · backup retention is 2 years
against a 5-year statutory floor.

**Recoveries.** No base-year / expense stop (only a ceiling) · per-lease exclusions never built ·
statement area uses today's measurement rather than a period-weighted share (`denominator_used_sqm`
exists, unused) · re-run freezes the participant *set*, so a mid-year arrival is never allocated ·
no sales categories or exclusions · reporting vs billing frequency conflated (no "annual in arrears") ·
**the marketing fund is booked as revenue and never reconciled** — no true-up, no carry-forward, no
tenant reporting, which is a contract question to settle before go-live · no prior-year adjustment
path · `disputed` allocation status unreachable · utilities have no sub-meter reconciliation, block
tariffs, loss factor or estimated readings.

**Other.** Move-out final account omits `Tenant::creditBalance()`, so the refund is short by any
unallocated overpayment · `Lease::generateReference` is the only sequence with neither `withTrashed()`
nor a collision loop, so a soft-deleted lease kills the next creation wizard on a duplicate key ·
owner statement penny-reconciles `owner_share` but not `share_revenue`/`share_expense`, so the PDF
contradicts itself by a cent · aging "as of" a past date uses today's balances, so the monthly-close
pack is not reproducible · Paymob callback idempotency misses `reconciled`/`settled` · five unfloored
`daysOverdue` implementations with differing sign and rounding semantics, one of them served to the
mobile API · no aged AP, no cash position, no budget vs actual · no opening-balance tool · no
recurring, reversing or prepayment journals · no drill-through from any statement figure · no
inter-property due-to/due-from.

---

## 4. What is at or above benchmark — do NOT touch

Stated as prominently as the defects, because the cost of "fixing" one of these is high and the
temptation during a sweep is real.

1. **The four-channel AR core.** `recomputeTotals()` as the single source of truth, `capturedCashPaid()`,
   the cancel release, both over-allocation guards, and `InvoiceItemSettlement` deriving every
   per-line figure from `paid_amount` so item outstandings always sum back to `balance`.
2. **The one-registry rule.** `LedgerPoster::JOURNALIZERS` with all four dispatch paths deriving from
   `sources()`. An agent tasked specifically with finding an unregistered money source diffed every
   `decimal()` money column against all 24 sources and **found none** — the failure in this area is a
   *cascade* (§2.6), not the registry.
3. **VAT origination.** All 18 origination points resolve through `Vat::rateForType()`/`onType()`;
   **no tax rate is hardcoded in any computation in `app/`**. The rate-vs-taxability split (settings
   vs `charge_codes.vat_treatment`) is where Yardi puts it. Origination-only resolution is the single
   most important tax property in the system. `EXEMPT_TYPES` as a floor with a conformance gate;
   exempt and zero-rated stored apart.
4. **Lock-and-recheck discipline** across every settlement path, plus `AllocatesDocumentNumber`
   holding the lock across the insert.
5. **Credit-note reversal by un-applying the original** rather than issuing a second offsetting
   document.
6. **AR aging** — one bucket registry, five Yardi buckets, as-of-date, by-charge-type with disputed
   shown beside.
7. **The PDC module** — a genuine differentiator for the Egyptian market.
8. **`assertLinesValid`, `FROZEN_ONCE_POSTED`, `withinPostingEngine`**, counting void+posted in
   reports, GRNI FIFO sibling-clearing, the WHT credit split, per-property year-end close, and
   `PostMonth` applied before change-detection.
9. **WHT and payroll shipped disabled** rather than with guessed statutory constants; the `0` vs
   `null` vendor-rate distinction.
10. **The CAM recovery engine itself** — caps including controllable scope and carry-forward, gross-up
    with four guard rails, three denominators, zone pools, ledger-sourced totals, and the closed
    estimate → reconcile → re-estimate loop.
11. **Reading the VAT return from the ledger while checking it against the documents.** §2.1 is a bug
    inside a correct design.

---

## 5. Suggested order of work

Sequenced by risk-per-hour, not by severity alone. The first block is small and buys most of the
safety.

| # | Item | § | Class | Effort |
|---|---|---|---|---|
| 1 | Hard-gate `pay-demo` + a failing health check | 1.4 | REMOVE | S |
| 2 | Credit notes into the VAT return, and give it a page | 2.1 | BUGFIX + WIRE | S |
| 3 | Status filter on the payment picker | 1.2(c) | BUGFIX | S |
| 4 | `alreadyBilledForMonth`: status filter + `nsf_fee` | 2.7 | BUGFIX | S |
| 5 | Shared `REPORTABLE` constant + the bank-rec≡ledger test | 1.1 | BUGFIX | M |
| 6 | Per-pool estimate charge codes; default non-`cam` to `stated` | 1.5 | BUGFIX | S–M |
| 7 | `billing:reconcile`: all four channels; then schedule it | 2.2, 2.18 | BUGFIX | S |
| 8 | Cap and net partial write-offs; one `written_off` predicate | 1.2 | BUGFIX | M |
| 9 | Deposit settlement posting-date guard + fix the registry entry | 2.5 | BUGFIX | S |
| 10 | `VendorBill` → penalties cascade | 2.6 | BUGFIX | S |
| 11 | Late fee onto its own dated invoice | 2.8 | BUGFIX | S |
| 12 | Month-range on the four statement pages | 2.15 | EDIT | S–M |
| 13 | WHT base excludes VAT (**before** enabling WHT) | 2.11 | BUGFIX | S |
| 14 | Seller TRN on the tax invoice | 2.12 | EDIT | S |
| 15 | Renewal: derive the clone; carry the child collections | 1.3 | REIMPLEMENT | M |
| 16 | Bank-account dimension | 2.3 | REIMPLEMENT | M |

**Blocked on the accountant, not on engineering:** §1.6 and §1.7 — and they must be answered
together, because the answer to one determines whether the other exists at all.

---

## 6. Retired claims

Recorded because a retired claim is worth as much as a finding — it stops the next person rebuilding
a thing that works.

| Claim | Verdict |
|---|---|
| "`billing:apply-late-fees` is not scheduled" | **False** (lead's own initial suspicion). It is scheduled as a *Job* at [routes/console.php:41](../../routes/console.php#L41); `DumpSystemCensus.php:181` explicitly warns that a naive `Schedule::command()` grep misses it. |
| "`deducted_amount` is a dead column / deductions are not applied" | **False.** Deductions *are* applied; an agent raised and then disproved this itself. |
| "An unregistered money source exists" | **False.** Every `decimal()` money column was diffed against all 24 registry sources; no unregistered source is a real economic event lacking an offsetting registered document. |

## 7. Stale documentation this sweep found

These actively misdirect. Fixing them is nearly free and prevents rebuilt work.

- `docs/benchmarks/yardi/02-yardi-money-flow.md` — **four stale rows inside a section labelled
  "re-verified against the code 2026-08-11"**: open-credit auto-apply (shipped and defaulted on,
  contradicting the doc's own "recommend asking the operator"), per-lease late-fee override (`:143`),
  NSF fee (`:132`), and deposit disposition + shortfall reconciliation (`:171-172`, contradicted by
  `:305` in the same file).
- `docs/benchmarks/yardi/03-yardi-recoveries-percentage-rent.md` — §A3/A4/A5/A7/A8/B2/B4/B5/B6 all
  describe absences that shipped.
- `docs/money/05-percentage-rent.md` — entirely pre-fix; documents the billing gap as current.
- `docs/money/10-utilities.md` — states there is no tariff and no consumption→charge bridge; both now
  false.
- `docs/money/03-marketing-levy.md` — claims `billing:reconcile` does not assert marketing accrual; it
  does (check 5).
- `docs/money/02-vat-and-tax.md` — **materially stale and load-bearing**: states in bold that "there is
  no global VAT-rate setting", the opposite of the shipped invariant. This is the document a finance
  person signs off from.
- `docs/modules/08-cam.md` — still calls multi-unit leases "not yet relevant" and slice-3 basis/gross-up
  "not yet built".
- `docs/gap-analysis/{05,06,07,12,13,14}.md` — dated 2026-05-31; archaeology, not a baseline.
  `14-credit-notes.md:81` states the **inverse** of current behaviour, describing the old double-counting
  path as the way to reverse an applied note. **F-28 (mid-year termination, §2.20) is the only finding
  in that set still live.**
- `docs/ROADMAP.md` — records the VAT return as "✅ shipped" when no operator can open it.
