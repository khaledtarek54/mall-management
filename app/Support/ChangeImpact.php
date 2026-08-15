<?php

namespace App\Support;

use App\Models\CreditNote;
use App\Models\Custody;
use App\Models\CustodyTransaction;
use App\Models\DepositApplication;
use App\Models\DepositTransaction;
use App\Models\DepreciationEntry;
use App\Models\Disbursement;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceRepayment;
use App\Models\Expense;
use App\Models\FixedAsset;
use App\Models\FixedAssetDisposal;
use App\Models\Invoice;
use App\Models\InvoiceWriteOff;
use App\Models\SlaPenalty;
use App\Models\MarketingSpend;
use App\Models\OwnerStatementRun;
use App\Models\Payment;
use App\Models\Payroll;
use App\Models\StockMovement;
use App\Models\StraightLineRentAdjustment;
use App\Models\TenantCreditApplication;
use App\Models\VendorBill;
use App\Models\VendorBillPayment;

/**
 * **The** register of what a change to a committed money document is allowed to do to the ledger.
 *
 * There is a registry for what posts to the GL (`LedgerPoster::JOURNALIZERS`), for posting-date
 * guards, for deletion, for property isolation, for search, for dashboards and for action authz —
 * and until now none for the question an operator actually asks: *I changed this. What happened to
 * the books, and was I allowed to?*
 *
 * **Why it matters here more than in most systems.** Atriom's ledger is a PROJECTION, not a journal.
 * Yardi Voyager posts at transaction time and never looks back; `LedgerPoster::sync()` re-derives
 * each entry from the document's current state and, when they differ, voids the stale entry and
 * posts a fresh one. That is arithmetically stronger (the books cannot drift) and evidentially
 * weaker (an entry records what the document says now, not what was booked in March). So *which*
 * changes may reach a posted entry is a policy decision, and it was previously carried in people's
 * heads and in four scattered `updating` hooks.
 *
 * **The rule the verdicts encode:** a change may re-derive a posted entry only while that entry is
 * not yet evidence — filed with the ETA, delivered to the counterparty, inside a closed period, or
 * inside an issued owner statement. Past that line the correction is a new document.
 *
 * **What `DERIVED` really means.** `LedgerPoster::matches()` compares three things: the line
 * signature (account + amount), the `entry_date`, and the `asset_id` dimension. A field is DERIVED
 * when changing it moves one of those. A field that only reaches the entry's *description* is
 * DESCRIPTIVE: it will be written into a fresh post but will never cause a re-derive, so the ledger
 * can hold a description naming a value the document no longer has. That is a deliberate,
 * documented asymmetry rather than a bug — descriptions are not the books.
 *
 * `ChangeImpactConformanceTest` enforces four things: every fillable of every posting source is
 * classified (a new money source cannot ship undecided), every REFUSED field is *proved* refused by
 * dirtying it on a committed fixture, every field a journalizer reads is non-neutral, and the
 * source's own posting-date column is never classified as neutral.
 *
 * @see docs/accounting/CHANGE-IMPACT-PLAN.md
 */
class ChangeImpact
{
    /** Immutable once committed; the correction is a new document (void + re-issue, credit note, …). */
    public const REFUSED = 'refused';

    /** Editable; the posted entry is voided and re-posted to match. The operator must be told. */
    public const DERIVED = 'derived';

    /** Editable; affects FUTURE documents only. Posted entries are never rewritten. */
    public const PROSPECTIVE = 'prospective';

    /** Reaches the entry's description text but not its lines, date or dimension — never re-derives. */
    public const DESCRIPTIVE = 'descriptive';

    /** No ledger consequence at all. */
    public const NEUTRAL = 'neutral';

    public const VERDICTS = [self::REFUSED, self::DERIVED, self::PROSPECTIVE, self::DESCRIPTIVE, self::NEUTRAL];

    /**
     * Source model => its change policy.
     *
     * `committed` states, in one sentence, when the document stops being a draft — that is the
     * moment REFUSED starts biting, and the fixture the gate builds must be in that state.
     *
     * REFUSED / DERIVED / PROSPECTIVE / DESCRIPTIVE are `field => why` maps because each is a
     * decision someone made. NEUTRAL is a flat list: its reason is definitional and uniform — the
     * field never reaches a journalizer payload and never decides a period — so a reason per field
     * would be 150 repetitions of the same sentence. Where a neutral field is *surprising*, the
     * reason is an inline comment, which is where a reader will look for it.
     *
     * @var array<class-string, array<string, mixed>>
     */
    public const POLICY = [

        // ───────────────────────────── Receivables ─────────────────────────────

        Invoice::class => [
            'committed' => 'anything past draft — an issued invoice is a live AR and GL document',
            self::REFUSED => [
                'issue_date' => 'it IS the entry date, so it decides which period recognises the revenue',
                'tenant_id' => 'the AR dimension — re-pointing an issued invoice books the receivable against someone else',
                'asset_id' => 'THE property dimension of the GL entry (denormalized 2026-08-15). Re-pointing an issued invoice books its revenue into another mall\'s P&L and another owner\'s statement',
                'unit_ownership_id' => 'the other half of the AR document\'s identity — the unit sale that raised it. Re-pointing an issued assessment moves a live receivable to a different owner',
                'lease_id' => 'the AR document\'s identity — which agreement raised it. It no longer carries the property (see asset_id above), but re-pointing an issued invoice still re-parents a live receivable',
            ],
            self::DERIVED => [
                'status' => 'draft and cancelled have no GL effect, so a status move posts or reverses the whole entry',
                'is_opening_balance' => 'flipping it decides whether the invoice posts at all — an opening item is sub-ledger only, because its revenue was earned in the previous system and already sits in the accountant\'s opening journal entry. Setting it on a posted invoice must void that entry; clearing it must post one. DERIVED, not REFUSED, because correcting a mis-flagged migration row is legitimate work during a cutover and the re-derive is exactly the right outcome',
                'subtotal' => 'the revenue side of the entry',
                'vat_amount' => 'the VAT payable credit',
                'total' => 'the AR debit. Deliberately NOT refused: the CAM true-up legitimately rewrites an issued invoice\'s totals (via saveQuietly, which skips the model event anyway), and guarding derived floats risks round-trip false positives. The form lock is what stops an operator. (LateFeeService was the other example here until 2026-08-11, when the fee became its own dated invoice — appending it restated an issued document AND booked the penalty in the original invoice\'s month.)',
            ],
            self::NEUTRAL => [
                'due_date', 'period_start', 'period_end', 'currency',
                // The link to the late-fee invoice raised because this one went unpaid. It records
                // WHY that document exists and makes the charge idempotent; it is read by no
                // journalizer and changes no line, so it cannot move the books.
                'late_fee_invoice_id',
                // Revenue is recognised at ISSUE, so how much has since been collected does not
                // touch this entry — a payment posts its own (Dr Bank / Cr AR). These three are
                // the AR sub-ledger's derived state, not the invoice's GL effect.
                'paid_amount', 'credit_applied_amount', 'balance',
                'eta_submission_id', 'eta_submitted_at', 'eta_response', 'eta_status', 'eta_long_id',
                'notes', 'owner_overdue_notified_at', 'tenant_overdue_notified_at',
            ],
            self::DESCRIPTIVE => [
                'number' => 'names the entry ("Invoice INV-…"). Immutable in practice — AllocatesDocumentNumber assigns it in `creating` and nothing rewrites it',
            ],
        ],

        Payment::class => [
            'committed' => 'received (captured or any RECEIVED_STATUSES) — the cash is on the books',
            self::REFUSED => [
                'amount' => 'the cash leg; correcting it means voiding the receipt and re-recording',
                'payment_date' => 'it IS the entry date — the period the cash landed in',
            ],
            self::DERIVED => [
                'status' => 'only a received payment posts; refunded/bounced reverses the entry',
                'method' => 'chooses the cash vs bank account the credit lands in',
                // Not refused today, and a candidate for promotion: re-pointing a captured receipt
                // re-books the AR credit against another tenant. The allocation guards make it hard
                // to reach and nothing legitimate does it.
                'tenant_id' => 'the AR dimension of the credit leg',
            ],
            self::NEUTRAL => [
                'currency', 'gateway', 'channel', 'gateway_transaction_id', 'gateway_response',
                'cheque_number', 'cheque_clearance_date', 'notes', 'received_by', 'receipt_notified_at',
            ],
            self::DESCRIPTIVE => [
                'reference' => 'names the entry; regenerated in `creating` and never rewritten after',
            ],
        ],

        CreditNote::class => [
            'committed' => 'anything past draft — an issued note reverses revenue',
            self::REFUSED => [
                'issue_date' => 'it IS the entry date',
                'tenant_id' => 'the AR dimension of the credit leg',
                'invoice_id' => 'the receivable being reversed',
                'lease_id' => 'the property dimension. May be bound ONCE from null — a standalone note adopting the property of the first invoice it settles — but re-homing a scoped note stays refused',
            ],
            self::DERIVED => [
                'status' => 'draft and void have no GL effect',
                'total' => 'the AR credit',
                'vat_amount' => 'the VAT debit; the sales-returns line is derived as total − vat',
            ],
            self::NEUTRAL => [
                'reason', 'reason_notes', 'currency', 'issued_by_user_id', 'applied_at', 'voided_at', 'notes',
                // Not read by the journalizer at all — the sales-returns line is derived as
                // `total − vat_amount`, so subtotal is a display figure as far as the books go.
                'subtotal',
                // The AR sub-ledger's derived state; applying a note posts its own entry.
                'applied_amount', 'balance',
            ],
            self::DESCRIPTIVE => [
                'number' => 'names the entry',
            ],
        ],

        TenantCreditApplication::class => [
            'committed' => 'on creation — applying credit is the event, and it posts immediately',
            self::DERIVED => [
                'tenant_id' => 'the counterparty of the Dr Unearned / Cr AR pair',
                'invoice_id' => 'the receivable being settled',
                'asset_id' => 'the books dimension',
                'amount' => 'both legs',
                'entry_date' => 'stamped at APPLICATION time, never the source receipt\'s date — that decoupling is what lets an old overpayment settle a current invoice without stranding the entry in a closed period',
            ],
            self::NEUTRAL => ['created_by', 'notes'],
        ],

        DepositApplication::class => [
            'committed' => 'on creation — netting a deposit against an invoice posts immediately',
            self::DERIVED => [
                'tenant_id' => 'the counterparty',
                'invoice_id' => 'the receivable being settled',
                'asset_id' => 'the books dimension',
                'amount' => 'both legs',
                'entry_date' => 'application time, for the same decoupling reason as tenant credit — a deposit taken three years ago must be able to settle a current invoice',
            ],
            self::NEUTRAL => ['lease_id', 'created_by', 'notes'],
        ],

        InvoiceWriteOff::class => [
            'committed' => 'on creation — the write-off is the event',
            self::DERIVED => [
                'invoice_id' => 'the receivable cleared',
                'tenant_id' => 'the counterparty',
                'asset_id' => 'the books dimension',
                'amount' => 'Dr bad-debt expense / Cr AR',
                'entry_date' => 'the period the bad debt is recognised in',
            ],
            self::NEUTRAL => ['reason', 'notes', 'created_by'],
        ],

        DepositTransaction::class => [
            'committed' => 'on creation — a deposit receipt/refund/forfeit is cash moving',
            self::DERIVED => [
                'type' => 'decides the whole recipe — receipt, refund or forfeit post different pairs',
                'amount' => 'both legs',
                'asset_id' => 'the books dimension',
                'lease_id' => 'the lease the liability is held against',
                'tenant_id' => 'the counterparty',
                'transaction_date' => 'it IS the entry date',
                'method' => 'chooses cash vs bank',
                'status' => 'a cancelled transaction has no GL effect',
            ],
            self::NEUTRAL => ['notes', 'created_by_user_id'],
            self::DESCRIPTIVE => ['number' => 'names the entry'],
        ],

        StraightLineRentAdjustment::class => [
            'committed' => 'on creation — the recognition entry is the document. Ships OFF pending the accountant',
            self::DERIVED => [
                'lease_id' => 'the lease whose revenue is being straight-lined',
                'asset_id' => 'the books dimension',
                'period' => 'the month being recognised',
                'adjustment_amount' => 'the deferred-rent movement',
                'entry_date' => 'the END of the month being recognised, never today — a recognition entry belongs in the period it recognises or that month\'s P&L is wrong',
            ],
            self::NEUTRAL => [
                // The two inputs the adjustment is computed FROM; the entry posts the difference.
                'billed_amount', 'straight_line_amount',
            ],
        ],

        // ─────────────────────────────── Payables ───────────────────────────────

        VendorBill::class => [
            'committed' => 'anything past draft — an approved bill recognises the payable',
            self::REFUSED => [
                'subtotal' => 'the expense side; editing a posted bill re-derives the GL at the new total while payments stay applied — overstated expense and a phantom balance on a "paid" bill',
                'vat_amount' => 'the recoverable input VAT',
                'vendor_id' => 'the counterparty of the payable',
                'category' => 'chooses the expense account the net books to',
                'purchase_request_id' => 'the procurement link, and the property it must agree with',
            ],
            self::DERIVED => [
                'status' => 'draft and cancelled have no GL effect',
                'bill_date' => 'it IS the entry date. Editable, and guarded separately: a CHANGED date is re-checked against a closed period on both create and edit',
                'asset_id' => 'the books dimension; guarded by assertAssetInScope on create and edit',
                'total' => 'the AP credit — derived from subtotal + vat, both of which are refused',
            ],
            self::NEUTRAL => [
                'vendor_contract_id', 'due_date', 'reference', 'description', 'currency',
                'approved_by_user_id', 'created_by_user_id', 'approved_at',
                // The tax CLASSIFICATION, not the tax. The journalizer books `vat_amount` to
                // `vat_recoverable`; it never reads the code, so re-classifying a posted bill moves
                // no line and re-derives no entry. It changes which line of the VAT RETURN the
                // input tax is reported on — which is a document-side question, and correcting a
                // mis-classified bill is legitimate work rather than something to refuse.
                'tax_code', 'tax_override_reason',
                // AP sub-ledger state; a payment and a penalty each post their own entry.
                'paid_amount', 'penalty_applied_amount', 'balance',
            ],
            self::DESCRIPTIVE => ['number' => 'names the entry'],
        ],

        VendorBillPayment::class => [
            'committed' => 'on creation — the cash left the bank. Reversed by VoidVendorBillPaymentService, never edited',
            self::REFUSED => [
                // Promoted from DERIVED once the void existed. Locking these without a reversal
                // path would have trapped an operator holding a wrong cheque, so the correction
                // had to ship first — a refusal is only as good as the path it names.
                'amount' => 'the gross AP debit; correcting it means voiding the payment and re-recording',
                'withholding_amount' => 'splits the credit between bank and the withholding-tax liability owed to the ETA — editing it after the fact misstates what is owed the tax authority',
                'payment_date' => 'it IS the entry date, so it decides which period the cash left in',
                'vendor_bill_id' => 'the payable being settled, and the source of the books dimension — re-pointing settles a different vendor\'s claim',
            ],
            self::DERIVED => [
                'method' => 'chooses cash vs bank',
            ],
            self::NEUTRAL => ['notes', 'created_by_user_id'],
            self::DESCRIPTIVE => ['reference' => 'names the entry'],
        ],

        Expense::class => [
            'committed' => 'recorded — which is also its normal working state, so it is posted AND editable',
            self::REFUSED => [
                // Decided on the Yardi standard 2026-08-11: Voyager does not let a posted payable
                // be edited. `recorded` IS posted here — there is no draft — so these are immutable
                // from birth and the correction is cancel + re-enter, exactly as on VendorBill.
                'amount' => 'the expense itself; `total` is derived from it on every write, so editing it re-derives the posted entry',
                'vat_amount' => 'the recoverable input VAT',
                'category' => 'chooses the expense account the net books to',
                'paid_from' => 'chooses whether the credit left cash or the bank',
            ],
            self::DERIVED => [
                'status' => 'only `recorded` posts; cancelling reverses',
                'total' => 'the credit to cash/bank, and the expense debit is derived as total − vat. Not independently settable — the `saving` hook recomputes it from amount + vat, both of which are refused',
                'expense_date' => 'it IS the entry date. Editable on purpose, with its own posting-date guard: re-dating a correctly-keyed expense does not restate what was spent',
                'asset_id' => 'the books dimension, guarded by assertAssetInScope',
            ],
            self::NEUTRAL => [
                'reference', 'description', 'created_by_user_id',
                // The tax CLASSIFICATION, not the tax — see VendorBill. `vat_amount` is what the
                // journalizer books; the code says which line of the VAT return it belongs on, and
                // correcting that on a posted expense moves no GL line.
                'tax_code', 'tax_override_reason',
            ],
            self::DESCRIPTIVE => ['number' => 'names the entry'],
        ],

        SlaPenalty::class => [
            'committed' => 'applied — an assessed (`final`) penalty is owed but not yet deducted, and posts nothing',
            self::DERIVED => [
                'status' => 'only `applied` posts; waiving or detaching reverses',
                'vendor_bill_id' => 'the payable the penalty reduces',
                'asset_id' => 'the books dimension',
                'amount' => 'both legs',
                'applied_at' => 'the entry date. Applying always stamps it, so the created_at fallback never decides a real entry\'s period',
            ],
            self::NEUTRAL => [
                'facility_work_order_id', 'vendor_id', 'vendor_contract_id', 'currency',
                'finalised_at', 'waived_at', 'waived_by_user_id', 'waive_reason',
                // The inputs the service computes `amount` from, not the posted figure itself.
                'basis', 'rate', 'hours_over_sla',
            ],
        ],

        Payroll::class => [
            'committed' => 'anything past draft',
            self::DERIVED => [
                'status' => 'a draft or cancelled run posts nothing',
                'period_month' => 'it IS the entry date — the month the salary expense belongs to',
                'asset_id' => 'the books dimension',
                'gross_salaries' => 'the salaries expense debit',
                'salary_tax' => 'withheld — a credit to the salary-tax liability',
                'social_insurance' => 'withheld — a credit to the social-insurance liability',
                'employer_social_insurance' => 'the employer\'s own cost, expensed and accrued',
                'advance_deductions' => 'recovers an outstanding advance rather than paying cash',
                'other_deductions' => 'reduces the net paid',
                'net_paid' => 'what actually leaves the bank',
                'paid_from' => 'chooses cash vs bank',
            ],
            self::NEUTRAL => [
                'description', 'approved_by_user_id', 'created_by_user_id', 'approved_at',
                // Folded into `gross_salaries` by the service before posting.
                'allowances',
            ],
            self::DESCRIPTIVE => ['number' => 'names the entry'],
        ],

        // ──────────────────────── Assets, stock and cash ────────────────────────

        StockMovement::class => [
            'committed' => 'on creation — a receipt, issue or adjustment is stock moving',
            self::DERIVED => [
                'type' => 'decides the recipe entirely; a transfer between warehouses posts nothing',
                'quantity' => 'value = |quantity| × unit_cost',
                'unit_cost' => 'the other half of the value',
                'moved_on' => 'it IS the entry date',
                'warehouse_id' => 'the entry is dimensioned to the warehouse\'s property, not the movement\'s',
            ],
            self::NEUTRAL => [
                'reference', 'source_type', 'source_id', 'moved_by_user_id', 'notes',
                // Inventory is one account role, not one per item, so which item moved does not
                // change the posting — only its value does.
                'inventory_item_id',
            ],
        ],

        FixedAsset::class => [
            'committed' => 'on creation — acquisition is capitalised immediately',
            self::DERIVED => [
                'acquisition_cost' => 'the capitalised amount',
                'acquisition_date' => 'it IS the entry date',
                'funded_from' => 'chooses the credit — cash, bank or payable',
                'asset_id' => 'the books dimension',
                'is_opening_balance' => 'flipping it decides whether the asset posts an acquisition AT ALL. An asset loaded at cut-over was bought before this system existed and its cost is already inside the accountant\'s opening journal entry, so the journalizer returns null. Setting it on a posted asset must void that entry; clearing it must post one. DERIVED rather than REFUSED for the same reason as `invoices.is_opening_balance`: correcting a mis-flagged migration row is legitimate work during a cutover, and the re-derive is exactly the right outcome.',
            ],
            self::PROSPECTIVE => [
                'opening_accumulated_depreciation' => 'the write-off taken before Atriom existed. It posts nothing itself — the accountant\'s opening entry carries it — but it reduces the carrying amount, so it changes how much depreciation REMAINS and therefore every future charge. It also feeds the disposal entry\'s gain or loss, which is a future document too.',
                'useful_life_months' => 'changes FUTURE depreciation entries; the periods already posted are their own documents and are not rewritten',
                'salvage_value' => 'same — it changes the depreciable base going forward',
                'method' => 'same — straight-line vs reducing-balance applies to periods not yet run',
            ],
            self::NEUTRAL => [
                'tag', 'category', 'status', 'disposed_on', 'notes',
            ],
            self::DESCRIPTIVE => ['name' => 'names the entry'],
        ],

        DepreciationEntry::class => [
            'committed' => 'on creation — a depreciation row IS the posting',
            self::DERIVED => [
                'period_month' => 'it IS the entry date — the month being depreciated',
                'amount' => 'both legs',
            ],
            self::NEUTRAL => [
                'created_by_user_id',
                // The parent carries the books dimension; re-pointing a posted depreciation row at
                // another asset is not a path anything offers.
                'fixed_asset_id',
            ],
        ],

        FixedAssetDisposal::class => [
            'committed' => 'on creation — disposal derecognises the asset',
            self::DERIVED => [
                'disposed_on' => 'it IS the entry date',
                'proceeds' => 'the cash received, and the gain/loss that balances it',
                'proceeds_account' => 'where the proceeds land',
            ],
            self::NEUTRAL => ['fixed_asset_id', 'notes', 'created_by_user_id'],
        ],

        EmployeeAdvance::class => [
            'committed' => 'on creation — the advance is cash out',
            self::DERIVED => [
                'amount' => 'both legs',
                'advance_date' => 'it IS the entry date',
                'paid_from' => 'chooses cash vs bank',
                'asset_id' => 'the books dimension, denormalised onto the row so no relation can strand it',
            ],
            self::NEUTRAL => ['employee_id', 'type', 'notes', 'created_by_user_id'],
        ],

        EmployeeAdvanceRepayment::class => [
            'committed' => 'on creation',
            self::DERIVED => [
                'amount' => 'both legs',
                'repaid_on' => 'it IS the entry date',
                'method' => 'chooses cash vs bank',
                'asset_id' => 'the books dimension',
            ],
            self::NEUTRAL => ['employee_advance_id', 'notes', 'created_by_user_id'],
        ],

        Custody::class => [
            'committed' => 'on creation — عهدة granted is cash advanced',
            self::DERIVED => [
                'amount' => 'both legs',
                'custody_date' => 'it IS the entry date',
                'paid_from' => 'chooses cash vs bank',
                'asset_id' => 'the books dimension',
            ],
            self::NEUTRAL => ['employee_id', 'reference', 'purpose', 'created_by_user_id'],
        ],

        CustodyTransaction::class => [
            'committed' => 'on creation',
            self::DERIVED => [
                'type' => 'spend vs return post opposite pairs',
                'amount' => 'both legs',
                'transaction_date' => 'it IS the entry date',
                'category' => 'chooses the expense account a spend books to',
                'method' => 'chooses cash vs bank on a return',
                'asset_id' => 'the books dimension',
            ],
            self::NEUTRAL => ['custody_id', 'notes', 'created_by_user_id'],
        ],

        MarketingSpend::class => [
            'committed' => 'on creation. Deliberately NOT locked — edits fully reconcile through the budget and the GL cascade, so locking would remove a valid correction for no integrity gain',
            self::DERIVED => [
                'amount' => 'both legs',
                'spent_on' => 'it IS the entry date',
                'paid_from' => 'chooses cash vs bank',
                'category' => 'chooses the expense account',
                'marketing_budget_id' => 'the budget carries the property — a spend with no property has no place in the per-property books',
            ],
            self::NEUTRAL => ['marketing_post_id', 'description', 'receipt_reference', 'created_by_user_id'],
        ],

        // ──────────────────────────── Owner accounting ────────────────────────────

        OwnerStatementRun::class => [
            'committed' => 'finalised — a draft run posts nothing',
            self::DERIVED => [
                'status' => 'the accrual is dated at finalise; a draft has no GL effect',
                'posting_date' => 'it IS the entry date',
                'asset_id' => 'the books dimension',
                'net_distributable' => 'what the owner is owed — both legs',
            ],
            self::NEUTRAL => [
                'accounting_period_id', 'period_start', 'period_end', 'basis',
                'total_revenue', 'total_expense', 'net_operating_income', 'income_breakdown',
                'version', 'supersedes_id', 'finalised_at', 'finalised_by_user_id',
            ],
            self::DESCRIPTIVE => ['reference' => 'names the entry'],
        ],

        Disbursement::class => [
            'committed' => 'paid — scheduled and approved disbursements post nothing',
            self::DERIVED => [
                'status' => 'only a paid disbursement posts',
                'paid_on' => 'it IS the entry date — the day the owner was actually paid',
                'amount' => 'both legs',
                'method' => 'chooses cash vs bank',
                'asset_id' => 'the books dimension',
            ],
            self::NEUTRAL => [
                'owner_statement_id', 'user_id', 'required_permission',
                'approved_by_user_id', 'approved_at', 'external_reference',
            ],
            self::DESCRIPTIVE => ['reference' => 'names the entry'],
        ],
    ];

    /** Every model with a declared change policy — which must be exactly LedgerPoster::sources(). */
    public static function sources(): array
    {
        return array_keys(self::POLICY);
    }

    /** The verdict for one field, or null when the field is unclassified (which the gate fails on). */
    public static function verdictFor(string $model, string $field): ?string
    {
        foreach (self::VERDICTS as $verdict) {
            if (in_array($field, self::fields($model, $verdict), true)) {
                return $verdict;
            }
        }

        return null;
    }

    /**
     * The field names carrying one verdict for one model. Handles both shapes — `field => why`
     * maps for the decided verdicts, and a flat list for NEUTRAL.
     *
     * @return array<int, string>
     */
    public static function fields(string $model, string $verdict): array
    {
        $block = self::POLICY[$model][$verdict] ?? [];

        // A policy also carries the `committed` sentence, which is a string, so a caller passing
        // something that is not a verdict must get an empty list rather than a crash.
        if (! is_array($block)) {
            return [];
        }

        // Discriminate on the KEY, not on array_is_list: the decided verdicts are `field => why`
        // maps and NEUTRAL is a flat list, and this reads both without caring which it got.
        $out = [];
        foreach ($block as $key => $value) {
            $out[] = is_int($key) ? (string) $value : (string) $key;
        }

        return $out;
    }

    /** The reason recorded for a decided field, or null for NEUTRAL (whose reason is definitional). */
    public static function reasonFor(string $model, string $field): ?string
    {
        foreach ([self::REFUSED, self::DERIVED, self::PROSPECTIVE, self::DESCRIPTIVE] as $verdict) {
            $block = self::POLICY[$model][$verdict] ?? [];
            if (! is_array($block)) {
                continue;
            }
            // A strict === is the whole discrimination: a NEUTRAL block is int-keyed, and an int
            // is never identical to the string field name, so a neutral field returns null here
            // (correctly — its reason is definitional, not written per field).
            foreach ($block as $key => $why) {
                if ($key === $field) {
                    return (string) $why;
                }
            }
        }

        return null;
    }

    /** Every field this model refuses to have changed once committed. */
    public static function refusedFields(string $model): array
    {
        return self::fields($model, self::REFUSED);
    }
}
