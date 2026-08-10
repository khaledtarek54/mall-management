<?php

namespace App\Models;

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
 * `late_fee` / `nsf_fee` settle last — and that logic is keyed on the {@see \App\Enums\InvoiceItemType}
 * constants, which survive as named references to exactly those codes. A conformance test asserts
 * every enum case has a row here, so an operator cannot delete a code the engine has opinions about
 * and the two lists cannot drift.
 */
class ChargeCode extends Model
{
    use LogsActivity;

    /** In-request memo: the journalizer asks for a role once per invoice LINE. */
    protected static ?array $roleCache = null;

    protected $fillable = [
        'code',
        'name_en',
        'name_ar',
        'posting_role',
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
            ->logOnly(['code', 'name_en', 'name_ar', 'posting_role', 'is_active'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('charge_code');
    }

    protected static function booted(): void
    {
        // Any write invalidates the memo — otherwise re-pointing a code mid-request would keep
        // posting to the old account for the rest of it.
        static::saved(fn () => static::$roleCache = null);
        static::deleted(fn () => static::$roleCache = null);
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
        static::$roleCache ??= static::query()->pluck('posting_role', 'code')->all();

        return static::$roleCache[$code] ?? null;
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
