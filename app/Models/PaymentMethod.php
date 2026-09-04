<?php

namespace App\Models;

use App\Models\Concerns\IsCodeCatalogue;
use App\Models\Concerns\RefusesDeletionWhenReferenced;
use App\Services\Accounting\AccountResolver;
use App\Support\ActivityLogging;
use App\Support\Attributes\DeletableWhenUnused;
use App\Support\Attributes\PortfolioShared;
use App\Support\ValueSets;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * قناة سداد — one way money moves in or out, and where it lands in the books.
 *
 * ## Why this is a row
 *
 * Egypt's rails keep moving. `ValueSets`' own docblock named the problem — *"Fawry, Meeza, Aman,
 * Vodafone Cash"* — and then held them in a PHP `const`, so adding one was a 9–14 file deploy
 * including two lang catalogues, two `->only()` filter lists and a hardcoded count in a test. There
 * were also FOUR parallel lists that had drifted, which is why a security deposit received by
 * InstaPay could not be recorded as InstaPay.
 *
 * Same shape as {@see ChargeCode}, deliberately: a code the documents already store, a bilingual
 * name, and the account it lands in. That parallel is the point — revenue asks a charge code which
 * account it books to, and money should ask the rail.
 *
 * ## The floor, and why nothing moves on deploy
 *
 * `ledger_account_id` is NULLABLE and null is the normal state. {@see accountFor()} then falls back
 * to `cash` for cash and `bank` for everything else — exactly what the journalizers hard-coded. So
 * this ships behaviour-identical, in the same way `Vat::EXEMPT_TYPES` floors an unseeded tax
 * catalogue. An operator opts in one rail at a time.
 *
 * It is worth saying what the floor is WRONG about, because that is the defect this exists to let
 * an operator fix: a card capture debits `bank` on the day it is captured, while the money actually
 * lands T+1/T+2 (longer for Fawry). The bank line and the book line therefore carry different
 * dates, and `billing:reconcile` and the bank-reconciliation module see a gross unmatched
 * population every month. The fix is a clearing account per rail — but the account codes are the
 * accountant's, and the real Egyptian chart has not been supplied, so the mechanism ships and the
 * policy stays a row.
 *
 * ## Direction
 *
 * One catalogue serves SEVEN columns. `for_inbound` covers `payments.method`,
 * `deposit_transactions.method` and `employee_advance_repayments.method` (the employee is paying the
 * operator BACK, so that one debits cash/bank); `for_outbound` covers `vendor_bill_payments.method`,
 * `expenses.paid_from`, `employee_advances.paid_from` (granting an advance PAYS the employee) and
 * `Disbursement`. Cash and bank transfer are both; a collection network is
 * inbound only. Without this, unifying the registries would offer nonsense on one side.
 */
#[DeletableWhenUnused(
    blockedBy: ['payments', 'vendorBillPayments', 'depositTransactions', 'expenses', 'disbursements', 'employeeAdvanceRepayments', 'employeeAdvances'],
    instead: 'Deactivate it. A rail that carried money stays in the catalogue, because every document that names it reads its label — deleting the row would leave those documents naming a code nothing can explain.',
)]
// Shared, not property-owned: a payment rail is operator-level infrastructure. Eltizam banks the
// same way at every mall it runs, and a per-property catalogue would mean re-stating InstaPay once
// per property.
#[PortfolioShared]
class PaymentMethod extends Model
{
    use IsCodeCatalogue;
    use LogsActivity;
    use RefusesDeletionWhenReferenced;

    /**
     * What a rail books to when the catalogue says nothing.
     *
     * Verbatim the ternary the four journalizers carried (`$method === 'cash' ? 'cash' : 'bank'`),
     * so an unseeded database and a database whose rails have no role behave identically to the
     * code this replaced. Change a rail by giving its ROW a role, never by editing this.
     */
    public const FLOOR_CASH_ROLE = 'cash';

    public const FLOOR_ROLE = 'bank';

    protected $fillable = [
        'code',
        'name_en',
        'name_ar',
        'ledger_account_id',
        'for_inbound',
        'for_outbound',
        'requires_bank_account',
        'settlement_days',
        'is_active',
        'sort_order',
        'notes',
    ];

    protected $casts = [
        'for_inbound' => 'boolean',
        'for_outbound' => 'boolean',
        'requires_bank_account' => 'boolean',
        'is_active' => 'boolean',
        'settlement_days' => 'integer',
        'sort_order' => 'integer',
    ];

    protected $attributes = [
        'for_inbound' => true,
        'for_outbound' => true,
        // TRUE for a rail somebody is adding, while the COLUMN defaults to false. The two are
        // different questions: the column default protects an insert written by code that predates
        // this feature, and this one answers "what is an operator most likely registering?" — a new
        // way to be paid is a bank-borne one far more often than a second till.
        'requires_bank_account' => true,
        'is_active' => true,
        'settlement_days' => 0,
        'sort_order' => 0,
    ];

    /**
     * Blanking a numeric field in Filament submits NULL, and this column is NOT NULL with a default
     * — a default only applies when the column is OMITTED, never when null is written to it.
     * Coerced at the model, which is where CLAUDE.md puts this class of fix. `sort_order` gets the
     * same treatment from {@see IsCodeCatalogue}.
     */
    protected static function booted(): void
    {
        static::saving(fn (self $rail) => $rail->settlement_days ??= 0);
    }

    protected static function catalogueMemoKey(): string
    {
        return 'payment_method';
    }

    protected static function catalogueFallbackGroup(): string
    {
        return 'admin.enums.method';
    }

    /**
     * Codes are memoised per DIRECTION, and the account map is a third memo.
     *
     * All three are dropped on write. That matters more here than in a catalogue read once per
     * page: a queue worker is one long-lived process, so an operator activating Fawry at 10:00
     * would otherwise be invisible to `queue:work` until it restarted — the picker would offer it,
     * the web request would accept it, and the worker posting the entry would still be answering
     * from a map built before the row existed.
     *
     * @return array<int, string>
     */
    protected static function catalogueMemoSuffixes(): array
    {
        return ['roles', 'codes.inbound', 'codes.outbound', 'needs_bank'];
    }

    /**
     * Does recording money on this rail mean naming WHICH bank account it moved through?
     *
     * The ONE answer to a question that was being answered in two places and neither of them a row:
     * `RecurringExpenseForm` hid its bank-account picker behind a hardcoded `!== 'cash'`, and the
     * other six money forms never asked at all. A literal is wrong the moment the operator activates
     * Fawry — a collection network is not cash, and its money is not in the bank the same day
     * either — which is exactly the class of change {@see PaymentMethod} exists to make a tick.
     *
     * **A rail with no ROW takes the FLOOR, verbatim: `code !== 'cash'`.** That is not a guess, it
     * is the same ternary {@see accountIdOrFloor()} applies one method down — if the posting engine
     * is going to book this money to the `bank` role, the form has every business asking WHICH bank.
     * It is also load-bearing rather than theoretical: `payrolls.paid_from`, `expenses.paid_from`,
     * `deposit_transactions.method` and three more columns accept the legacy literal **`bank`**,
     * which is a value set member and NOT a catalogue code — no `payment_methods` row has ever had
     * `code = 'bank'`. Reading "no row" as "no requirement" would have exempted the single most
     * obviously bank-borne value in the system, on the four screens most likely to use it.
     *
     * `null` is still false: on the one form where the rail is optional the placeholder says the
     * blank means cash, so an unanswered rail must not demand a bank account.
     *
     * Memoised for the request and dropped on write like the other three maps — the reason is in
     * {@see catalogueMemoSuffixes()}: `queue:work` is one long-lived process, so a rail changed at
     * 10:00 would otherwise stay invisible to it until it restarted.
     */
    public static function requiresBankAccount(?string $code): bool
    {
        if ($code === null) {
            return false;
        }

        $memo = self::catalogueMemoKey().'.needs_bank';

        $map = app()->has($memo)
            ? app($memo)
            : tap(static::query()->pluck('requires_bank_account', 'code')->all(),
                fn (array $m) => app()->instance($memo, $m));

        // `array_key_exists`, not `??`: a row that says FALSE must beat the floor, and `?? ` cannot
        // tell "the operator said no" from "there is no row" — both are falsy. Getting this wrong
        // would make `requires_bank_account = false` unsettable for every rail except cash.
        return array_key_exists($code, $map)
            ? (bool) $map[$code]
            : $code !== self::FLOOR_CASH_ROLE;
    }

    public function scopeInbound(Builder $query): Builder
    {
        return $query->where('for_inbound', true);
    }

    public function scopeOutbound(Builder $query): Builder
    {
        return $query->where('for_outbound', true);
    }

    /**
     * The chart account this rail's money lands in, or null to take the floor.
     *
     * Memoized per request for the reason {@see ChargeCode::roleFor()} is: a payment run asks once
     * per document, and this is a table of a dozen rows. Flushed on write — see {@see booted()}.
     */
    public static function accountIdFor(?string $code): ?int
    {
        if ($code === null) {
            return null;
        }

        $memo = self::catalogueMemoKey().'.roles';

        $map = app()->has($memo)
            ? app($memo)
            : tap(static::query()->pluck('ledger_account_id', 'code')->all(),
                fn (array $m) => app()->instance($memo, $m));

        return $map[$code] ?? null;
    }

    /**
     * The account a document paid on this rail debits (money in) or credits (money out).
     *
     * The ONE place the floor is applied, so the journalizers cannot drift from each other the way
     * the four registries did. Returns a `LedgerAccount`, not a role, because a rail names its
     * account directly — see the migration for why a `PostingRoles` key was the wrong shape.
     */
    public static function accountIdOrFloor(?string $code, ?int $assetId, AccountResolver $accounts): int
    {
        $id = static::accountIdFor($code);

        if ($id !== null) {
            $account = LedgerAccount::find($id);

            // POSTABLE and ACTIVE, not merely present — the same two flags `MoneyAccount`'s bank
            // tier and `ExpenseCategory`'s category tier already re-check, and this tier did not.
            // Deactivating a chart account IS the documented way to retire one, and a rail pointing
            // at a retired or summary account was handed straight to the posting engine, which
            // refuses it: every document on that rail then committed with its entry stranded in the
            // log-only sync job. The fall-through exists precisely to prevent that.
            if ($account !== null && $account->is_postable && $account->is_active) {
                return $account->id;
            }
            // The row pointed at an account that has since gone, been retired, or been made a
            // summary parent. Falling through to the floor is the safe answer: the entry still posts
            // and still balances, where throwing would kill the sync job and leave the document
            // unposted with nothing on screen to say so.
        }

        return $accounts->id(
            $code === 'cash' ? self::FLOOR_CASH_ROLE : self::FLOOR_ROLE,
            $assetId,
        );
    }

    /**
     * The two entry points {@see ValueSets} widens from.
     *
     * Separate methods so the value-set declaration reads as a pair of named facts rather than a
     * string argument, and so each direction gets its own memo.
     *
     * @return array<int, string>
     */
    public static function inboundCodes(): array
    {
        return static::cachedCodes('codes.inbound', fn (Builder $q) => $q->where('for_inbound', true));
    }

    /** @return array<int, string> */
    public static function outboundCodes(): array
    {
        return static::cachedCodes('codes.outbound', fn (Builder $q) => $q->where('for_outbound', true));
    }

    /**
     * `code => label` for the picker on ONE column.
     *
     * **The column, not a direction.** The direction and the FLOOR are both derived from it, and
     * that is the whole point: taking a direction and then flooring from a hard-coded table meant a
     * picker offered the floor of a DIFFERENT column. `expenses.paid_from` accepts `cash|bank` and
     * its form offered the five `vendor_bill_payments` rails on any database without the catalogue
     * seeded — a value the saving listener refuses, which is the 2026-08-18 deposit bug in its
     * original form. `OfferedValuesAreAcceptedValuesConformanceTest` could not see it: that gate
     * compares `allowed()` with `forTable()`, and both were right about the column; it was the
     * PICKER that was reading somebody else's set.
     *
     * `$fallbackGroup` stays per call site, because one catalogue serves seven columns that each had
     * their own lang group — a vendor-bill payment reads `admin.enums.vendor_bill_payment_method`,
     * an expense reads `admin.enums.expense_paid_from`. Only reached on an unseeded database.
     *
     * @param  string  $column  `table.column`, e.g. `expenses.paid_from`
     * @return array<string, string>
     */
    public static function optionsFor(string $column, ?string $fallbackGroup = null): array
    {
        [$table, $name] = explode('.', $column, 2);

        // Which direction this column is, taken from the registry that already says so rather than
        // from a second list. `PaymentMethodPickersMatchTheirColumnTest` fails on an unregistered one.
        $reader = ValueSets::catalogueWidenedColumns()[$column][1] ?? 'outboundCodes';
        $outbound = $reader === 'outboundCodes';

        return static::catalogueOptions(
            scope: fn (Builder $q) => $q->where($outbound ? 'for_outbound' : 'for_inbound', true),
            floor: ValueSets::allowed($table, $name) ?? [],
            fallbackGroup: $fallbackGroup,
        );
    }

    /**
     * The rail a NEW document on this column opens on — or nothing, once the operator retires it.
     *
     * Seven money forms stated their default as a LITERAL beside options that come from a catalogue
     * the operator edits, so the option list moved with the catalogue and the default did not.
     * Retire the rail a form defaults to and Filament — which derives a Select's `Rule::in` from the
     * options it resolved, and cannot label a value it was not offered — renders the field EMPTY
     * while its state still carries the retired code. The operator then submits a form they never
     * touched that field on and is refused as *invalid*: the 2026-08-18 deposit bug through a door
     * the operator opens themselves.
     *
     * Measured at HEAD 2026-09-04 against the dev database. With `bank_transfer` deactivated,
     * `optionsFor('disbursements.method')` answers `[cash, cheque, card, instapay, other]`, and the
     * schedule-payout modal's `->default(Disbursement::METHOD_BANK_TRANSFER)` is not among them.
     *
     * **Null, never a substitute rail.** Falling back to whatever the catalogue happens to offer
     * first would choose a channel for money nobody chose, and the rail decides which chart account
     * the entry lands in ({@see accountIdOrFloor()}). A blank required field asks the question,
     * which is the honest answer once the rail this form assumed has been retired.
     *
     * Derived from {@see optionsFor()} — the SAME list the picker renders — so the two cannot drift.
     * The label group is irrelevant here: it changes labels, never keys.
     *
     * @param  string  $column  `table.column`, e.g. `disbursements.method`
     */
    public static function defaultFor(string $column, ?string $preferred): ?string
    {
        if ($preferred === null || $preferred === '') {
            return null;
        }

        return array_key_exists($preferred, static::optionsFor($column)) ? $preferred : null;
    }

    /**
     * `code => label` for a FILTER on one column — retired rails included.
     *
     * A form asks what may be recorded; a filter asks what already WAS. Pointing a filter at
     * `optionsFor()` meant retiring a rail hid every payment ever taken on it from the list it is on.
     *
     * @return array<string, string>
     */
    public static function filterOptionsFor(string $column, ?string $fallbackGroup = null): array
    {
        [$table, $name] = explode('.', $column, 2);
        $reader = ValueSets::catalogueWidenedColumns()[$column][1] ?? 'outboundCodes';
        $direction = $reader === 'outboundCodes' ? 'for_outbound' : 'for_inbound';

        return static::catalogueFilterOptions(
            scope: fn (Builder $q) => $q->where($direction, true),
            floor: ValueSets::allowed($table, $name) ?? [],
            fallbackGroup: $fallbackGroup,
        );
    }

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'method', 'code');
    }

    public function vendorBillPayments(): HasMany
    {
        return $this->hasMany(VendorBillPayment::class, 'method', 'code');
    }

    public function depositTransactions(): HasMany
    {
        return $this->hasMany(DepositTransaction::class, 'method', 'code');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'paid_from', 'code');
    }

    public function disbursements(): HasMany
    {
        return $this->hasMany(Disbursement::class, 'method', 'code');
    }

    public function employeeAdvanceRepayments(): HasMany
    {
        return $this->hasMany(EmployeeAdvanceRepayment::class, 'method', 'code');
    }

    public function employeeAdvances(): HasMany
    {
        return $this->hasMany(EmployeeAdvance::class, 'paid_from', 'code');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'payment_method');
    }
}
