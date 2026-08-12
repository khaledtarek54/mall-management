<?php

namespace App\Models;

use App\Models\Concerns\RefusesDeletionWhenReferenced;
use App\Support\PostingRoles;
use App\Support\Vat;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * كود ضريبي — one tax this system may apply, and the dated ladder of rates it has carried.
 *
 * The catalogue an accountant maintains. Before it, the standard VAT rate was a single settings
 * field with no date attached and withholding was a second one; see the migration for why neither
 * shape can express what a tax actually is.
 *
 * **The rate is resolved for a DATE, never "now".** {@see rateOn()} takes the document's own date,
 * so an invoice back-dated into a previous rate regime bills that regime's rate — and a rate the
 * accountant enters in advance starts applying by itself on the day it takes effect, with nobody
 * needing to remember.
 *
 * **Origination only, exactly like the thing it replaced.** Once a line exists it carries its own
 * `vat_rate` column and every downstream path reads that stored figure. Changing a rate here
 * affects what is billed NEXT and never rewrites an issued document — otherwise a rate change would
 * de-tie the books from the filed returns. {@see Vat} is the one resolver every
 * origination point calls.
 */
class TaxCode extends Model
{
    use LogsActivity;
    use RefusesDeletionWhenReferenced;

    /** Output tax — charged to a tenant on an invoice. */
    public const SALES = 'sales';

    /** Input tax — charged to us by a supplier; recoverable on the return. */
    public const PURCHASES = 'purchases';

    /** @var array<int, string> */
    public const DIRECTIONS = [self::SALES, self::PURCHASES];

    /** ضريبة القيمة المضافة — VAT Law 67/2016. */
    public const FAMILY_VAT = 'vat';

    /** ضريبة الدمغة — stamp duty. */
    public const FAMILY_STAMP = 'stamp';

    /** ضريبة الجدول — the VAT law's Schedule taxes on listed supplies. */
    public const FAMILY_SCHEDULE = 'schedule';

    /**
     * خصم وتحصيل تحت حساب الضريبة — withheld from a supplier payment and remitted for them.
     * Its rates are stored NEGATIVE: the tax reduces what is paid rather than adding to it.
     */
    public const FAMILY_WITHHOLDING = 'withholding';

    /** @var array<int, string> */
    public const FAMILIES = [self::FAMILY_VAT, self::FAMILY_STAMP, self::FAMILY_SCHEDULE, self::FAMILY_WITHHOLDING];

    /** A taxable supply — bills the rate in force on the document's date. */
    public const STANDARD = 'standard';

    /** Outside the scope of VAT — base rent, penalties, the marketing levy. Bills 0. */
    public const EXEMPT = 'exempt';

    /**
     * A taxable supply rated at 0%. Bills the same 0 as exempt and reports differently, which is
     * the only reason it is a separate value: the distinction cannot be recovered later from
     * documents that recorded nothing but a zero.
     */
    public const ZERO_RATED = 'zero_rated';

    /** @var array<int, string> */
    public const TREATMENTS = [self::STANDARD, self::EXEMPT, self::ZERO_RATED];

    /**
     * The whole catalogue, memoized in the CONTAINER for the request.
     *
     * Origination asks for a rate once per charge and a hundred-line reconciliation invoice would
     * otherwise be a hundred queries against a table of a dozen rows. Held in the container rather
     * than a static for the reason `ChargeCode` documents: the container is rebuilt per request AND
     * per test, so a test that moves the VAT rate cannot leak that answer into the next one after
     * its transaction has rolled back.
     */
    private const MEMO = 'atriom.tax_codes.catalogue';

    protected $fillable = [
        'code',
        'name_en',
        'name_ar',
        'family',
        'direction',
        'treatment',
        'posting_role',
        'invoice_label',
        'statutory_reference',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'name_en', 'name_ar', 'family', 'direction', 'treatment', 'posting_role', 'invoice_label', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('tax_code');
    }

    protected static function booted(): void
    {
        static::saving(function (self $code) {
            $code->assertCanBeActivated();
        });

        static::saved(fn () => static::flushLookupCaches());
        static::deleted(fn () => static::flushLookupCaches());
    }

    /**
     * A taxable code may only be switched on once it can actually bill.
     *
     * An ACTIVE code with an empty rate ladder appears in every picker and resolves to no rate at
     * all; an active one with no posting role collects money into no account. Both are the state
     * the catalogue ships in deliberately — most codes seed rate-less, because their statutory
     * figures are the accountant's to enter, not the software's to guess — so the guard is what
     * makes that safe rather than a trap. Activation is the accountant saying "I have entered the
     * rate", and this refuses the claim when it isn't true.
     *
     * **Checked on update, not on create.** A brand-new code cannot have rungs yet — they are rows
     * that need its id — so the honest flow is create · add the rate · activate, which is what the
     * form's `is_active` default of false leads to and what `TaxCodeSeeder` does. Nothing references
     * a code that does not exist yet, so there is nothing to protect at that moment.
     * `TaxCatalogueConformanceTest` covers the seeded catalogue from the other side.
     */
    public function assertCanBeActivated(): void
    {
        if (! $this->exists || ! $this->is_active || $this->treatment !== self::STANDARD) {
            return;
        }

        // Only when activation is what is CHANGING. Re-saving a label on a code that is already
        // active and already complete must not re-litigate it, and a code that somehow got into a
        // bad state must stay editable so it can be got out of one.
        if (! $this->isDirty('is_active') && ! $this->isDirty('treatment')) {
            return;
        }

        if ($this->rates()->doesntExist()) {
            throw new \DomainException(__('admin.validation.tax_code_needs_rate', ['code' => $this->code]));
        }

        if (blank($this->posting_role)) {
            throw new \DomainException(__('admin.validation.tax_code_needs_role', ['code' => $this->code]));
        }
    }

    /**
     * Drop the per-request memo.
     *
     * Called from the model events above, and needed on its own by anything that writes either
     * table WITHOUT going through Eloquent — a `DB::table('tax_rates')->…` write fires no model
     * event, so the memo would keep answering from a ladder that no longer exists.
     */
    public static function flushLookupCaches(): void
    {
        app()->forgetInstance(self::MEMO);
    }

    /** @return HasMany<TaxRate, $this> */
    public function rates(): HasMany
    {
        return $this->hasMany(TaxRate::class);
    }

    /** @return HasMany<ChargeCode, $this> */
    public function chargeCodes(): HasMany
    {
        return $this->hasMany(ChargeCode::class, 'tax_code', 'code');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfDirection(Builder $query, string $direction): Builder
    {
        return $query->where('direction', $direction);
    }

    public function scopeOfFamily(Builder $query, string $family): Builder
    {
        return $query->where('family', $family);
    }

    public function label(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'ar' ? $this->name_ar : $this->name_en;
    }

    /**
     * The rate this code applies to a document dated `$on`, or null when the catalogue has no such
     * code.
     *
     * Null is the honest answer and not a default: {@see Vat} decides what an
     * un-catalogued code bills, and it needs to tell "the accountant ruled on this" apart from
     * "this database has no catalogue yet". Same shape, and same reason, as
     * `ChargeCode::vatPolicyFor()`.
     *
     * A non-standard treatment resolves to 0 whatever the ladder says, so a rate typed against an
     * exempt code reads as policy and does nothing.
     */
    public static function rateOn(string $code, CarbonInterface|string|null $on = null): ?float
    {
        $entry = self::catalogue()[$code] ?? null;

        if ($entry === null) {
            return null;
        }

        if ($entry['treatment'] !== self::STANDARD) {
            return 0.0;
        }

        return self::rateFromLadder($entry['rates'], $on);
    }

    /** How this code is treated, or null when the catalogue has no row for it. */
    public static function treatmentOf(string $code): ?string
    {
        return self::catalogue()[$code]['treatment'] ?? null;
    }

    /** Which Egyptian tax this code belongs to, or null when the catalogue has no row for it. */
    public static function familyOf(?string $code): ?string
    {
        return $code === null ? null : (self::catalogue()[$code]['family'] ?? null);
    }

    /** The GL posting role this code's collections land in, if it collects anything. */
    public static function postingRoleOf(string $code): ?string
    {
        return self::catalogue()[$code]['posting_role'] ?? null;
    }

    public static function knows(string $code): bool
    {
        return array_key_exists($code, self::catalogue());
    }

    /**
     * The display name of a code, from the memo rather than a query.
     *
     * A table column resolving this per row would be one query per row for a catalogue of fifteen.
     * Falls back to the code itself for one the catalogue has lost — a charge code pointing at a
     * deleted tax must still render something an operator can act on.
     */
    public static function labelFor(?string $code, ?string $locale = null): ?string
    {
        if ($code === null) {
            return null;
        }

        $entry = self::catalogue()[$code] ?? null;

        if ($entry === null) {
            return $code;
        }

        return ($locale ?? app()->getLocale()) === 'ar' ? $entry['name_ar'] : $entry['name_en'];
    }

    /**
     * The rate in force on `$on`: the latest rung at or before that date.
     *
     * **Before the first rung, the first rung applies.** The alternative — returning null and
     * letting the caller bill 0 — would silently under-collect a tax that is due because someone
     * back-dated a document past the earliest rate anyone recorded. A ladder that starts in 2017
     * says "14% since 2017", not "no VAT existed before 2017", and billing must not stop or
     * silently zero itself over a date the catalogue simply predates.
     *
     * @param  array<int, array{from: string, rate: float}>  $ladder  newest first
     */
    private static function rateFromLadder(array $ladder, CarbonInterface|string|null $on): ?float
    {
        if ($ladder === []) {
            return null;
        }

        $date = $on === null
            ? CarbonImmutable::now()->toDateString()
            : CarbonImmutable::parse($on)->toDateString();

        foreach ($ladder as $rung) {
            if ($rung['from'] <= $date) {
                return $rung['rate'];
            }
        }

        return end($ladder)['rate'];
    }

    /**
     * The catalogue: code => treatment, scope, posting role, and its rate ladder newest-first.
     *
     * One query for the codes and one for the rates, whatever is asked of it afterwards.
     *
     * @return array<string, array{treatment: string, family: string, direction: string, posting_role: ?string, name_en: string, name_ar: string, rates: array<int, array{from: string, rate: float}>}>
     */
    public static function catalogue(): array
    {
        if (app()->has(self::MEMO)) {
            return app(self::MEMO);
        }

        $codes = static::query()->get(['id', 'code', 'family', 'direction', 'treatment', 'posting_role', 'name_en', 'name_ar']);

        $ladders = TaxRate::query()
            ->orderByDesc('effective_from')
            ->get(['tax_code_id', 'rate', 'effective_from'])
            ->groupBy('tax_code_id');

        $catalogue = $codes
            ->mapWithKeys(fn (self $c) => [$c->code => [
                'treatment' => $c->treatment ?: self::STANDARD,
                'family' => $c->family ?: self::FAMILY_VAT,
                'direction' => $c->direction ?: self::SALES,
                'posting_role' => $c->posting_role,
                'name_en' => (string) $c->name_en,
                'name_ar' => (string) $c->name_ar,
                'rates' => $ladders->get($c->id, collect())
                    ->map(fn (TaxRate $r) => [
                        'from' => $r->effective_from->toDateString(),
                        'rate' => (float) $r->rate,
                    ])
                    ->values()
                    ->all(),
            ]])
            ->all();

        app()->instance(self::MEMO, $catalogue);

        return $catalogue;
    }

    /**
     * Value => label map for a picker, restricted to one direction.
     *
     * Keyed by CODE, not id — a document records the code, so the form state and the stored value
     * are the same string and nothing has to translate between them.
     *
     * @return array<string, string>
     */
    public static function options(string $direction = self::SALES, ?string $locale = null): array
    {
        return static::query()
            ->active()
            ->ofDirection($direction)
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (self $c) => [$c->code => $c->label($locale)])
            ->all();
    }

    /** The rate in force today, for display beside the picker. */
    public function currentRate(CarbonInterface|string|null $on = null): ?float
    {
        return self::rateOn($this->code, $on);
    }

    /** The statement class this code's role belongs to — shown beside the role on the screen. */
    public function roleGroup(): ?string
    {
        return $this->posting_role ? PostingRoles::group($this->posting_role) : null;
    }
}
