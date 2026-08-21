<?php

namespace App\Models;

use App\Models\Concerns\IsCodeCatalogue;
use App\Models\Concerns\RefusesDeletionWhenReferenced;
use App\Services\Accounting\AccountResolver;
use App\Support\Attributes\DeletableWhenUnused;
use App\Support\Attributes\PortfolioShared;
use App\Support\ValueSets;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * One catalogue serves four columns. `for_inbound` covers `payments.method` and
 * `deposit_transactions.method`; `for_outbound` covers `vendor_bill_payments.method`,
 * `expenses.paid_from` and `Disbursement`. Cash and bank transfer are both; a collection network is
 * inbound only. Without this, unifying the registries would offer nonsense on one side.
 */
#[DeletableWhenUnused(
    blockedBy: ['payments', 'vendorBillPayments', 'depositTransactions', 'expenses', 'disbursements'],
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
        'settlement_days',
        'is_active',
        'sort_order',
        'notes',
    ];

    protected $casts = [
        'for_inbound' => 'boolean',
        'for_outbound' => 'boolean',
        'is_active' => 'boolean',
        'settlement_days' => 'integer',
        'sort_order' => 'integer',
    ];

    protected $attributes = [
        'for_inbound' => true,
        'for_outbound' => true,
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
        return ['roles', 'codes.inbound', 'codes.outbound'];
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

            if ($account !== null) {
                return $account->id;
            }
            // The row pointed at an account that has since gone. Falling through to the floor is
            // the safe answer: the entry still posts and still balances, where throwing would kill
            // the sync job and leave the document unposted with nothing on screen to say so.
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
     * Active rail codes usable in a direction.
     *
     * INACTIVE rows are excluded: switching a rail off stops it being offered. It does not
     * invalidate the documents that already name it, which is why `ValueSets` keeps its literal
     * floor and only ever WIDENS from here.
     *
     * @return array<int, string>
     */
    public static function codesFor(string $direction): array
    {
        return $direction === 'outbound' ? static::outboundCodes() : static::inboundCodes();
    }

    /**
     * `code => label` for a picker, in the reader's language.
     *
     * `$fallbackGroup` is per CALL SITE rather than per model, because one catalogue now serves five
     * columns that each had their own lang group — a vendor-bill payment reads
     * `admin.enums.vendor_bill_payment_method`, an expense reads `admin.enums.expense_paid_from`.
     * Only reached on an unseeded database; a seeded one answers from the rows.
     *
     * @return array<string, string>
     */
    public static function options(string $direction, ?string $fallbackGroup = null): array
    {
        $outbound = $direction === 'outbound';

        return static::catalogueOptions(
            scope: fn (Builder $q) => $q->where($outbound ? 'for_outbound' : 'for_inbound', true),
            floor: ValueSets::allowed($outbound ? 'vendor_bill_payments' : 'payments', 'method') ?? [],
            fallbackGroup: $fallbackGroup,
        );
    }

    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    public function payments()
    {
        return $this->hasMany(Payment::class, 'method', 'code');
    }

    public function vendorBillPayments()
    {
        return $this->hasMany(VendorBillPayment::class, 'method', 'code');
    }

    public function depositTransactions()
    {
        return $this->hasMany(DepositTransaction::class, 'method', 'code');
    }

    public function expenses()
    {
        return $this->hasMany(Expense::class, 'paid_from', 'code');
    }

    public function disbursements()
    {
        return $this->hasMany(Disbursement::class, 'method', 'code');
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'name_en', 'name_ar', 'ledger_account_id', 'for_inbound', 'for_outbound', 'settlement_days', 'is_active', 'sort_order'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('payment_method');
    }
}
