<?php

namespace App\Models;

use App\Enums\InvoiceItemType;
use App\Support\Attributes\DeletionAllowed;
use App\Support\PostingRoles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * كود رسوم — one billable charge code and the GL account it posts to.
 *
 * The catalogue an accountant maintains (gap-analysis row 216). Adding "key money" or a "chiller
 * charge" used to mean editing a PHP enum and a private const map inside the journalizer; it is now
 * a row.
 *
 * **The catalogue is data; behaviour stays in code.** A few codes carry real logic —
 * `cam_recovery` / `percentage_rent` are excluded from the monthly anti-double-bill probe,
 * `late_fee` / `nsf_fee` settle last — and that logic is keyed on the {@see InvoiceItemType}
 * constants, which survive as named references to exactly those codes. A conformance test asserts
 * every enum case has a row here, so an operator cannot delete a code the engine has opinions about
 * and the two lists cannot drift.
 */
#[DeletionAllowed(reason: 'configuration: the billing vocabulary. A code the engine references by name is refused at the screen; an operator-added one that was never billed is ordinary cleanup')]
class ChargeCode extends Model
{
    use LogsActivity;

    /**
     * Memo keys, held in the CONTAINER rather than in static properties.
     *
     * The journalizer asks for a role once per invoice line and origination asks for a treatment
     * once per charge, so a catalogue of twelve rows would otherwise be re-read a hundred times on
     * a reconciliation invoice. A static would memoise just as well and outlive the thing it
     * describes: the container is rebuilt per request AND per test, so a test that rules parking
     * taxable cannot leak that answer into the next one after its transaction has rolled back.
     */
    private const ROLE_MEMO = 'atriom.charge_codes.roles';

    private const VAT_MEMO = 'atriom.charge_codes.vat';

    protected $fillable = [
        'code',
        'name_en',
        'name_ar',
        'posting_role',
        'tax_code',
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
            // Taxability is logged for the same reason the posting role is: it is an accountant's
            // ruling, and "when did parking become taxable?" is a question an auditor asks.
            ->logOnly(['code', 'name_en', 'name_ar', 'posting_role', 'tax_code', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('charge_code');
    }

    protected static function booted(): void
    {
        // Any write invalidates the memo — otherwise re-pointing a code mid-request would keep
        // posting to the old account, or taxing at the old treatment, for the rest of it.
        static::saved(fn () => static::flushLookupCaches());
        static::deleted(fn () => static::flushLookupCaches());
    }

    /**
     * Drop the per-request memos.
     *
     * Called from the model events above, and needed on its own by anything that writes this table
     * WITHOUT going through Eloquent — a mass `DB::table('charge_codes')->…` update fires no model
     * event, so the memo would answer from a catalogue that no longer exists.
     */
    public static function flushLookupCaches(): void
    {
        app()->forgetInstance(self::ROLE_MEMO);
        app()->forgetInstance(self::VAT_MEMO);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function label(?string $locale = null): string
    {
        return ($locale ?? app()->getLocale()) === 'ar' ? $this->name_ar : $this->name_en;
    }

    /**
     * The posting role a charge code books to, or null to take the misc_income fallback.
     *
     * Memoized per request because the journalizer asks once per invoice line and a hundred-line
     * reconciliation invoice would otherwise be a hundred queries for a table of twelve rows.
     */
    public static function roleFor(string $code): ?string
    {
        $roles = app()->has(self::ROLE_MEMO)
            ? app(self::ROLE_MEMO)
            : tap(static::query()->pluck('posting_role', 'code')->all(),
                fn (array $map) => app()->instance(self::ROLE_MEMO, $map));

        return $roles[$code] ?? null;
    }

    /**
     * Does the catalogue contain this code?
     *
     * Reads the same memo {@see roleFor()} fills, so the model-layer type guard on every charge
     * costs no extra query. Includes INACTIVE codes deliberately: switching a code off stops it
     * being offered, it does not make the rows that already use it invalid.
     */
    public static function knows(string $code): bool
    {
        $roles = app()->has(self::ROLE_MEMO)
            ? app(self::ROLE_MEMO)
            : tap(static::query()->pluck('posting_role', 'code')->all(),
                fn (array $map) => app()->instance(self::ROLE_MEMO, $map));

        return array_key_exists($code, $roles);
    }

    /**
     * The tax code this charge is billed under, or null when the catalogue has no row for it.
     *
     * Null is the honest answer, not a default: `App\Support\Vat` decides what an un-catalogued code
     * bills, and it needs to know the difference between "the accountant ruled on this" and "this
     * database has no catalogue yet". Same shape, and same reason, as {@see roleFor()}.
     *
     * A charge code with a row but a NULL `tax_code` is a third state and also returns null — an
     * operator added a code and has not classified it yet, which the floor handles identically.
     */
    public static function taxCodeFor(string $code): ?string
    {
        $map = app()->has(self::VAT_MEMO)
            ? app(self::VAT_MEMO)
            : tap(static::query()
                ->get(['code', 'tax_code'])
                ->mapWithKeys(fn (self $c) => [$c->code => $c->tax_code])
                ->all(),
                fn (array $m) => app()->instance(self::VAT_MEMO, $m));

        return $map[$code] ?? null;
    }

    /** Value => label map for the invoice-line picker. Active codes only. */
    public static function options(?string $locale = null): array
    {
        return static::query()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get()
            ->mapWithKeys(fn (self $c) => [$c->code => $c->label($locale)])
            ->all();
    }

    /** The statement class this code's role belongs to — shown beside the role on the screen. */
    public function roleGroup(): ?string
    {
        return $this->posting_role ? PostingRoles::group($this->posting_role) : null;
    }
}
