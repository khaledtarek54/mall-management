<?php

namespace App\Models;

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
    use LogsActivity;
    use RefusesDeletionWhenReferenced;

    /** Memo key — the journalizers ask once per document. */
    private const ROLE_MEMO = 'payment_method.roles';

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
     * Every memo this model fills is dropped the moment a rail changes.
     *
     * `ChargeCode` does the same and this copy had dropped it, which mattered more here than there:
     * a queue worker is one long-lived process, so an operator activating Fawry at 10:00 would have
     * been invisible to `queue:work` until it restarted — the picker would offer it, the web request
     * would accept it, and the worker posting the entry would still be answering from a map built
     * before the row existed.
     *
     * `ValueSets`' own per-process table cache is flushed too, because the enforced set is derived
     * from this catalogue — see {@see ValueSets::forTable()}.
     */
    protected static function booted(): void
    {
        // Blanking a numeric field in Filament submits NULL, and both of these columns are NOT
        // NULL with a default — a default only applies when the column is OMITTED, never when null
        // is written to it. Coerced at the model, which is where CLAUDE.md puts this class of fix.
        static::saving(function (self $rail): void {
            $rail->settlement_days ??= 0;
            $rail->sort_order ??= 0;
        });

        $flush = function (): void {
            foreach ([self::ROLE_MEMO, self::ROLE_MEMO.'.inbound', self::ROLE_MEMO.'.outbound', self::ROLE_MEMO.'.labels'] as $key) {
                app()->forgetInstance($key);
            }

            ValueSets::flushCatalogueCache();
        };

        static::saved($flush);
        static::deleted($flush);
    }

    /** The reader's language, falling back to the other rather than to a blank cell. */
    public function label(): string
    {
        return app()->getLocale() === 'ar'
            ? ($this->name_ar ?: $this->name_en)
            : ($this->name_en ?: $this->name_ar);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
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

        $map = app()->has(self::ROLE_MEMO)
            ? app(self::ROLE_MEMO)
            : tap(static::query()->pluck('ledger_account_id', 'code')->all(),
                fn (array $m) => app()->instance(self::ROLE_MEMO, $m));

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
     * Separate from {@see codesFor()} so the value-set declaration reads as a pair of named facts
     * rather than a string argument, and so the memo below is keyed by direction.
     *
     * @return array<int, string>
     */
    public static function inboundCodes(): array
    {
        return static::cachedCodes('inbound');
    }

    /** @return array<int, string> */
    public static function outboundCodes(): array
    {
        return static::cachedCodes('outbound');
    }

    /**
     * Memoized, and SAFE BEFORE THE TABLE EXISTS.
     *
     * `ValueSets::allowed()` is called from the global `eloquent.saving: *` listener, so this runs
     * on every save of every model — including saves made by migrations and seeders on a database
     * that has not reached this migration yet, and including the migration that CREATES this table.
     * A bare query there is a fatal error during deploy. An empty answer is the correct one: the
     * literal floor in `ValueSets` is then the whole set, which is exactly the behaviour that
     * predates the catalogue.
     *
     * @return array<int, string>
     */
    private static function cachedCodes(string $direction): array
    {
        $memo = self::ROLE_MEMO.'.'.$direction;

        if (app()->has($memo)) {
            return app($memo);
        }

        try {
            $codes = static::codesFor($direction);
        } catch (\Throwable) {
            return [];
        }

        app()->instance($memo, $codes);

        return $codes;
    }

    /**
     * Active rail codes usable in a direction — what {@see ValueSets} unions onto its
     * floor list, and what the pickers offer.
     *
     * INACTIVE rows are excluded: switching a rail off stops it being offered. It does not
     * invalidate the documents that already name it, which is why `ValueSets` keeps its literal
     * floor and only ever WIDENS from here.
     *
     * @return array<int, string>
     */
    public static function codesFor(string $direction): array
    {
        $column = $direction === 'outbound' ? 'for_outbound' : 'for_inbound';

        return static::query()
            ->where('is_active', true)
            ->where($column, true)
            ->orderBy('sort_order')
            ->pluck('code')
            ->all();
    }

    /**
     * The label for ONE stored code, in the reader's language.
     *
     * What a table CELL renders. It must not be `__("admin.enums.method.{$code}")` — an operator-added
     * rail has no lang key, so a Fawry payment would render the raw key on the very screen the
     * filter beside it lists Fawry on. Falls back to the lang catalogue for the shipped codes, and
     * to the code itself for a legacy value with neither.
     */
    public static function labelFor(?string $code, string $fallbackGroup = 'admin.enums.method'): string
    {
        if ($code === null || $code === '') {
            return '—';
        }

        // Inactive rows included deliberately: retiring a rail stops it being OFFERED, it must not
        // blank the label on every document that already names it.
        $labels = app()->has(self::ROLE_MEMO.'.labels')
            ? app(self::ROLE_MEMO.'.labels')
            : tap(static::allLabels(), fn (array $m) => app()->instance(self::ROLE_MEMO.'.labels', $m));

        if (isset($labels[$code])) {
            return $labels[$code];
        }

        $key = "{$fallbackGroup}.{$code}";
        $translated = __($key);

        return $translated === $key ? $code : $translated;
    }

    /** @return array<string, string> */
    private static function allLabels(): array
    {
        try {
            return static::query()->get()->mapWithKeys(fn (self $m) => [$m->code => $m->label()])->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * `code => label` for a picker, in the reader's language.
     *
     * The ONE label source, because a rail added by an operator has no lang key and would otherwise
     * render as `admin.enums.method.fawry`. Falls back to the existing catalogues for a code with no
     * row — an unseeded database, or a legacy value a document still carries — so no screen
     * regresses before the seeder runs.
     *
     * @return array<string, string>
     */
    public static function options(string $direction, string $fallbackGroup = 'admin.enums.method'): array
    {
        $rows = [];

        try {
            $column = $direction === 'outbound' ? 'for_outbound' : 'for_inbound';
            $rows = static::query()
                ->where('is_active', true)
                ->where($column, true)
                ->orderBy('sort_order')
                ->get()
                ->mapWithKeys(fn (self $m) => [$m->code => $m->label()])
                ->all();
        } catch (\Throwable) {
            // Before the table exists — see cachedCodes().
        }

        if ($rows !== []) {
            return $rows;
        }

        $floor = ValueSets::allowed(
            $direction === 'outbound' ? 'vendor_bill_payments' : 'payments',
            $direction === 'outbound' ? 'method' : 'method',
        ) ?? [];

        // Through `labelFor()`, not `__()` directly: a code with no row AND no lang key must render
        // as the code, never as `admin.enums.expense_paid_from.bank_transfer`. The floor lists are
        // wider than their lang groups — `expenses.paid_from` accepts six values and its group names
        // two — so the unguarded version put a raw key on the expense list.
        return collect($floor)
            ->mapWithKeys(fn (string $code) => [$code => static::labelFor($code, $fallbackGroup)])
            ->all();
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
