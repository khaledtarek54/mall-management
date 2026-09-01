# Deep sweep — 2026-09-01

> **The evidence file for the sweep whose worklist is [ROADMAP §9](../ROADMAP.md#9--cycle--the-2026-09-01-deep-sweep-sw).**
> It is a record, not a second live list: when a row is picked up it moves to the ROADMAP or to
> [POST-STAGING-BACKLOG](POST-STAGING-BACKLOG.md), and when it is declined it gets a one-line row in
> [gap-analysis §6](../gap-analysis/README.md#6-declined--with-reasons-so-they-are-not-re-raised).
> Two lists of one launch is how a stale one survives.

## How this was produced, and how much to trust each row

Twenty-three finder agents read the code to break it — twelve over the money and GL seams, eleven
over the Filament panels, the tenant portal and the contractor portal — and every finding they
raised was then **adjudicated against the code by a second agent that was told to refute it**, with
a third pass on anything that survived. A finding is in this file only if it survived that.

**It is still a claim about code, and this project's own rule applies to it**: *a gap row is a claim
about code — check it against code.* Four rows were fixed the same day and each one was
independently re-verified before it was touched; two were refuted outright and are not here. Read
the row, open the file, and satisfy yourself before you act on it. Several rows will have been
overtaken by the concurrent CAM work.

**Four were fixed on the day and are NOT in the table below** — they were the three criticals and
one high, and each shipped with a mutation-proved regression test:

| What | Commit |
|---|---|
| The invoice status Select offered `written_off`, so live AR left every collection surface with no bad-debt entry, through a one-way door, using only `invoices.edit` | `9c970144` |
| Work-order **evidence was replacing, not appending** — on both doors — so a contractor's second photograph deleted their first, and the operator's attachment deleted theirs | `42e21d0b` |
| A scheduled report emailed **every mall's** rent roll to whatever addresses the schedule named, including the owner's external accountant | `a39a4c78` |
| Nothing anywhere wrote `is_portal_user`, so **no contractor could ever sign in** and the whole `/vendor` panel was inert | `14771766` |

**Status** — this file is the LIVE record and is updated as work lands, not a snapshot. `open` ·
`✅ fixed` (names the commit) · `⏳ in progress` · `↩︎ refuted on re-check` (it survived adjudication
and then did not survive contact — say why, and leave the row) · `⏭️ declined` (with the reason, and
a matching line in [gap-analysis §6](../gap-analysis/README.md#6-declined--with-reasons-so-they-are-not-re-raised)).
**Update the row in the same commit as the fix.** A status column nobody maintains is worse than
none, because it reads as current — **and so are the counts beside it**. Every subtotal below is
DERIVED from the rows by `python3 docs/qa/scripts/sweep-tally.py`; run it after changing a status
rather than editing a number. The first hand-typed set had already drifted by the third fix (the
header said 193 open over a table of 195, and the money section claimed 11 high where 7 were left),
which is the same failure this repo gates for generated doc blocks.

> ### Where this stands — 23 closed, 191 open (updated 2026-09-01)
>
> Plus the four fixed on the day of the sweep, listed above and not in the table.

**Severity** — `critical` wrong money, lost data, or something confidential leaving the building ·
`high` an operator blocked, or data quietly wrong · `medium` real friction · `low` polish.
**Fix** is the adjudicating agent's own estimate: XS under an hour, S a morning, M a day, L longer.

---

### Money · AR · settlement

*22 open — 2 high, 11 medium, 9 low.*

| ID | Status | Sev | Fix | What is wrong | Where |
|---|---|---|---|---|---|
| **SW-001** | ✅ fixed | high | S | Credit-note status Select lets 'void' be picked directly, bypassing the void service's applied-amount refusal. Broader than reported: `applied` is DERIVED (picking it makes the tenant's credit invisible to every picker narrowing on `hasBalance()`) and `issued` skipped `PostingDate::assertOpen`, so AR committed while the journal post was silently refused. **The first cut was worse than the bug** — removing the options made an applied or voided note unsaveable on every field (`Rule::in([])`), and the only remaining option was `issued`, so the sole way past the error was to un-void the note. Narrowed on draft, DISABLED after; void is terminal | `Filament/Admin/Resources/CreditNotes/Schemas/CreditNoteForm:144` |
| **SW-002** | ✅ **fixed** `2733f9af` | high | S | A deposit refund/forfeit larger than what the lease holds is accepted by the DepositTransactions create form — negative deposit liability *(reported by 2 independent agents)*. The FIFTH door onto the pot, and the only one with no cap at all: same `deposit_transactions.create`/`.edit` permissions as the lease action, `minValue(0.01)` and nothing else, and freely editable afterwards because the receipt freeze fires only for `type === 'receipt'`. Capped on the MODEL, measured against the pot less the row's own persisted contribution | `Models/DepositTransaction:300` |
| **SW-003** | open | high | S | CreatePayment's orphan rollback throws on the form's own default status — a captured, allocation-less receipt survives behind the wrong error *(reported by 2 independent agents)* | `Filament/Admin/Resources/Payments/Pages/CreatePayment:193` |
| **SW-004** | open | high | M | EditPayment re-allocation re-spends a surplus already drawn down as tenant credit — the same money settles two invoices | `Filament/Admin/Resources/Payments/Pages/EditPayment:148` |
| **SW-005** | ✅ **fixed** `ae18fe62` | high | — | Portal invoice View page offers a live Paymob checkout on a WRITTEN-OFF invoice — same file's demo button gets it right. `canPayNow()` tested no status AT ALL while `canPayDemo()` three lines below carried the denylist, so the button that spends real money was the permissive one. Both ask `isPayable()` now | `Filament/Portal/Resources/Invoices/Pages/ViewInvoice:97` |
| **SW-006** | ✅ **fixed** `ae18fe62` | high | — | A DRAFT invoice is publicly readable and publicly payable through `/pay/{token}` — the one invoice surface with no draft filter. **Reproduced over the real route: 200, naming the tenant and the amount, to an unauthenticated visitor.** `isPayable()` was a fourth hand-rolled status denylist beside `InvoiceSettlement`, which had refused `draft` from the day it was written | `Models/Concerns/Invoice/HasPaymentLink:86` |
| **SW-006b** | ✅ **fixed** `ae18fe62` | high | S | *(found while fixing SW-006 — not in the sweep.)* Every tenant payment path charged the RAW `balance`, which a write-off deliberately leaves standing. A 10,000 invoice with 6,000 forgiven asked the tenant for 10,000 on the public page, in the Paymob session, in the pivot allocation and in the demo capture — collecting it drives AR negative for that debt and leaves bad-debt expense standing against cash that arrived. `InvoiceSettlement::settleableAmount()` already capped SEVEN operator-driven call sites and **none of the tenant-driven ones**; `Invoice::payableAmount()` is now the one amount. **The review caught a worse defect the first cut introduced**: moving our RECORD to the netted figure while `PaymobClient` still charged the raw balance would have left the difference as real cardholder money with no row in Atriom at all | `Services/Paymob/PaymobPaymentInitiator:114` |
| **SW-007** | ✅ **fixed** `fe04a0f9` | high | — | A unit-owner assessment can never be charged a late fee — the sweep fatals on a null lease every night *(reported by 3 independent agents)* | `Services/LateFeeService:203` |
| **SW-008** | ✅ **fixed** `67212524` | high | M | PDC clear() settles a written-off invoice — AR relieved twice, bad debt stands, cash double-counted | `Services/PostDatedChequeService:73` |
| **SW-009** | ✅ **fixed** `2733f9af` | high | M | Two concurrent move-out settlements double-disburse the deposit: depositHeld() is a plain read inside one outer transaction, so the second settle spends and refunds a pot the first already emptied *(reported by 3 independent agents)* — the settle took **no lock at all**, and no UNIQUE index can turn the race into a duplicate key — `deposit_transactions.number` is unique, but the two writers get different numbers, so nothing constrains the POT. `Lease::depositHeldForUpdate()` is the locking twin; the LEASE is the contended row because the pot spans three tables, so `ApplyDepositToInvoiceService` locking the invoice was never a guard for it. Order: leases → invoices → deposit_transactions → deposit_applications | `Services/SettleMoveOutService:81` |
| **SW-009b** | ✅ **fixed** `2733f9af` | high | S | *(found while fixing SW-009 — not in the sweep.)* The same pot has FOUR doors and the sweep only saw the one that locked nothing. `BillSecurityDepositService` locked the lease and then read `depositUnbilledShortfall()` — a PLAIN read — beneath a comment asserting the lock made it a check-then-act guard. Two operators each read the same unbilled shortfall and each raise an invoice: the tenant is asked for **twice the security they agreed**, and `deposits_held` is credited twice on payment. The *Record deposit movement* action capped from the display twin **outside any transaction** | `Services/BillSecurityDepositService:56`, `Filament/Admin/Actions/LeaseActions:246` |
| **SW-009c** | open | medium | M | **Lock-order cycle `leases` ⇄ `units`, via an OBSERVER.** Three services take units → leases (`LeaseCreationService:45→50`, `LeaseRenewalService:60→62`, `ConvertLeaseToHoldoverService:108→110`); six paths take leases → units the other way, because `LeaseObserver::updated` → `Unit::recomputeStatus()` issues an implicit X lock on `units` (`ExpireLeasesCommand:118`, `LeaseTerminationService:74`, `LeaseExtensionService:69`, `LeaseRentChangeService:100`, `RentEscalationService:179`, `SettleMoveOutService`). A deadlock is detected and rolled back, so the symptom is an intermittent 500 rather than wrong money — but `ConcurrencyPolicy` cannot see the second edge at all, because it registers EXPLICIT locks and this one is an observer-driven UPDATE | `Observers/LeaseObserver:72` |
| **SW-009d** | open | medium | M | **Lock-order cycle `credit_notes` ⇄ `invoices` ⇄ `credit_note_applications` — three different orders in ONE file.** `applyToInvoice:55→56` is notes→invoices; `reverseAppliedCredit:197→198` runs from `Invoice::updated` while the caller holds an invoices lock, i.e. invoices→applications→notes; `reverseAllApplications:235→241→242` is notes→applications→invoices; `reverseApplication:282→289→295` is applications→invoices→notes. A void racing an apply on the same note/invoice pair deadlocks | `Services/CreditNoteService:51` |
| **SW-009e** | open | medium | S | **Lock-order cycle `payments` ⇄ `invoices`.** The gateway callback locks the payment then its invoices (`CallbackController:157` → `Payment::refitAllocationsToBalance:396`), and so does `VoidPaymentService:33`; the receipt/allocation paths lock the invoice then its payments (`Payment::assertInvoicesNotOverAllocated:298→326`). A capture landing while an operator edits an allocation on the same invoice deadlocks. Also `PostDatedChequeService::settleOpenInvoices:135` takes a RANGE lock over a tenant's open invoices — the widest in the repo, and the likeliest victim | `Models/Payment:291` |
| **SW-010** | ✅ **fixed** `2b4dd73c` | high | XS | VoidPaymentService's spent-surplus guard falls back to the GLOBAL credit balance for a zero-allocation receipt — credit at another mall masks that this receipt's surplus was already spent. A no-allocation receipt is the ORDINARY case, not an exotic one: a cleared SERIES cheque names no invoice, which is the Egyptian norm. `Payment::originatingAssetId()` already held its property — the same fact `Tenant::creditBalance()` reaches for when it attributes that credit | `Services/VoidPaymentService:59` |
| **SW-011** | ✅ **fixed** `77f088a4` | high | M | A PARTIAL write-off is invisible to every collection surface — the tenant is asked for, and can pay, the forgiven part; AR goes negative and even billing:reconcile agrees with the wrong books | `Services/WriteOffInvoiceService:129` |
| **SW-012** | open | medium | S | Tenant hub's Payments tab scopes through invoices.lease.unit — unit-owner assessment payments vanish for property-restricted operators | `Filament/Admin/RelationManagers/TenantPaymentsRelationManager:35` |
| **SW-013** | ✅ **fixed** `67212524` | medium | S | Payment allocation picker and auto-suggest offer DRAFT invoices; allocating flips a never-issued draft straight to paid | `Filament/Admin/Resources/Payments/Schemas/PaymentForm:178` |
| **SW-014** | open | medium | M | Payment/PDC invoice pickers override getOptionLabelUsing with an unscoped Invoice::find, deleting the validation write-guard the picker's narrowing relies on | `Filament/Admin/Resources/Payments/Schemas/PaymentForm:197` |
| **SW-015** | open | medium | — | Portal invoice View shows a blank unit on every owner assessment — the fix landed in the table beside it and not in the infolist | `Filament/Portal/Resources/Invoices/Schemas/InvoiceInfolist:22` |
| **SW-016** | open | medium | — | A portal filter labelled "Overdue Only" returns every unpaid invoice, and the dashboard's headline stat deep-links into it | `Filament/Portal/Resources/Invoices/Tables/InvoicesTable:138` |
| **SW-017** | open | medium | S | Receipt-in-use freeze checks the NEW type, so flipping a drawn-on receipt's type to forfeit/refund bypasses it | `Models/DepositTransaction:259` |
| **SW-018** | open | medium | M | A partially-written-off invoice is collectable at its FULL pre-write-off balance on every payment path, and the AR tie-out stays green over the resulting negative AR | `Models/Payment:318` |
| **SW-019** | ✅ **fixed** `67212524` | medium | S | A written-off invoice passes every server-side money gate for receiving cash — only option lists exclude it — so a stale repeater row or a gateway capture relieves AR twice for the same debt | `Models/Payment:371` |
| **SW-020** | open | medium | S | Voiding a cheque-clearing payment keyed in error marks the physical cheque BOUNCED — a bank return that never happened, and an NSF fee becomes billable on it | `Models/Payment:444` |
| **SW-021** | ✅ **fixed** `67212524` | medium | S | Tenant credit can be applied to a written-off (or draft) invoice — the one settlement channel missing the status guard its two siblings have | `Services/ApplyTenantCreditService:34` |
| **SW-022** | ✅ **fixed** `77f088a4` | medium | S | Late fees and dunning are charged on the forgiven slice of a partially-written-off debt | `Services/LateFeeService:158` |
| **SW-023** | open | medium | S | Voiding a partially-written-off invoice leaves the bad-debt entry standing — AR negative and the loss double-counted in P&L | `Services/VoidInvoiceService:52` |
| **SW-024** | open | low | XS | Deposit register cannot be searched or filtered by tenant | `Filament/Admin/Resources/DepositTransactions/Tables/DepositTransactionsTable:42` |
| **SW-025** | open | low | — | A payment rail with both directions off saves as an active row that no screen will ever offer | `Filament/Admin/Resources/PaymentMethods/Schemas/PaymentMethodForm:56` |
| **SW-026** | open | low | S | Payment gateway/cheque fields have no maxLength against their string(255) columns | `Filament/Admin/Resources/Payments/Schemas/PaymentForm:305` |
| **SW-027** | open | low | S | Payments and Invoices status filters offer a hand-kept subset — 'voided' (the new reversal status), bounced, settled, disputed, written_off are unfilterable | `Filament/Admin/Resources/Payments/Tables/PaymentsTable:96` |
| **SW-028** | open | low | S | PDC lodge-series preview renders English month names inside an Arabic sentence | `Filament/Admin/Resources/PostDatedCheques/Pages/ListPostDatedCheques:101` |
| **SW-029** | open | low | — | Portal invoice and payment status filters are hand-written `->only()` lists that cannot find four visible statuses each | `Filament/Portal/Resources/Invoices/Tables/InvoicesTable:97` |
| **SW-030** | open | low | S | No positive-fee guard: a 0% / 0-minimum configuration mints EGP 0.00 late-fee invoices (with tenant notifications), and each zero fee permanently blocks a real fee | `Services/LateFeeService:167` |
| **SW-031** | open | low | S | Disputed invoices are invisible to the final account — refund paid out while contested AR stands, with nothing on the statement | `Services/MoveOutStatementService:59` |
| **SW-032** | open | low | S | The final account omits on-account tenant credit — a document that promises 'everything owed in both directions' misses one pot | `Services/MoveOutStatementService:69` |

### Billing · leases

*21 open — 5 high, 11 medium, 5 low.*

| ID | Status | Sev | Fix | What is wrong | Where |
|---|---|---|---|---|---|
| **SW-033** | ✅ **fixed** `e6d9f699` | high | M | Nightly leases:expire strands every unconverted holdover: no offered path to convert, renew, or terminate an 'expired' lease | `Filament/Admin/Actions/LeaseActions:795` |
| **SW-034** | open | high | M | 'End charge' / 'End assessment' with a future stop date silently stops billing immediately — the intervening months are never invoiced | `Filament/Admin/RelationManagers/ChargeScheduleRelationManager:508` |
| **SW-035** | ✅ **fixed** `e6d9f699` | high | — | `leases:expire` empties the holdover queue every morning, making the whole LE-04 holdover conversion permanently unreachable | `Console/Commands/ExpireLeasesCommand:84` |
| **SW-036** | ✅ **fixed** `e6d9f699` | high | M | leases:expire makes holdover conversion unreachable — the entire LE-04 workflow is dead after the first night | `Console/Commands/ExpireLeasesCommand:87` |
| **SW-037** | ✅ **fixed** `aa624ab1` | high | — | Clicking a report filter's clear (×) 500s the page — the bound property is non-nullable and Livewire unsets it Thirteen screens had it, not two — every financial statement through the shared ledger bar, plus the ageing bucket, month-end close, the reports index, tax depreciation, the VAT and withholding returns and the revenue forecast. `KeepsFilterAnswered` restores a filter whose blank is not an answer; `selectablePlaceholder(false)` stops it being offered. `AClearableFilterNeverBreaksItsPageTest` DRIVES every filter-bearing admin page, because the control and the property live in different files and no source sweep can pair them. | `Filament/Admin/Pages/BillingRunPreview:62` |
| **SW-038** | open | high | — | Portal lease list shows DRAFT and PENDING_APPROVAL leases — the tenant reads terms nobody has approved | `Filament/Portal/Resources/Leases/LeaseResource:100` |
| **SW-039** | open | high | S | Writing off a partially-paid billed deposit erases the PAID portion from depositHeld — tenant's deposit silently kept at move-out | `Models/Lease:674` |
| **SW-040** | open | high | — | An assessment invoice records the calendar month it did not bill, so a resale credits the seller too little and the mall over-collects | `Services/BillUnitOwnershipsService:268` |
| **SW-041** | open | high | M | Re-running Terminate on a lease under notice stacks duplicate unearned credit notes — no idempotency on the MF-02 credit | `Services/LeaseTerminationService:176` |
| **SW-042** | open | medium | XS | Quick-new-lease wizard prefills hard-coded 7-day payment terms and 36-month term, bypassing the property/portfolio conventions the main form was already fixed to honour | `Filament/Admin/Resources/Leases/Tables/LeasesTable:393` |
| **SW-043** | open | medium | S | Re-entering an existing index month on the rent-index form hits the (code, period) DB unique with a raw 500 — no unique rule, no pointer to the edit path | `Filament/Admin/Resources/RentIndices/Schemas/RentIndexForm:42` |
| **SW-044** | open | medium | S | Rentable-items register's holder column reads the unfiltered leases relation: shows former/terminated holders, never shows unit-owner holders | `Filament/Admin/Resources/RentableItems/Tables/RentableItemsTable:54` |
| **SW-045** | open | medium | — | An imported quarterly/annual owner assessment is silently never billed and counted as an ordinary `skipped` | `Filament/Imports/ChargeImporter:94` |
| **SW-046** | open | medium | M | A catch-up billing run silently skips every lease that expired between the failed night and the re-run — today's status answers a period question | `Models/Concerns/Lease/HasLeaseTermState:255` |
| **SW-047** | open | medium | M | "End charge" on an arrears-billed charge silently forfeits the last consumed month — close() deactivates the row the next invoice still needs | `Services/ChargeScheduleService:479` |
| **SW-048** | open | medium | — | `leases.expiry_reminder_notified_at` is set and never cleared, so extending a lease's term silently kills its renewal reminder for the rest of the tenancy | `Services/LeaseExtensionService:70` |
| **SW-049** | open | medium | S | LeaseRentChangeService's inline rate↔rent derivations ignore the holdover premium — EG-40's fix does not cover the sibling copy, and the round trip compounds it | `Services/LeaseRentChangeService:70` |
| **SW-050** | open | medium | M | Immediate termination deactivates the whole schedule, so a terminated arrears lease's final consumed month is never billed | `Services/LeaseTerminationService:106` |
| **SW-051** | open | medium | M | Converting to holdover after the final-settle run double-bills the settled arrears month | `Services/MonthlyBillingService:376` |
| **SW-052** | open | medium | S | One overlapping charge schedule takes down the whole Billing Run Preview — the preview does not contain the per-lease refusal the real run contains | `Services/MonthlyBillingService:864` |
| **SW-053** | open | low | XS | Lease-clause form ships hardcoded English 'days' and 'km' suffixes that the Arabic-chrome gate cannot see | `Filament/Admin/RelationManagers/LeaseClausesRelationManager:93` |
| **SW-054** | open | low | XS | Dead private option-builders in LeasesTable still carry the pre-2026-08-28 self-holding exemption that RentableItemOptions was fixed to remove | `Filament/Admin/Resources/Leases/Tables/LeasesTable:53` |
| **SW-055** | open | low | S | 'Deposit outstanding' filter re-implements depositHeld() in SQL minus the billed-and-settled channel, so it flags leases whose deposit is fully collected | `Filament/Admin/Resources/Leases/Tables/LeasesTable:247` |
| **SW-056** | open | low | XS | Unit-ownership form and model default payment_terms_days to a literal 7, ignoring the mall's configured payment-terms convention | `Filament/Admin/Resources/UnitOwnerships/Schemas/UnitOwnershipForm:136` |
| **SW-057** | open | low | XS | CreditUnearnedBillingService issues real GL-posted credit notes against DRAFT invoices | `Services/CreditUnearnedBillingService:86` |

### Facility · vendors · procurement

*30 open — 7 high, 11 medium, 12 low.*

| ID | Status | Sev | Fix | What is wrong | Where |
|---|---|---|---|---|---|
| **SW-058** | open | high | M | No screen can assign or reassign a work order's technician after creation — the model's own reassignment-notification hook is unreachable | `Filament/Admin/Resources/FacilityWorkOrders/Schemas/FacilityWorkOrderForm:146` |
| **SW-059** | open | high | XS | Service Plans 'Generate due' header action calls a protected method cross-class — guaranteed PHP Error after generation runs | `Filament/Admin/Resources/ServicePlans/Tables/ServicePlansTable:146` |
| **SW-060** | open | high | S | Draft purchase request is a panel dead-end: submit() has no caller and every edit surface is locked to 'requested' | `Filament/Admin/RelationManagers/PurchaseRequestLinesRelationManager:53` |
| **SW-061** | open | high | M | Editing day_of_month (or frequency) on a live schedule re-walks the calendar and double-books the current period | `Filament/Admin/Resources/RecurringExpenses/Schemas/RecurringExpenseForm:104` |
| **SW-062** | open | high | — | The contractor can post to the job thread but can never read it — the operator's "Share with the contractor" reaches nobody | `Filament/Vendor/Resources/WorkOrders/Pages/ListWorkOrders:142` |
| **SW-063** | open | high | — | The quote loop is one-way: the NTE that is supposed to trigger a quote is invisible, and the decision never comes back | `Filament/Vendor/Resources/WorkOrders/Pages/ListWorkOrders:176` |
| **SW-064** | open | high | — | Every recurring EXPENSE credits the CASH account — the schedule has no paid_from and the generator omits it, so the column default wins | `Services/GenerateRecurringExpensesService:180` |
| **SW-065** | open | medium | M | Picking a vendor on an internal corrective work order 500s: the edit form offers a field the model refuses with InvalidArgumentException, and execution_type has no screen to change it | `Filament/Admin/Resources/FacilityWorkOrders/Schemas/FacilityWorkOrderForm:152` |
| **SW-066** | open | medium | S | An ISSUED (or CLOSED) work permit is fully editable on its record page — the stated 'a live authorisation is not a draft' rule is enforced only by hiding the list's Edit shortcut | `Filament/Admin/Resources/WorkPermits/Pages/EditWorkPermit:26` |
| **SW-067** | open | medium | S | A rejected purchase request shows its 'rejected' badge but the required rejection reason is rendered nowhere | `Filament/Admin/Resources/PurchaseRequests/Tables/PurchaseRequestsTable:39` |
| **SW-068** | open | medium | M | A recurring schedule can be created in a state that never books, and the screen never says so | `Filament/Admin/Resources/RecurringExpenses/Schemas/RecurringExpenseForm:113` |
| **SW-069** | open | medium | — | The contractor's only screen shows status and priority as raw database codes, in both languages | `Filament/Vendor/Resources/WorkOrders/Pages/ListWorkOrders:51` |
| **SW-070** | open | medium | — | The jobs list has no filters and opens oldest-first, so most of a contractor's screen is closed work | `Filament/Vendor/Resources/WorkOrders/Pages/ListWorkOrders:233` |
| **SW-071** | open | medium | — | act_material_cost ignores the stock ledger while partsCost() and the module doc follow it — two definitions of what a part cost the job | `Models/Concerns/FacilityWorkOrder/HasWorkOrderCost:92` |
| **SW-072** | open | medium | — | A DRAFT vendor bill counts as actual cost on the work order, and can price a contractor's SLA penalty | `Models/Concerns/FacilityWorkOrder/HasWorkOrderCost:96` |
| **SW-073** | open | medium | — | Editing day_of_month forward on a live recurring schedule books the same period twice — the UNIQUE index keys on the DATE, not the period | `Models/RecurringExpense:193` |
| **SW-074** | open | medium | — | Editing a recurring cost's `day_of_month` forward re-arms the period it has already generated — a second statutory expense for the same month, posted to the GL, that the UNIQUE index cannot catch | `Models/RecurringExpense:209` |
| **SW-075** | open | medium | — | The profile page enforces an email uniqueness the domain deliberately rejects, and can lock a contractor out of their own password change | `Providers/Filament/VendorPanelProvider:51` |
| **SW-076** | open | low | XS | Global search details on work orders and equipment read the dropped `category` column — a permanently blank 'Category' row where the trade should be, plus an untranslated raw priority code | `Filament/Admin/Resources/FacilityWorkOrders/FacilityWorkOrderResource:166` |
| **SW-077** | open | low | XS | Estimated-hours fields have no maxValue against decimal(8,2) — a fat-fingered figure is an SQL out-of-range 500, not a validation message | `Filament/Admin/Resources/FacilityWorkOrders/Schemas/FacilityWorkOrderForm:103` |
| **SW-078** | open | low | S | The work-orders list shows neither the assigned technician nor the vendor — the dispatch board cannot answer 'who is on this job?' | `Filament/Admin/Resources/FacilityWorkOrders/Tables/FacilityWorkOrdersTable:49` |
| **SW-079** | open | low | XS | Hardcoded Latin 'h' hour-suffix composed into SLA overrun strings inside column closures — Arabic panel reads '٦٧h' | `Filament/Admin/Resources/FacilityWorkOrders/Tables/FacilityWorkOrdersTable:132` |
| **SW-080** | open | low | S | Unscoped find() in contract helper-text and purchase-match placeholder reads other malls' commitment figures | `Filament/Admin/Resources/VendorBills/Schemas/VendorBillForm:86` |
| **SW-081** | open | low | XS | Vendor bill due_date can precede bill_date — no ordering rule on the date pair | `Filament/Admin/Resources/VendorBills/Schemas/VendorBillForm:207` |
| **SW-082** | open | low | S | Money inputs validate a floor but no ceiling against decimal(14,2) — MySQL-only 500 the suite cannot see | `Filament/Admin/Resources/VendorBills/Schemas/VendorBillForm:227` |
| **SW-083** | open | low | XS | The AP register and the expense register have no date-range filter, unlike every AR money list | `Filament/Admin/Resources/VendorBills/Tables/VendorBillsTable:82` |
| **SW-084** | open | low | S | Portfolio-wide (NULL asset_id) vendor contracts vanish from the notice-due chase filter and the dashboard count | `Filament/Admin/Resources/Vendors/Tables/VendorsTable:136` |
| **SW-085** | open | low | — | FacilityWorkOrderLabour is the one cost channel with no 'moved between jobs' recompute of the job it left | `Models/FacilityWorkOrderLabour:84` |
| **SW-086** | open | low | — | A recurring schedule's first period is derived from the MONTH of starts_on, so it can book a cost dated before the schedule begins — and wedge itself permanently | `Models/RecurringExpense:200` |
| **SW-087** | open | low | XS | Withholding tax at bill payment is resolved at today's rate instead of the payment date, contradicting the documented dated-catalogue invariant | `Services/VendorBillService:81` |

### HR · payroll · treasury

*22 open — 4 high, 10 medium, 8 low.*

| ID | Status | Sev | Fix | What is wrong | Where |
|---|---|---|---|---|---|
| **SW-088** | open | high | — | The per-property proration override renders as a NUMERIC box — the setting is unsettable, and anything typed is silently discarded | `Filament/Admin/Pages/PropertyOverrides:149` |
| **SW-089** | ✅ **fixed** `5041571a` | high | — | Two unvalidated free-text time fields feed cron expressions — "24:00" stops the entire scheduler | `Filament/Admin/Pages/Settings:381` |
| **SW-090** | open | high | S | Payroll add-line modal renders allowances / other deductions / deduction note / employer SI and silently discards all four on save | `Filament/Admin/RelationManagers/PayrollLinesRelationManager:232` |
| **SW-091** | open | high | S | Un-settled custody's custody_date can be back-dated into a closed period through the Edit form — row saves, GL re-post silently refused | `Filament/Admin/Resources/Custodies/Pages/EditCustody:11` |
| **SW-092** | open | high | — | Two payroll runs for the same month can both be approved concurrently — the double-pay guard takes no lock, and the advance re-check decides from a pre-lock snapshot | `Services/PayrollService:48` |
| **SW-093** | open | medium | — | The audit trail cannot be filtered or searched by WHO acted — its most-asked question is unanswerable on screen and in the scheduled CSV | `Filament/Admin/Pages/ActivityLog:207` |
| **SW-094** | open | medium | — | PropertyOverrides' Save button is ungated in the Blade — the gated getFormActions() is never rendered, so manager/viewer get a raw 403 | `Filament/Admin/Pages/PropertyOverrides:168` |
| **SW-095** | open | medium | — | Deactivating the configured withholding tax code makes the WHOLE Settings screen unsaveable | `Filament/Admin/Pages/Settings:522` |
| **SW-096** | open | medium | — | The Workflows page prints every state name in raw English — Arabic operators read English cells despite three existing status catalogues | `Filament/Admin/Pages/Workflows:92` |
| **SW-097** | open | medium | S | A terminated employee is a dead-end status: no reactivate/undo action exists on any screen, and the form has no status field | `Filament/Admin/Actions/EmployeeActions:34` |
| **SW-098** | open | medium | M | The Roles & Permissions vocabulary renders in English on the Arabic panel: ~190 checkbox labels, the role-description column, and two role pickers bypass the existing roles_list catalogue | `Filament/Admin/Resources/Roles/Schemas/RoleForm:58` |
| **SW-099** | open | medium | S | canSuspend has a self-guard but no protected-role guard — an hr user can suspend a super_admin | `Filament/Admin/Resources/Users/UserResource:91` |
| **SW-100** | open | medium | — | A lump-sum payroll run and a payslip run for the same month both approve, double-posting the salary expense | `Models/Payroll:250` |
| **SW-101** | open | medium | — | `tenant_users.locale` is read by three places and written by none — the portal ignores a retailer's stated language | `Models/TenantUser:32` |
| **SW-102** | open | medium | S | hr cannot open Payrolls at all — contradicting the seeder's own stated premise — and the payslip PDF on the employee page is withheld from hr while the figures render | `database/seeders/RolesPermissionsSeeder:882` |
| **SW-103** | open | low | — | The portfolio holdover rate is floored at 0 while the action it prefills is floored at 100 — the default can be a value the modal then refuses | `Filament/Admin/Pages/Settings:356` |
| **SW-104** | open | low | — | Turning off the approval ladder hides the tenant-request and work-order state machines too | `Filament/Admin/Pages/Workflows:49` |
| **SW-105** | open | low | — | The contractor panel has no durable language — SetLocale and the switcher route still know only two of the three auth surfaces | `Http/Middleware/SetLocale:39` |
| **SW-106** | open | low | XS | Terminate modal accepts a termination date before the hire date, silently zeroing gratuity accrual and printing negative tenure | `Filament/Admin/Actions/EmployeeActions:42` |
| **SW-107** | open | low | XS | Custody View/Edit shows a blank custodian when the employee is no longer active — the options list drops the stored value | `Filament/Admin/Resources/Custodies/Schemas/CustodyForm:28` |
| **SW-108** | open | low | S | Creating a department whose name slugs to an existing functional role silently binds membership to that role's full permission set | `Models/Department:235` |
| **SW-109** | open | low | XS | The 'nobody is paid twice for the same month' guard is scoped to one asset_id, so a consolidated run and a mall run can both pay the same employee | `Models/Payroll:257` |
| **SW-110** | open | low | — | An employee advance can be granted with a future date, posting cash out of a period that has not happened | `Services/GrantEmployeeAdvanceService:34` |

### Cross-cutting

*19 open — 4 high, 10 medium, 5 low.*

| ID | Status | Sev | Fix | What is wrong | Where |
|---|---|---|---|---|---|
| **SW-111** | open | high | — | A general (no-property) owner request is invisible to every property-restricted operator — and it is the form's DEFAULT | `Filament/Admin/Resources/OwnerRequests/OwnerRequestResource:97` |
| **SW-112** | open | high | — | The owner can neither read nor answer the conversation thread module 15 built for them | `Filament/Admin/Resources/OwnerRequests/Tables/OwnerRequestsTable:120` |
| **SW-113** | ✅ **fixed** `2b4dd73c` | high | XS | Paymob callback treats only 'captured' as terminal, not the whole received set — a late or replayed decline callback flips a 'reconciled'/'settled' payment to 'failed', silently un-paying the invoice and voiding its GL leg. The REVERSED half of the very same condition was already derived from the model, under a comment reading *"enumerate a set like this by asking the model, not by grepping the diff"*; the RECEIVED half was a literal. Both paths (the fast check and the locked re-check) now ask `Payment::isReceived()` | `Http/Controllers/Paymob/CallbackController:128` |
| **SW-114** | open | high | — | Applying an SLA penalty never re-derives the work order's cost — recompute() is saveQuietly, so the only hook that calls recomputeCosts() never fires | `Services/ApplySlaPenaltyService:45` |
| **SW-115** | open | high | — | `sales:estimate-missing` runs on the 8th and the chase it is supposed to follow runs on the 10th — the reminder can never fire for any tenant the estimate covers | `routes/console:246` |
| **SW-116** | open | medium | — | Schedule-payout modal defaults to a rail the catalogue may no longer offer | `Filament/Admin/Actions/OwnerStatementRunActions:104` |
| **SW-117** | open | medium | — | The Income Statement restores the remembered property AFTER the pin, desyncing its scope caption from the mall it is reporting | `Filament/Admin/Pages/IncomeStatement:70` |
| **SW-118** | open | medium | — | A duplicate custom-field key is a raw duplicate-key 500 — the DB has UNIQUE(model,key) and no layer above it validates | `Filament/Admin/Resources/CustomFields/Schemas/CustomFieldForm:47` |
| **SW-119** | open | medium | — | Document-wording uniqueness never fires for the house row: the scope Radio's blank state is '' not null, and MySQL's unique index does not cover NULL | `Filament/Admin/Resources/DocumentTemplates/Schemas/DocumentTemplateForm:38` |
| **SW-120** | open | medium | — | `format('M Y')` puts English months inside the Arabic panel — in a required picker's options and inside a translated sentence *(reported by 2 independent agents)* | `Filament/Admin/Resources/OwnerStatementRuns/Pages/ListOwnerStatementRuns:42` |
| **SW-121** | open | medium | — | "View working" renders the itemised owner P&L as one run-on line | `Filament/Admin/Resources/OwnerStatementRuns/Tables/OwnerStatementRunsTable:36` |
| **SW-122** | open | medium | S | A retired catalogue code bricks the record's edit form: Filament's In rule refuses every save of a violation whose category (or charge code whose tax code) was deactivated | `Filament/Admin/Resources/Violations/Schemas/ViolationForm:48` |
| **SW-123** | open | medium | — | CustomField::model is not immutable — only `key` is refused at the model, while the docblock and module doc say both are | `Models/CustomField:105` |
| **SW-124** | open | medium | — | Un-applying an SLA penalty does not lock the bill, unlike applying one — concurrent detach/waive leaves the payable understated | `Services/ApplySlaPenaltyService:57` |
| **SW-125** | open | medium | S | Recorded refunds and forfeits stay fully editable after the final account is frozen — the ChangeImpact registry's 'already had the freeze' claim covers receipts only | `Support/ChangeImpact:236` |
| **SW-126** | open | low | — | The shared evidence field accepts images only and caps nothing, while the admin form on the same collection accepts PDFs and caps size and count | `Filament/Actions/EvidenceUpload:45` |
| **SW-127** | open | low | XS | Property form accepts leasable area greater than gross area — the pair has no cross-field rule | `Filament/Admin/Resources/Assets/Schemas/AssetForm:80` |
| **SW-128** | open | low | XS | violation_date and category stay editable after the fine is billed, diverging the record from its issued invoice | `Filament/Admin/Resources/Violations/Schemas/ViolationForm:103` |
| **SW-129** | open | low | S | Global search details print the raw violation category code instead of its label | `Filament/Admin/Resources/Violations/ViolationResource:147` |
| **SW-130** | open | low | — | Two registries discover screens from a hardcoded Admin+Portal list, so the vendor panel is swept by neither | `Support/ScreenGuides:393` |

### General ledger · period · banking

*19 open — 4 high, 9 medium, 6 low.*

| ID | Status | Sev | Fix | What is wrong | Where |
|---|---|---|---|---|---|
| **SW-131** | open | high | — | A Form 41 quarter cannot survive a link or a saved view — the screen shows the full year while the emailed CSV shows the quarter | `Filament/Admin/Pages/Concerns/ScopesLedgerReport:67` |
| **SW-132** | ✅ **fixed** `aa624ab1` | high | — | Clearing any report Select unsets a non-nullable Livewire property and 500s the page Same root cause as SW-037 and fixed with it. | `Filament/Admin/Pages/Concerns/ScopesLedgerReport:130` |
| **SW-133** | open | high | — | The unallocated-entries warning exists only on screen — the PDF, CSV, XLSX, scheduled email and owner pack omit money silently | `Filament/Admin/Pages/Concerns/ScopesLedgerReport:270` |
| **SW-134** | open | high | L | Re-pointing a posting-role mapping (or a charge code's posting_role) retroactively re-derives the entire ledger history: the weekly --all sweep mass-voids/re-posts every open-period document and permanently str | `Services/Accounting/LedgerPoster:506` |
| **SW-135** | open | high | S | On estimate_basis='billed', a unit owner's estimated_paid is always 0 — the owner is billed his full annual share again despite a year of paid assessments | `Services/SyncCamPoolFromLedgerService:278` |
| **SW-136** | open | medium | S | Reopen-period row action bypasses the year-end close: postings land under a standing closing entry | `Filament/Admin/Resources/AccountingPeriods/Tables/AccountingPeriodsTable:104` |
| **SW-137** | open | medium | S | Two bank accounts can point at the same ledger account — and with the shipped chart's single bank leaf, that is the default outcome | `Filament/Admin/Resources/BankAccounts/Schemas/BankAccountForm:46` |
| **SW-138** | open | medium | S | A matched bank statement can be re-homed to another bank account, stranding matches against the wrong bank | `Filament/Admin/Resources/BankStatements/Schemas/BankStatementForm:27` |
| **SW-139** | open | medium | XS | Opening-balances preview reads $account?->name — a column ledger_accounts does not have — so account names never render and every draft line's description is null | `Services/Accounting/ImportOpeningBalancesService:96` |
| **SW-140** | open | medium | — | The trial-balance PDF ignores "Include zero balances" — the printed copy cannot answer the question the toggle exists for | `Services/Accounting/LedgerReportPdfService:22` |
| **SW-141** | open | medium | XS | Period-close gate part (b) is blind to SLA penalties applied on the period's last day: whereBetween compares the datetime applied_at against date bounds that bind as midnight | `Services/Accounting/PeriodService:102` |
| **SW-142** | open | medium | S | Bank statement CSV amount parser turns thousands-separated integer amounts into fractions: '1,234,567' imports as 1.234 and '12,500' as 12.50 | `Services/Banking/ImportBankStatementService:189` |
| **SW-143** | open | medium | M | GL tie-out and deposits tie-out read only the GLOBAL control-account mapping, so one mall-scoped accounts_receivable/accounts_payable/deposits_held override row makes books_tie_out permanently red (or masks rea | `Services/Reconciliation/BooksReconciliationService:383` |
| **SW-144** | open | medium | M | Deactivating a chart account still referenced by a bank account or payment rail makes the weekly full-history ledger sweep void-and-repost every historical entry to the generic floor account, stranding bank mat | `Support/MoneyAccount:104` |
| **SW-145** | open | low | XS | Posting-map role helper reads $get('key') but the key Select is not live — the 'expects a {group} account' hint never appears while choosing | `Filament/Admin/Resources/AccountMappings/Schemas/AccountMappingForm:34` |
| **SW-146** | open | low | XS | Posting-map list shows the chart in Arabic to every locale — the same defect already fixed in this screen's own picker | `Filament/Admin/Resources/AccountMappings/Tables/AccountMappingsTable:37` |
| **SW-147** | open | low | XS | Accounting-periods View action opens an empty modal — the resource form it renders has no fields | `Filament/Admin/Resources/AccountingPeriods/AccountingPeriodResource:57` |
| **SW-148** | open | low | XS | Bank-line matching workspace re-queries coverage 3-4 times per row, ignoring the eager load it sets up | `Filament/Admin/Resources/BankStatements/RelationManagers/LinesRelationManager:82` |
| **SW-149** | open | low | S | Chart-of-accounts import completion notification is hardcoded English | `Filament/Imports/LedgerAccountImporter:131` |
| **SW-150** | open | low | S | Auto-reversal entries store untranslatable mixed-language prose with no narrative key: the Arabic ledger renders 'قيد عكسي للقيد JE-0519 — Superseded by an updated document.' | `Services/Accounting/JournalPostingService:211` |

### Portals · mobile API

*8 open — 4 high, 1 medium, 3 low.*

| ID | Status | Sev | Fix | What is wrong | Where |
|---|---|---|---|---|---|
| **SW-151** | open | high | — | An announcement's expires_at is unvalidated, so a broadcast can deep-link every tenant to a 404 — and the record is immutable the moment it sends | `Filament/Admin/Resources/Announcements/Schemas/AnnouncementForm:124` |
| **SW-152** | open | high | — | Retiring a catalogue code makes every record already carrying it permanently unsavable — the half of the deposit bug that was never fixed | `Filament/Admin/Resources/TenantRequests/Schemas/TenantRequestForm:139` |
| **SW-153** | open | high | — | A unit owner cannot raise a tenant request: the screen is offered, the required Unit picker has zero options | `Filament/Portal/Resources/TenantRequests/Schemas/TenantRequestForm:59` |
| **SW-154** | open | high | — | `GET /me/statement?to=` prints the window it was asked for and ignores it — the statement lists rows after its own stated end date | `Services/TenantStatementPdfService:98` |
| **SW-155** | open | medium | — | A tenant request's zone silently goes stale when its unit is corrected on the Edit page, under a field labelled "auto" | `Filament/Admin/Resources/TenantRequests/Schemas/TenantRequestForm:227` |
| **SW-156** | open | low | — | The two overdue scans re-check their stamp under the lock but not the balance, so a payment landing mid-run produces a dunning notice on a settled invoice | `Console/Commands/RemindOverdueTenantsCommand:99` |
| **SW-157** | open | low | — | Two table columns named read_at: the read/unread tick on the announcement recipient list is silently dropped | `Filament/Admin/Resources/Announcements/RelationManagers/RecipientsRelationManager:58` |
| **SW-158** | open | low | — | Announcements ship a TrashedFilter with no Restore or ForceDelete anywhere — a soft-deleted notice is a dead end | `Filament/Admin/Resources/Announcements/Tables/AnnouncementsTable:123` |

### CAM · recoveries · sales

*16 open — 2 high, 10 medium, 4 low.*

| ID | Status | Sev | Fix | What is wrong | Where |
|---|---|---|---|---|---|
| **SW-159** | open | high | XS | A disputed sales declaration cannot be re-locked from any screen — the void/correct/re-bill loop is a dead end | `Filament/Admin/Actions/SalesDeclarationActions:191` |
| **SW-160** | open | high | S | Re-generating a pool with billed allocations corrupts landlord_unrecovered_amount (billed rows are skipped out of the sum) | `Services/CamReconciliationService:381` |
| **SW-161** | open | medium | S | period_year stays editable after a CAM pool is billed/reconciled — the one identity field the freeze does not cover | `Filament/Admin/Resources/CamExpensePools/Schemas/CamExpensePoolForm:110` |
| **SW-162** | open | medium | S | CAM pool status is a free Select, bypassing the markReconciled gate and permission | `Filament/Admin/Resources/CamExpensePools/Schemas/CamExpensePoolForm:186` |
| **SW-163** | open | medium | S | Correcting gross_sales after the VAT toggle leaves a stale VAT deduction that flows into the billed charge | `Filament/Admin/Resources/TenantSalesDeclarations/Schemas/TenantSalesDeclarationForm:236` |
| **SW-164** | open | medium | S | sales_exclusions KeyValue takes free-typed keys and values; unknown keys and formatted numbers are silently dropped from the charge basis | `Filament/Admin/Resources/TenantSalesDeclarations/Schemas/TenantSalesDeclarationForm:251` |
| **SW-165** | open | medium | — | Portal CAM allocation View shows a blank unit for a unit owner — the same relation the resource's own docblock says is null there | `Filament/Portal/Resources/CamAllocations/Schemas/CamAllocationInfolist:24` |
| **SW-166** | open | medium | — | Portal sales-declaration create re-checks tenant ownership but not percentage rent — the mobile API checks both | `Filament/Portal/Resources/TenantSalesDeclarations/Pages/CreateTenantSalesDeclaration:27` |
| **SW-167** | open | medium | S | The F-08 over-recovery guard checks Σ shares ≤ 100% while allocation multiplies the grossed-up basis — a stated share plus gross-up over-recovers past actual cost with the guard green | `Services/CamReconciliationService:162` |
| **SW-168** | open | medium | M | A mid-year resold unit's whole annual true-up lands on the Dec-31 owner; an ownership ended before year-end is recovered from nobody | `Services/CamReconciliationService:481` |
| **SW-169** | open | medium | S | CAM statement resolves the cap term without the pool code — two callers missed by the cap-belongs-to-a-pool change (03133a13) | `Services/CamStatementPdfService:150` |
| **SW-170** | open | medium | S | The tenant statement's 'total due' omits the recovery VAT the invoice actually charges | `Services/CamStatementPdfService:159` |
| **SW-171** | open | low | XS | View modal of a LOCKED declaration recomputes the frozen percentage-rent figure as a live estimate | `Filament/Admin/Resources/TenantSalesDeclarations/Schemas/TenantSalesDeclarationForm:293` |
| **SW-172** | open | low | — | A tenant's own uploaded sales report and request attachments are write-only — no portal screen shows them back | `Filament/Portal/Resources/TenantSalesDeclarations/Schemas/TenantSalesDeclarationInfolist:30` |
| **SW-173** | open | low | S | voidLocked stores frozen English prose in audit_notes | `Services/PercentageRentCalculationService:629` |
| **SW-174** | open | low | S | Ownership allocations have no unique-index backstop — the lease path's last line of defence against concurrent double generation is absent for owners | `database/migrations/2026_08_15_200000_a_cam_allocation_may_belong_to_an_ownership:47` |

### Reports · settings · system

*14 open — 2 high, 8 medium, 4 low.*

| ID | Status | Sev | Fix | What is wrong | Where |
|---|---|---|---|---|---|
| **SW-175** | open | high | — | "Record payment" on the collections worklist builds /admin/{tenantId}/… — a 404, and the prefill can never fire | `Filament/Admin/Pages/ArCollections:234` |
| **SW-176** | open | high | — | "Send every month" replays the as-at date frozen at save time, for ever | `Services/Reports/DeliverSavedReportService:68` |
| **SW-177** | open | medium | — | Vendor Scorecard: `operations` can read it but not export it — and can get the identical CSV by scheduling it to themselves | `Filament/Admin/Pages/Concerns/ExportsReport:74` |
| **SW-178** | open | medium | — | The trial balance is the one accounting screen whose figures cannot be opened, though it already carries the account id | `Filament/Admin/Pages/TrialBalance:192` |
| **SW-179** | open | medium | — | The marketing-post store picker is a HARD filter, so the Edit page refuses to save once the attributed retailer's lease ends | `Filament/Admin/Resources/MarketingPosts/Schemas/MarketingPostForm:48` |
| **SW-180** | open | medium | — | The marketing-post "Live" column re-derives liveFor() and drops the store clause, so the operator's column contradicts the filter beside it | `Filament/Admin/Resources/MarketingPosts/Tables/MarketingPostsTable:79` |
| **SW-181** | open | medium | — | A contractor has no language: the dispatch bell freezes in the dispatching operator's locale and their EN/AR choice never persists | `Notifications/WorkOrderDispatchedNotification:36` |
| **SW-182** | open | medium | — | The balance-sheet export drops the figure the sheet balances on, and no export carries the balance check | `Services/Reports/ReportCsvExporter:60` |
| **SW-183** | open | medium | — | Occupancy-cost and MAT windows include the current month, which has costs but no declarations — ratios overstated and colour bands flipped | `Services/Reports/ReportService:607` |
| **SW-184** | open | medium | — | A `from`/`to` report range can be inverted, and WeeklySpend then reports EGP 0.00 of spend as a fact | `Support/ReportFilters:83` |
| **SW-185** | open | low | — | The cash-flow statement is the only ledger report with no ledger-freshness line and no "Post to GL" | `Filament/Admin/Pages/CashFlow:33` |
| **SW-186** | open | low | — | OccupancyMap's headline is string-concatenated, so the Arabic panel reorders it — its sibling map does it correctly | `Filament/Admin/Pages/OccupancyMap:126` |
| **SW-187** | open | low | — | The spend↔campaign link is write-only: no column, no filter, and absent from the spend register CSV the owner reviews | `Filament/Admin/Resources/MarketingBudgets/RelationManagers/MarketingSpendsRelationManager:72` |
| **SW-188** | open | low | — | `SetApiLocale::SUPPORTED` is a second hardcoded language list, unreferenced and ungated | `Http/Middleware/SetApiLocale:17` |

### Inventory · fixed assets

*10 open — 1 high, 5 medium, 4 low.*

| ID | Status | Sev | Fix | What is wrong | Where |
|---|---|---|---|---|---|
| **SW-189** | open | high | — | weightedAverageCost() averages every receipt ever made, not the stock on hand — Inventory keeps a permanent, compounding residual for stock that is gone | `Services/StockMovementService:307` |
| **SW-190** | open | medium | XS | Dispose modal: proceeds_account picker can never appear, so bank proceeds always post to cash | `Filament/Admin/Actions/FixedAssetActions:66` |
| **SW-191** | open | medium | S | Item's Stock Movements tab shows every mall's movements to a property-restricted operator, and cannot tie out to the scoped on-hand beside it | `Filament/Admin/RelationManagers/StockMovementsRelationManager:26` |
| **SW-192** | open | medium | S | Total NBV summarizer includes disposed assets' residual book value while its label claims the balance-sheet tie-out | `Filament/Admin/Resources/FixedAssets/Tables/FixedAssetsTable:87` |
| **SW-193** | open | medium | XS | 'Fully depreciated' filter ignores salvage_value and opening_accumulated_depreciation — the write-off worklist misses exactly the assets it exists for | `Filament/Admin/Resources/FixedAssets/Tables/FixedAssetsTable:131` |
| **SW-194** | open | medium | XS | inventory_items.reorder_quantity is enterable on no screen and no importer — the draft-reorder service's 'stated reorder quantity' branch is dead in production | `Filament/Admin/Resources/InventoryItems/Schemas/InventoryItemForm:72` |
| **SW-195** | open | low | XS | Low-stock filter and red on-hand disagree with the low-stock scan: reorder_level 0 means 'off' to the alert, 'always low' to the screen | `Filament/Admin/Resources/InventoryItems/Tables/InventoryItemsTable:101` |
| **SW-196** | open | low | S | Three record pickers (custodian, transfer from/to warehouse) are bare Selects that evade the EntitySelect gate through a helper-method indirection | `Filament/Admin/Resources/StockMovements/Pages/ListStockMovements:156` |
| **SW-197** | open | low | S | Stock-movement refusals reach the operator as raw-English toast bodies, outside the refusal-translation gate by exception class | `Filament/Admin/Resources/StockMovements/Pages/ListStockMovements:257` |
| **SW-198** | open | low | S | The stock-movement ledger has no date-range, warehouse or item filter — one type filter on an append-only register | `Filament/Admin/Resources/StockMovements/Tables/StockMovementsTable:65` |

### Tax

*10 open — 2 high, 5 medium, 3 low.*

| ID | Status | Sev | Fix | What is wrong | Where |
|---|---|---|---|---|---|
| **SW-199** | ✅ **fixed** `fe0d8d46` | high | S | VAT return counts VOIDED credit notes on the documents side (credit notes are 'void', never 'cancelled'), understating filed taxable base and permanently breaking the tie-out control. **Excluding `void` outright is the obvious repair and is ALSO wrong** — caught in review before it shipped: voiding posts a REVERSAL and leaves the original `void` (a REPORTABLE status), and `reversalPeriod()` back-dates that reversal only while the period is open — so a note voided after its month closed is netted in the CURRENT month, and dropping it moved `base_standard` on an ALREADY FILED return by the whole credited supply (measured 60,000 → 80,000). The rule is about DATES, in two halves that compose through the accumulator's sign | `Services/Reports/VatReturnService:87` |
| **SW-199b** | open | high | S | *(found by the review of SW-199 — not in the sweep.)* `SyncCamPoolFromLedgerService` filters billed invoices with `whereNotIn('invoices.status', ['cancelled','written_off'])` and **omits `draft`**, which is the column DEFAULT. A never-issued draft inflates the billed estimate and therefore understates the CAM true-up, or mints a credit note. Its siblings `PercentageRentCalculationService:108` and `ReportService:937` both exclude `draft`, so the asymmetry is verified rather than assumed. **CAM is another session's area this cycle — do not fix without checking HEAD** | `Services/SyncCamPoolFromLedgerService:296` |
| **SW-199c** | open | high | S | *(found by the review of SW-199.)* `AssetStatementPdfService` filters `['cancelled','credited']` and omits **`written_off`** and **`draft`**. A write-off deliberately leaves `balance` standing, so `where('balance','>',0)` two lines later puts already-relieved bad debt on the OWNER's outstanding list. Every sibling AR read excludes it — `TenantLedger:51`, `TenantStatementPdfService:101`, `DepositHoldings:83`, `Lease::DEPOSIT_BILLING_EXCLUDED_STATUSES` | `Services/AssetStatementPdfService:59` |
| **SW-199d** | open | medium | S | *(found by the review of SW-199.)* `BooksReconciliationService` matches credit notes with NO status filter, so a CAM allocation still `billed` whose backing note was VOIDED passes `$hasCredit` and the reconciler reports clean — a check that cannot fail, the family CLAUDE.md gates for | `Services/Reconciliation/BooksReconciliationService:183` |
| **SW-199e** | open | medium | S | *(found by the review of SW-199.)* A DRAFT credit note downloads as an unwatermarked, numbered tax document: `CreditNotePdfService` watermarks on `status === 'void'` only, and neither download action gates on status. `CreditNote::isOnTheBooks()` is exactly the predicate. The exporter has no on-the-books marker either, so summing its `total` column double-counts drafts and voids | `Services/CreditNotePdfService:57` |
| **SW-199f** | open | medium | M | *(found by the review of SW-199.)* `CreditNoteJournalizer` gates on the hand-rolled allowlist `['issued','applied']` — the complement of `CreditNote::NOT_ON_THE_BOOKS` by coincidence, not derivation. A fifth status would be COUNTED by the documents side (exclusion) and SKIPPED by the GL (allowlist), silently. `CreditNote::hasBalance()` is a third copy of the same judgement | `Services/Accounting/Journalizers/CreditNoteJournalizer:27` |
| **SW-200** | open | medium | — | The VAT and withholding returns show a pinned per-mall "Property scope" while both deliberately compute portfolio-wide | `Filament/Admin/Pages/VatReturn:200` |
| **SW-201** | open | medium | S | Deleting the last rate rung of an ACTIVE tax code silently re-rates billing to the 14% VAT floor | `Filament/Admin/Resources/TaxCodes/RelationManagers/RatesRelationManager:111` |
| **SW-202** | open | low | — | Clearing the Tax depreciation year renders a full schedule of zeros for year 0 | `Filament/Admin/Pages/TaxDepreciation:84` |
| **SW-203** | open | low | XS | Insurable-wage ceiling rule gte:insurable_wage_floor refuses a rung with a ceiling and a blank floor, though a null floor is legal ('no bound') | `Filament/Admin/Resources/PayrollRates/Schemas/PayrollRateForm:50` |
| **SW-204** | open | low | XS | Withholding rate rungs are stored negative by convention, but the rung form clamps minValue(0) — a seeded WH rung cannot even be re-saved | `Filament/Admin/Resources/TaxCodes/RelationManagers/RatesRelationManager:46` |
