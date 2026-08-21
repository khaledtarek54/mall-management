<?php

namespace App\Models;

use App\Models\Concerns\IsCodeCatalogue;
use App\Models\Concerns\RefusesDeletionWhenReferenced;
use App\Support\Attributes\DeletableWhenUnused;
use App\Support\Attributes\PortfolioShared;
use App\Support\ValueSets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * تصنيف تجاري — the merchandising mix, as rows.
 *
 * Twelve values in a `const` on {@see Tenant} drove the store directory, the public shopper API's
 * category filter and every tenant-mix analysis an owner reads. Yardi and MRI make this a row for
 * the reason a leasing team would recognise: the mix is their working vocabulary and it is revised
 * per mall and per season. A mall that lands a cinema, a clinic cluster or a co-working floor wants
 * it in the directory that afternoon.
 *
 * Twelve also flattens differences an Egyptian operator cares about — a pharmacy and a gym are both
 * `health_beauty`, a phone shop and a white-goods showroom are both `electronics`.
 *
 * Same shape as {@see PaymentMethod} and {@see ExpenseCategory}: a code the rows already store, a
 * bilingual name, an active flag, and `ValueSets` widened from the active set with the twelve as its
 * floor — all of it from {@see IsCodeCatalogue}.
 */
#[DeletableWhenUnused(
    blockedBy: ['tenants'],
    instead: 'Deactivate it. A category that classified a retailer stays in the register, because the directory, the shopper app and every historical tenant-mix report read its label.',
)]
// Shared: the mix is one vocabulary across the portfolio, so two malls can be compared.
#[PortfolioShared]
class RetailCategory extends Model
{
    use IsCodeCatalogue;
    use LogsActivity;
    use RefusesDeletionWhenReferenced;

    protected $fillable = [
        'code',
        'name_en',
        'name_ar',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected $attributes = [
        'is_active' => true,
        'sort_order' => 0,
    ];

    /** Retailers classified here — what makes a category undeletable once used. */
    public function tenants(): HasMany
    {
        return $this->hasMany(Tenant::class, 'retail_category', 'code');
    }

    protected static function catalogueMemoKey(): string
    {
        return 'retail_category';
    }

    protected static function catalogueFallbackGroup(): string
    {
        return 'admin.retail_categories';
    }

    /** @return array<int, string> */
    protected static function catalogueFloorCodes(): array
    {
        return ValueSets::allowed('tenants', 'retail_category') ?? [];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'name_en', 'name_ar', 'is_active', 'sort_order'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('retail_category');
    }
}
