<?php

namespace App\Models;

use App\Enums\InvoiceItemType;
use App\Support\ActivityLogging;
use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PortfolioShared;
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
// portfolio billing vocabulary; the per-property override lives on the mapping it names
#[PortfolioShared]
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

    /**
     * Labels, memoised PER LOCALE — a PDF service and a queued notification both switch language
     * mid-request, so one map would answer in whichever ran first.
     */
    private const LABEL_MEMO = 'atriom.charge_codes.labels';

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
        return ActivityLogging::for($this, 'charge_code');
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

        foreach (['en', 'ar'] as $locale) {
            app()->forgetInstance(self::LABEL_MEMO.'.'.$locale);
        }
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
     * What to call a charge TYPE on screen, in the reader's language.
     *
     * Three sources in priority order, because the catalogue is open: the shipped types have
     * translated strings, an accountant-added code carries its own bilingual pair, and anything
     * else falls back to a humanised form of the code rather than a blank cell.
     *
     * Lives here, on the model that owns `label()`, because two screens need the same answer — the
     * charge schedule and the billing forecast — and a second copy is how one of them ends up
     * showing `base_rent` in Arabic.
     */
    public static function labelFor(string $type): string
    {
        // ── THE ROW WINS, THEN THE TRANSLATION (2026-08-28) ─────────────────────────────────
        //
        // This asked the lang file FIRST and fell back to the catalogue, which is the opposite of
        // every other code catalogue in the app (`IsCodeCatalogue::labelFor()` reads its rows and
        // only then the group). The consequence: an operator renaming a SHIPPED code changed
        // nothing anywhere it is displayed.
        //
        // Reported from the panel. `parking` is named "Parking & rentable items" in the catalogue —
        // it bills bays, signage, storage and kiosks — and every screen went on calling it
        // "Parking" from the lang key, so a signage licence appeared on the billing forecast under
        // a heading naming car parks.
        //
        // The lang key stays as the FLOOR: a fresh install has no catalogue rows, and a code an
        // accountant adds has no lang key and would otherwise render as
        // `admin.enums.invoice_item_type.chiller_charge`.
        // Memoised per locale: the billing forecast asks once per line per period, so a query per
        // call turned one screen into scores of them for a table of twelve rows.
        $memo = self::LABEL_MEMO.'.'.app()->getLocale();

        $labels = app()->has($memo)
            ? app($memo)
            : tap(
                static::query()->get()->mapWithKeys(fn (self $c) => [$c->code => $c->label()])->all(),
                fn (array $map) => app()->instance($memo, $map),
            );

        if (filled($labels[$type] ?? null)) {
            return $labels[$type];
        }

        $key = "admin.enums.invoice_item_type.{$type}";
        $translated = __($key);

        return $translated === $key
            ? str($type)->replace('_', ' ')->title()->toString()
            : $translated;
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
