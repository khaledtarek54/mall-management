<?php

namespace App\Models;

use App\Models\Concerns\HasSearchText;
use App\Services\DepreciationService;
use App\Support\ActivityLogging;
use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PostingDateGuardedBy;
use App\Support\Attributes\PropertyOwned;
use App\Support\PostingDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * A depreciable asset the operator owns (module 23), scoped to one property.
 * Depreciation is straight-line: (acquisition_cost − salvage_value) spread over
 * useful_life_months. Accumulated depreciation is DERIVED from depreciation_entries
 * (DepreciationService), never a cached column.
 */
#[DeletionAllowed(reason: 'operational: soft-delete IS the retirement path — the sweep voids the asset\'s entire GL footprint, which a scenario test pins')]
#[PropertyOwned]
#[PostingDateGuardedBy(guard: FixedAsset::class)]
class FixedAsset extends Model
{
    use HasFactory, HasSearchText, LogsActivity, SoftDeletes;

    protected $fillable = [
        'asset_id',
        'name',
        'tag',
        'category',
        'acquisition_date',
        'acquisition_cost',
        'salvage_value',
        'useful_life_months',
        'method',
        // Which Egyptian income-tax pool this asset falls in (Law 91/2005 Art. 25). Separate
        // from `method`, which is the ACCOUNTING basis — the two answer different questions
        // and an asset routinely has a different rate under each.
        'tax_pool',
        'funded_from',
        'status',
        'is_opening_balance',
        'opening_accumulated_depreciation',
        'disposed_on',
        'notes',
    ];

    protected $casts = [
        'acquisition_date' => 'date',
        'disposed_on' => 'date',
        'acquisition_cost' => 'decimal:2',
        'salvage_value' => 'decimal:2',
        'useful_life_months' => 'integer',
        'is_opening_balance' => 'boolean',
        'opening_accumulated_depreciation' => 'decimal:2',
    ];

    /**
     * Asset name and the tag physically stuck on it.
     *
     * @return array<int, string|int|float|null>
     */
    public function searchTextSources(): array
    {
        return [
            $this->name,
            $this->tag,
            $this->category,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'fixed_asset');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function depreciationEntries(): HasMany
    {
        return $this->hasMany(DepreciationEntry::class);
    }

    public function disposal(): HasOne
    {
        return $this->hasOne(FixedAssetDisposal::class);
    }

    /**
     * Everything this asset has depreciated — **the one definition**.
     *
     * `opening_accumulated_depreciation` carries what was already written off before Atriom existed;
     * `depreciation_entries` carries every month since. A legacy chiller three years into a ten-year
     * life must show both, or the balance sheet carries it at cost and it depreciates its full value
     * a second time.
     *
     * Named here rather than in `DepreciationService` because there were already **two** independent
     * summers of `depreciationEntries()->sum('amount')` — the service, and
     * `FixedAssetDisposalJournalizer`, which computes gain or loss on sale from its own copy.
     * Adding the opening figure to one and not the other would have posted a wrong gain on every
     * legacy asset ever sold, which is the un-propagated-fix pattern this codebase keeps producing.
     * Both now call this.
     */
    public function accumulatedDepreciation(): float
    {
        return round(
            (float) $this->opening_accumulated_depreciation
            + (float) $this->depreciationEntries()->sum('amount'),
            2,
        );
    }

    /** The child ledger sources whose GL follows this asset's lifecycle (Phase 2/2b). */
    protected function ledgerChildRelations(): array
    {
        return [$this->depreciationEntries(), $this->disposal()];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    protected static function booted(): void
    {
        // NOT-NULL guard for the money columns (the meter_readings.cost bug class).
        static::saving(function (self $fixedAsset) {
            foreach (['acquisition_cost', 'salvage_value'] as $column) {
                $raw = $fixedAsset->getAttributes()[$column] ?? null;
                if ($raw === null || $raw === '') {
                    $fixedAsset->{$column} = 0;
                }
            }

            // `acquisition_date` is the acquisition entry's GL entry_date
            // (FixedAssetAcquisitionJournalizer), and it is a freely-editable DatePicker.
            // Back-dated into a CLOSED period, the register row commits while the Dr
            // Furniture / Cr Cash entry is refused inside the best-effort sync job — the
            // asset exists on the register and nowhere in the books.
            //
            // This module has no create/update service (the Filament resource writes the
            // model), so the model's own save is the single choke point every path shares:
            // form, console, seeder, factory, API.
            //
            // Only when the date is actually CHANGING. Re-checking on every save would
            // make an asset acquired in a since-closed month uneditable — you could not
            // fix its name or tag — which is a different rule from the one being enforced.
            // What matters is nobody MOVING an entry into a sealed period.
            if ($fixedAsset->isDirty('acquisition_date') && filled($fixedAsset->acquisition_date)) {
                PostingDate::assertOpen($fixedAsset->acquisition_date, 'acquisition_date');
            }

            // ── A DISPOSED asset's money and identity fields are frozen ────────────────────────
            // Disposal is terminal: it posts a write-off (Dr Accumulated Depreciation + proceeds,
            // Cr the asset's cost, gain or loss to the P&L) and cannot be re-run. The `updated`
            // hook below then DELIBERATELY re-derives the child entries when `acquisition_cost`
            // moves — right for a live asset whose cost is genuinely corrected, and exactly wrong
            // for one that has been sold: it restates an already-posted disposal, changing the gain
            // or loss on a sale that already happened, in a period that may since have closed.
            // Meanwhile the acquisition entry moves with the new cost while the disposal's credit
            // does not, leaving Furniture & Equipment carrying an asset the company no longer owns.
            //
            // Housekeeping stays open — an operator must still be able to fix a name, tag, category
            // or note after disposal. Guarded on the ORIGINAL status so the disposal itself, which
            // sets `status` and `disposed_on` in one update, is not blocked by its own outcome.
            // (Module 23 close-out, 2026-08-11 — the AP/AR/lease mirror of the same rule.)
            if ($fixedAsset->exists && $fixedAsset->getOriginal('status') === 'disposed') {
                foreach (['acquisition_cost', 'salvage_value', 'acquisition_date', 'useful_life_months', 'method', 'asset_id', 'disposed_on', 'status'] as $field) {
                    if ($fixedAsset->isDirty($field)) {
                        throw new \DomainException(__('admin.fixed_assets.errors.disposed_immutable'));
                    }
                }
            }

            // ── A re-cost may never fall below what has already been charged ───────────────────
            // `DepreciationService::assertRecostValid()` states the reason: accumulated of 60,000
            // against a new base of 30,000 leaves the ledger carrying −30,000 of net fixed assets.
            // It had exactly ONE caller — `EditFixedAsset`, a Filament page — so an import, the
            // console, a factory or any future screen walked straight past it into that state.
            //
            // Checked here because this module has no create/update service; the model's own save
            // is the single choke point every path shares, which is the same reasoning the
            // posting-date guard above already relies on.
            if ($fixedAsset->exists && $fixedAsset->isDirty(['acquisition_cost', 'salvage_value'])) {
                app(DepreciationService::class)->assertRecostValid(
                    $fixedAsset,
                    (float) $fixedAsset->acquisition_cost,
                    (float) $fixedAsset->salvage_value,
                );
            }
        });

        // --- Keep the depreciation charges' ledger entries in lock-step with the
        // parent. Each DepreciationEntry is its OWN ledger source, but the windowed
        // `accounting:sync-ledger` sweep discovers sources by their own updated_at.
        // A change to the PARENT (soft-delete / restore / re-home) does not bump the
        // children's updated_at, so without these hooks the charges' journal entries
        // strand — posted for a deleted asset, or dimensioned to the old property —
        // until a manual `--all` backfill. Bumping the children brings them back into
        // the sweep's recent window so their GL self-heals on the very next run.

        // Soft-delete cascades to the child sources (depreciation charges + disposal)
        // so the sweep voids their GL too, stamped with the parent's OWN deleted_at so
        // the restore can target exactly the rows this cascade trashed — never a row
        // trashed for another reason. Runs on `deleted` (after deleted_at is set). A
        // force-delete lets the FK cascade physically remove them (an out-of-band op —
        // see the module doc).
        static::deleted(function (self $fixedAsset) {
            if ($fixedAsset->isForceDeleting()) {
                return;
            }
            foreach ($fixedAsset->ledgerChildRelations() as $relation) {
                $relation->update(['deleted_at' => $fixedAsset->deleted_at, 'updated_at' => now()]);
            }
        });

        // Restore ONLY the child rows this asset's delete cascaded (matched on that
        // exact deleted_at), so a row removed for another reason stays removed.
        static::restoring(function (self $fixedAsset) {
            foreach ($fixedAsset->ledgerChildRelations() as $relation) {
                $relation->onlyTrashed()
                    ->where('deleted_at', $fixedAsset->deleted_at)
                    ->update(['deleted_at' => null, 'updated_at' => now()]);
            }
        });

        // A change to a field the child sources DERIVE from must re-flow to their GL:
        // asset_id (both re-dimension) and acquisition_cost (the disposal's Furniture
        // credit). Bump the children so the sweep re-derives them — else a re-costed or
        // re-homed asset strands its child entries (they key on their own updated_at).
        static::updated(function (self $fixedAsset) {
            if ($fixedAsset->wasChanged('asset_id') || $fixedAsset->wasChanged('acquisition_cost')) {
                foreach ($fixedAsset->ledgerChildRelations() as $relation) {
                    $relation->withTrashed()->update(['updated_at' => now()]);
                }
            }
        });
    }
}
