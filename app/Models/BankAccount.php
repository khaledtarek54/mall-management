<?php

namespace App\Models;

use App\Models\Concerns\HasSearchText;
use App\Support\ActivityLogging;
use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PropertyOwned;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A bank account the operator actually holds — slice 1 of bank reconciliation.
 *
 * `bank`/`cash` are posting ROLES; this is the account itself. Nothing posts through it yet: the
 * ledger still resolves the role exactly as before, so this register changes no balance and no
 * entry. It exists because a reconciliation is always OF one account, and the roles cannot name one
 * once a property banks in two places.
 *
 * @see docs/accounting/BANK-RECONCILIATION-PLAN.md
 */
#[DeletionAllowed(reason: 'configuration: the operator\'s bank accounts (revisit when statements exist)')]
// owns its asset_id: the mall whose money it holds
#[PropertyOwned]
class BankAccount extends Model
{
    use HasFactory, HasSearchText, LogsActivity, SoftDeletes;

    protected $fillable = [
        'asset_id',
        'name',
        'bank_name',
        'account_number',
        'iban',
        'currency',
        'purpose',
        'is_default',
        'ledger_account_id',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    protected $attributes = [
        'purpose' => self::PURPOSE_OPERATING,
        'is_default' => false,
    ];

    /**
     * What kind of money this account holds — Yardi's own split of a property's cash accounts.
     *
     * Not decoration: it is what lets a document DEFAULT to the right account without asking. A
     * deposit receipt and a rent receipt are both "money in" on the same rail, and they belong in
     * different accounts.
     *
     * `deposits` is the row that earns the column. A tenant's security deposit is money the operator
     * HOLDS — `deposits_held` is a liability — and mixing it with working cash is the thing every
     * jurisdiction that regulates this regulates. Egypt does not mandate a trust account the way US
     * states do, so this is a facility the operator may use rather than a rule imposed on them:
     * leave every account `operating` and the ladder in {@see defaultFor()} falls back to operating
     * for deposits too, which is what actually happens today.
     *
     * `payroll` is here because an Egyptian bank issuing a salary transfer file wants its own
     * account, so a mall that runs payroll usually has one.
     *
     * There is deliberately no `cash`/`till` purpose. A petty-cash box is not a bank account, it is
     * the `cash` posting role, and registering one here would put a row in the reconciliation
     * register that no statement will ever arrive for.
     */
    public const PURPOSE_OPERATING = 'operating';

    public const PURPOSE_DEPOSITS = 'deposits';

    public const PURPOSE_PAYROLL = 'payroll';

    /** @var array<int, string> */
    public const PURPOSES = [self::PURPOSE_OPERATING, self::PURPOSE_DEPOSITS, self::PURPOSE_PAYROLL];

    /**
     * One default per (property, purpose) — kept on WRITE, because no index can express it.
     *
     * MySQL has no partial unique index, so `unique(asset_id, purpose)` filtered to `is_default`
     * is not available; a plain unique would forbid a second operating account outright, which is
     * the exact situation EG-12 exists for. Demoting the previous holder is the honest mechanism.
     *
     * A QUERY-BUILDER update, not a model one: `$other->update()` re-enters this hook, and on a
     * property with several accounts that recursion is the whole write. It also deliberately does
     * NOT fire model events — nothing else listens for a bank account being demoted, and firing
     * them would write an activity-log row per sibling for one operator decision.
     *
     * Scoped to the same PURPOSE, so flagging the deposits account does not un-flag the operating
     * one — they answer different questions and both are the default for theirs.
     */
    protected static function booted(): void
    {
        static::saving(function (self $account): void {
            self::assertLedgerAccountIsItsOwn($account);
        });

        static::saved(function (self $account): void {
            if (! $account->is_default) {
                return;
            }

            static::query()
                ->withTrashed()
                ->where('asset_id', $account->asset_id)
                ->where('purpose', $account->purpose)
                ->whereKeyNot($account->getKey())
                ->where('is_default', true)
                ->update(['is_default' => false]);
        });
    }

    /**
     * A bank account gets its OWN chart account — not a shared one, and never a posting role.
     *
     * ## This is the market standard, not a house preference
     *
     * Every system that reconciles a bank does it this way. Yardi's Bank record points at one cash
     * GL account and its reconciliation is OF that account. NetSuite, QuickBooks and Odoo all make
     * each bank account its own GL account — Odoo goes further and CREATES the account for you when
     * you add the bank, which is why {@see App\Filament\Admin\Resources\BankAccounts\Schemas\BankAccountForm}
     * now offers the same thing. SAP maps each house bank account to its own reconciliation account.
     *
     * The mechanical reason is `MatchBankStatementLineService::candidatesFor()`, which finds
     * candidates with `where('ledger_account_id', $account->ledger_account_id)`. Two banks on one
     * chart account means reconciling CIB OFFERS NBE's postings. The operator matches one, the
     * statement balances, and the reconciliation is wrong — which
     * `docs/accounting/BANK-RECONCILIATION-PLAN.md` calls worse than not reconciling at all, because
     * a wrong match marks money verified.
     *
     * ## Why a POSTING ROLE account is refused too, and it is the subtler half
     *
     * The `bank` role is where a document naming NO bank account lands — that is the whole floor in
     * {@see App\Support\MoneyAccount}. Point a real bank at it and the two populations merge:
     * "money we know went through CIB" and "money nobody said anything about" become one balance, so
     * every unattributed posting is offered as a CIB candidate. `DemoSeeder` has been careful about
     * exactly this since the day the register was seeded — *"neither may BE a posting-role account,
     * and that is the whole point"* — and the FORM was not, which is how an install ends up with its
     * only bank pointing at `11102001 Bank Account`.
     *
     * Any role, not just `cash`/`bank`: a bank pointing at the AR control account or at
     * `deposits_held` is a worse version of the same mistake.
     *
     * ## Only on a DIRTY write
     *
     * Every install that predates this has a bank account mapped somewhere, quite possibly at the
     * role account. Refusing on every save would make those rows uneditable — the operator could not
     * even rename the bank without first solving a chart problem — which is the trap CLAUDE.md
     * records for `#[NeverDeletable]`: guarding a row a workflow legitimately touches breaks the
     * workflow instead of protecting it. `ConfigurationHealth::bankAccountsHaveTheirOwnAccount()`
     * reports the existing ones as the advisory they are, and the form's *create a dedicated
     * account* button makes fixing one a click.
     */
    private static function assertLedgerAccountIsItsOwn(self $account): void
    {
        if ($account->ledger_account_id === null || ! $account->isDirty('ledger_account_id')) {
            return;
        }

        if (AccountMapping::query()->where('ledger_account_id', $account->ledger_account_id)->exists()) {
            throw new DomainException(__('admin.refusals.bank_account_is_a_posting_role', [
                'account' => LedgerAccount::withTrashed()->find($account->ledger_account_id)?->code ?? '',
            ]));
        }

        $taken = static::query()
            ->withTrashed()
            ->where('ledger_account_id', $account->ledger_account_id)
            ->whereKeyNot($account->getKey())
            ->first();

        if ($taken !== null) {
            throw new DomainException(__('admin.refusals.bank_account_shares_a_chart_account', [
                'other' => $taken->name,
            ]));
        }
    }

    /**
     * Which account a NEW document on this property should name — the whole reason a requirement is
     * tolerable rather than a chore.
     *
     * Four rungs, each a real statement rather than a guess:
     *
     *   1. **The account flagged default for this purpose.** The operator's own answer.
     *   2. **The default OPERATING account.** A property with no dedicated deposits or payroll
     *      account banks that money with everything else, which is the ordinary Egyptian case and
     *      the state every install starts in. Falling here is not a compromise — it is true.
     *   3. **The only active account there is.** One account is not a choice, and making an
     *      operator flag a default on a register holding exactly one row is asking them to state
     *      something the data already says. Strictly one: with two, guessing is how money lands in
     *      the wrong bank.
     *   4. **Nothing.** The document names no account and `App\Support\MoneyAccount` falls to the
     *      rail and then the posting role — verbatim today's behaviour.
     *
     * `active` throughout and never `withTrashed()`: this picks an account for money that has not
     * moved yet, which is the opposite of {@see App\Support\MoneyAccount::ledgerAccountOf()} reading
     * a retired account for money that already did.
     */
    public static function defaultFor(?int $assetId, string $purpose = self::PURPOSE_OPERATING): ?self
    {
        if ($assetId === null) {
            return null;
        }

        $onProperty = static::query()->active()->where('asset_id', $assetId);

        $flagged = (clone $onProperty)
            ->where('is_default', true)
            ->whereIn('purpose', array_unique([$purpose, self::PURPOSE_OPERATING]))
            // The purpose asked for wins over the operating fallback whichever order the rows are
            // in. `orderByRaw` rather than a second query: two round trips to express one preference
            // is what the composite index was added to avoid.
            ->orderByRaw('case when purpose = ? then 0 else 1 end', [$purpose])
            ->first();

        if ($flagged !== null) {
            return $flagged;
        }

        $only = (clone $onProperty)->limit(2)->get();

        return $only->count() === 1 ? $only->first() : null;
    }

    public function scopePurpose(Builder $query, string $purpose): Builder
    {
        return $query->where('purpose', $purpose);
    }

    /**
     * Found by what an operator would type: the account's own name, its bank, and the number a
     * statement quotes. Own attributes only — never reached through a relation (the blob is a pure
     * function of this row, or renaming a ledger account would strand every blob quoting it).
     *
     * @return array<int, string|int|float|null>
     */
    public function searchTextSources(): array
    {
        return [
            $this->name,
            $this->bank_name,
            $this->account_number,
            $this->iban,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'bank_account');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** The GL account this bank is, when the accountant has said which. */
    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** "CIB — current ···4821", which is how an operator recognises it without exposing the number. */
    public function displayName(): string
    {
        $masked = $this->maskedNumber();

        return trim($this->name.($masked ? ' '.$masked : ''));
    }

    /** Last four only. The whole number is stored (a statement quotes it) but rarely worth showing. */
    public function maskedNumber(): ?string
    {
        $number = preg_replace('/\s+/', '', (string) $this->account_number);

        return $number === '' || $number === null ? null : '···'.mb_substr($number, -4);
    }
}
