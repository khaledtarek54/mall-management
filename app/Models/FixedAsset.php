<?php

namespace App\Models;

use App\Models\Concerns\AllocatesDocumentNumber;
use App\Models\Concerns\HasSearchText;
use App\Models\Concerns\RecordsBankAccount;
use App\Models\Concerns\RefusesRestatementOfCommittedMoney;
use App\Services\DepreciationService;
use App\Support\ActivityLogging;
use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PostingDateGuardedBy;
use App\Support\Attributes\PropertyOwned;
use App\Support\Modules;
use App\Support\PostingDate;
use App\Support\TaxDepreciation;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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
    use AllocatesDocumentNumber, HasFactory, HasSearchText, LogsActivity, RecordsBankAccount, RefusesRestatementOfCommittedMoney, SoftDeletes;

    /**
     * The register rows that are still ON THE BALANCE SHEET.
     *
     * A DISPOSED asset keeps its cost, its accumulated depreciation and its dates on the register
     * for the audit trail — "where did that chiller go?" is a question this register has to answer
     * — but `FixedAssetDisposalJournalizer` has already credited the cost off Furniture &
     * Equipment and debited the accumulated depreciation back, so its carrying amount in the books
     * is ZERO.
     *
     * Deliberately NOT a second spelling of {@see scopeActive()}. That answers "is this still being
     * depreciated" — the monthly run's question — and this answers "is this still on the balance
     * sheet". They agree today because `fixed_assets.status` holds two values; the day a third
     * arrives they are two different questions and this is the one a balance-sheet total wants.
     *
     * @var list<string>
     */
    public const ON_BOOKS_STATUSES = ['active'];

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
        // The outbound RAIL the purchase moved on (a `payment_methods` code; the legacy `cash|bank`
        // literals are its floor), and WHICH bank account — `RecordsBankAccount`, the ninth
        // document on the concern, so the acquisition's credit leg lands in that bank's own chart
        // account rather than the generic `bank` role (meeting 2026-09-02, point 15).
        'funded_from',
        'bank_account_id',
        // The supplier it was bought from — a `vendors` row, never a typed name: the market
        // acquires an asset THROUGH the supplier, and the bill that capitalises it (the next
        // slice) needs a counterparty that exists.
        'vendor_id',
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

    /** The supplier it was bought from; `withTrashed()` so the register still names a retired one. */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withTrashed();
    }

    /** This document calls its rail `funded_from` — the acquisition entry's credit side. */
    public static function bankAccountRailColumn(): string
    {
        return 'funded_from';
    }

    /**
     * The property this asset's PURCHASE money belongs to — for `RecordsBankAccount`'s default and
     * its cross-property guard. Not `asset_id`: after a transfer (point 18) the asset lives in
     * another mall while its acquisition entry, and the bank that paid for it, stay in the one it
     * was bought in. Read off the same history the journalizer reads, so the guard and the posting
     * cannot disagree about which mall the bank has to belong to.
     */
    protected static function bankAccountAssetOf($document): ?int
    {
        /** @var self $document */
        if ($document->acquisition_date === null) {
            return $document->asset_id === null ? null : (int) $document->asset_id;
        }

        return $document->propertyOn(CarbonImmutable::parse($document->acquisition_date));
    }

    /** @return HasMany<FixedAssetTransfer, $this> */
    public function transfers(): HasMany
    {
        return $this->hasMany(FixedAssetTransfer::class)->orderBy('transferred_on')->orderBy('id');
    }

    /** The GL sources a transfer writes — one OUT, one IN per act. @return HasMany<FixedAssetTransferLeg, $this> */
    public function transferLegs(): HasMany
    {
        return $this->hasMany(FixedAssetTransferLeg::class);
    }

    /**
     * Which property held this asset in the MONTH of a date (meeting 2026-09-02, point 18).
     *
     * The ONE reading every journalizer takes: the acquisition is dimensioned to the property the
     * asset was bought in, each depreciation charge to the property that held it that month, the
     * disposal to where it was sold from. A transfer is effective for the WHOLE of its month
     * (SAP's period control on a transfer — the transfer month's charge belongs to the receiving
     * cost centre), which is also what lets the OUT leg's accumulated figure be exactly the charges
     * posted for the months before it. With no transfer on record the answer is `asset_id`, so
     * nothing an install already holds reads any differently.
     */
    public function propertyOn(CarbonInterface $date): int
    {
        $month = CarbonImmutable::instance($date)->startOfMonth();

        $next = $this->transfers()
            ->where('transferred_on', '>=', $month->addMonth()->toDateString())
            ->first();

        return (int) ($next?->from_asset_id ?? $this->asset_id);
    }

    /**
     * Accumulated depreciation from the charges posted for the months BEFORE one — what a
     * transfer dated in that month moves out with the asset. The opening figure counts: it is
     * write-off taken before this system existed, and it left with the asset.
     */
    public function accumulatedDepreciationBefore(CarbonInterface $month): float
    {
        return round(
            (float) $this->opening_accumulated_depreciation
            + (float) $this->depreciationEntries()
                ->whereDate('period_month', '<', CarbonImmutable::instance($month)->startOfMonth()->toDateString())
                ->sum('amount'),
            2,
        );
    }

    /**
     * The figures a transfer's legs FROZE off this row (point 18): the cost the OUT leg credited
     * and the IN leg debited, the date the acquisition was dimensioned on, and the opening
     * write-off the OUT leg carried. A transfer row on disk locks all four — a re-cost after a
     * transfer would leave the old mall's Furniture carrying the difference for an asset it no
     * longer holds, with the trial balance still footing (the review measured all four, 2026-09-12).
     * The way through is the act's own: transfer it back, correct, transfer again.
     */
    public const TRANSFER_FROZEN = ['acquisition_cost', 'acquisition_date', 'is_opening_balance', 'opening_accumulated_depreciation'];

    /** Has this asset ever been transferred? Then {@see TRANSFER_FROZEN} is locked — the form and the guard read this. */
    public function historyLockedByTransfer(): bool
    {
        return $this->exists && $this->transfers()->exists();
    }

    /**
     * Committed once it has begun depreciating, or been disposed — the schedule and the books both
     * rest on it (`ChangeImpact::POLICY`). Before that a wrong property at registration is an
     * ordinary correction: the acquisition entry re-derives and nothing else has been posted.
     */
    public function isCommittedMoney(): bool
    {
        return $this->status === 'disposed' || $this->depreciationEntries()->exists();
    }

    /**
     * The one write of `asset_id` a committed asset accepts: the transfer act's own.
     *
     * A carve-out by SHAPE (the `isResumingFromExpiry()` idiom), not a flag a caller sets: the
     * only thing dirty is the property, and a `FixedAssetTransfer` row already records this asset
     * moving from the property it holds to the one being written — which only
     * `TransferFixedAssetService` writes, inside the same transaction, before it touches the row.
     * A crafted payload cannot produce that row, so the free edit of `asset_id` — the re-home that
     * restated every posted month — stays shut.
     */
    protected function restatementPermittedBecause(string $field): bool
    {
        if ($field !== 'asset_id') {
            return false;
        }

        $dirty = array_diff(array_keys($this->getDirty()), ['asset_id', 'updated_at']);

        if ($dirty !== []) {
            return false;
        }

        // The LATEST transfer, not any: after a round trip (A→B, then B→A) a row "from A to B"
        // exists for ever, and asking `exists()` would let a free A→B edit through again.
        $latest = $this->transfers()->reorder()->orderByDesc('id')->first();

        return $latest !== null
            && (int) $latest->from_asset_id === (int) $this->getOriginal('asset_id')
            && (int) $latest->to_asset_id === (int) $this->asset_id;
    }

    /**
     * The asset CLASS this row was registered under (meeting 2026-09-02, points 11 · 13 · 14):
     * where its tag series, its proposed life, its memo value and its tax pool came from. Keyed on
     * the CODE the `category` column stores, so a class renamed on its screen relabels every asset
     * at once and a legacy value with no row still reads as itself.
     *
     * NOT named `category()`: a relation named after its own foreign-key column shadows the
     * attribute — reading `$asset->category` then resolves the RELATION, whose `getParentKey()`
     * reads `$asset->category` again (measured: every read of the column threw).
     */
    public function assetClass(): BelongsTo
    {
        return $this->belongsTo(FixedAssetCategory::class, 'category', 'code');
    }

    /**
     * The straight-line rate as a percentage a year — `12 ÷ useful_life_months × 100`.
     *
     * The accountant reads a life as a RATE (Law 91 states its rates as percentages; SAP's key is
     * "20%" as readily as "5 years"), and the one figure the register showed was months. Months stay
     * the ONE stored truth — `DepreciationService::monthlyAmount()` divides by them — and this is the
     * other reading of the same number, offered on the form both ways. Null while no life is set.
     */
    public function annualRatePct(): ?float
    {
        return self::annualRateFor((int) $this->useful_life_months);
    }

    /** The rate a life in months reads as — the form's half of the pair, before a row exists. */
    public static function annualRateFor(int $months): ?float
    {
        return $months > 0 ? round(1200 / $months, 2) : null;
    }

    /**
     * Months from a rate, the reciprocal of {@see annualRatePct()} — `1200 ÷ rate`, rounded to a
     * whole month, never below one. What the form stores when the operator types the percentage.
     */
    public static function monthsForAnnualRate(float $ratePct): ?int
    {
        return $ratePct > 0 ? max(1, (int) round(1200 / $ratePct)) : null;
    }

    /**
     * The next tag in this property's series for a class — `{prefix}-0001`.
     *
     * Per PROPERTY, because a tag's identity already is (property, tag): two malls each number
     * their chillers from 1, which is the rule the importer keys on. MAX-based over `withTrashed()`
     * so a retired asset keeps its number reserved, and LENGTH-first so the series counts past its
     * padding (EG-10) — the same allocator shape every document series here takes, and the one
     * `DocumentSeriesOrderingConformanceTest` derives its sweep from.
     *
     * Only a tag whose tail is a NUMBER counts as a member of the series. Unlike an invoice number,
     * a tag is routinely TYPED — a migrating register's own `FUR-2026-0001` is kept as it stands
     * (the counterparty-code rule) — and `(int) '2026-0001'` reads as 2026, which would have the
     * next blank tag allocated `FUR-2027` (the review caught it). The set is bounded by one
     * property's assets in one class, so reading it back and filtering in PHP costs nothing.
     */
    public static function generateTag(int $assetId, string $prefix): string
    {
        $prefix = strtoupper($prefix).'-';

        $last = static::withTrashed()
            ->where('asset_id', $assetId)
            ->where('tag', 'like', $prefix.'%')
            ->orderByRaw('LENGTH(tag) DESC, tag DESC')
            ->pluck('tag')
            ->map(fn (string $tag): string => substr($tag, strlen($prefix)))
            ->filter(fn (string $tail): bool => preg_match('/^\\d+$/', $tail) === 1)
            ->map(fn (string $tail): int => (int) $tail)
            ->max();

        return sprintf('%s%04d', $prefix, ($last ?? 0) + 1);
    }

    /** MAX+1 with a collision loop — the belt to the allocation lock's braces. */
    protected static function generateUniqueTag(int $assetId, string $prefix): string
    {
        $series = strtoupper($prefix).'-';
        $candidate = static::generateTag($assetId, $prefix);
        $attempts = 0;

        while (static::withTrashed()->where('asset_id', $assetId)->where('tag', $candidate)->exists()) {
            $candidate = sprintf('%s%04d', $series, (int) substr($candidate, strlen($series)) + 1);

            if (++$attempts > 1000) {
                return $series.uniqid();
            }
        }

        return $candidate;
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

    /**
     * The SQL twin of {@see accumulatedDepreciation()}, correlated to `fixed_assets.id`.
     *
     * TWO readers, and until 2026-09-04 they were independent copies that disagreed: the register's
     * derived `accumulated` column (`FixedAssetResource::getEloquentQuery()`), and the "Fully
     * depreciated" write-off worklist, which summed the entries alone and compared them against the
     * GROSS cost. Whatever this expression says, both now say — the same reason the PHP version
     * above exists rather than a third `depreciationEntries()->sum()`.
     *
     * FULLY QUALIFIED, because a select ALIAS cannot be referenced from a WHERE on the same level —
     * which is exactly why the filter could not simply read `accumulated` — and this string has to
     * be valid in both places.
     *
     * `deleted_at is null` because `DepreciationEntry` soft-deletes and the PHP twin reads the
     * relation, which applies that scope; the old raw copy did not. Measured 2026-09-04 on
     * `mall_management` and `mall_management_qa`: 0 soft-deleted depreciation entries, so no figure
     * on either database moves. It is what stops the two answers drifting the day the parent-asset
     * soft-delete cascade trashes some.
     */
    public static function accumulatedDepreciationSql(): string
    {
        return 'COALESCE(fixed_assets.opening_accumulated_depreciation, 0) + COALESCE(('
            .'SELECT SUM(amount) FROM depreciation_entries '
            .'WHERE depreciation_entries.fixed_asset_id = fixed_assets.id '
            .'AND depreciation_entries.deleted_at IS NULL'
            .'), 0)';
    }

    /** Is this row still on the balance sheet? The row half of {@see ON_BOOKS_STATUSES}. */
    public function isOnBooks(): bool
    {
        return in_array($this->status, self::ON_BOOKS_STATUSES, true);
    }

    /** The child ledger sources whose GL follows this asset's lifecycle (Phase 2/2b). */
    protected function ledgerChildRelations(): array
    {
        // The transfer act's rows ride along (point 18): a leg is a GL source of its own whose
        // journalizer voids it for a trashed asset, and the transfer row is the leg's parent —
        // both must leave and come back with the asset, stamped with its own deleted_at.
        return [$this->depreciationEntries(), $this->disposal(), $this->transferLegs(), $this->hasMany(FixedAssetTransfer::class)];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    protected static function booted(): void
    {
        // NOT-NULL guard for the money columns (the meter_readings.cost bug class).
        static::saving(function (self $fixedAsset) {
            // ── THE CLASS PROPOSES WHAT THE ROW LEFT BLANK — on CREATE only ──────────────────
            // The memo value, the useful life and the tax pool an asset of this kind usually has
            // (`FixedAssetCategory::defaultsFor()`). The form prefills them when the category is
            // picked; this is the same proposal for the doors with no form — the importer, a
            // seeder, a factory — so a migrating register whose file leaves salvage blank gets the
            // class's memo value rather than a silent zero. BEFORE the NOT-NULL coercion below,
            // which would otherwise turn the blank into the zero this exists to replace. A figure
            // stated on the row — including an explicit 0 — is never overwritten, and a row that
            // exists is never touched: what is on the asset is what depreciates.
            if (! $fixedAsset->exists && ($defaults = FixedAssetCategory::defaultsFor($fixedAsset->category)) !== null) {
                $raw = $fixedAsset->getAttributes();

                if (($raw['salvage_value'] ?? null) === null || ($raw['salvage_value'] ?? null) === '') {
                    $fixedAsset->salvage_value = $defaults['salvage_value'];
                }

                if (blank($raw['useful_life_months'] ?? null) && $defaults['useful_life_months'] !== null) {
                    $fixedAsset->useful_life_months = $defaults['useful_life_months'];
                }

                if (blank($raw['tax_pool'] ?? null) && $defaults['tax_pool'] !== null) {
                    $fixedAsset->tax_pool = $defaults['tax_pool'];
                }
            }

            // A life must come from somewhere — the row, or its class. A blank left by a door that
            // named a class proposing none is refused in words, not as the column's NOT NULL. The
            // form requires the field, so the door this reaches is the importer — whose own
            // `beforeCreate()` throws the SAME sentence as a `RowImportFailedException`, because
            // Filament's `ImportCsv` writes only that exception's words into the failed-rows file
            // and swallows every other throwable into a message-less failed row.
            if (! $fixedAsset->exists && blank($fixedAsset->getAttributes()['useful_life_months'] ?? null)) {
                throw new \DomainException(__('admin.fixed_assets.errors.useful_life_required'));
            }

            // The tax pool is stated on every row: the class's, else the statutory default —
            // `TaxDepreciationService` would read a null the same way, and a stated value is what
            // the form shows back. The form carries NO default of its own (it did, and `general`
            // from mount meant the class's proposal never reached the field — the review caught it).
            //
            // **Only while the tax schedule is switched on** (`tax_depreciation`, meeting 2026-09-02
            // point 12). With it off nobody can state a pool — the field is hidden on every door —
            // so an asset whose class proposes none is left NULL, which the schedule already reads
            // as the law's default (`TaxDepreciationScheduleTest`: general, never dropped) and the
            // form shows back as BLANK the day the switch returns: an unconfirmed classification
            // the accountant can see and settle, rather than a `general` the system invented and
            // nobody would ever look at again. Found by review.
            if (! $fixedAsset->exists
                && blank($fixedAsset->getAttributes()['tax_pool'] ?? null)
                && Modules::enabled('tax_depreciation')) {
                $fixedAsset->tax_pool = TaxDepreciation::default();
            }

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
            // `funded_from` and `bank_account_id` joined the list on 2026-09-12: they choose the
            // acquisition entry's CREDIT leg, and re-pointing it on a sold asset restates a posted
            // acquisition under a disposal that has already netted it off — the same restatement
            // the cost freeze exists to refuse, through the other side of the entry.
            if ($fixedAsset->exists && $fixedAsset->getOriginal('status') === 'disposed') {
                foreach (['acquisition_cost', 'salvage_value', 'acquisition_date', 'useful_life_months', 'method', 'asset_id', 'disposed_on', 'status', 'funded_from', 'bank_account_id'] as $field) {
                    if ($fixedAsset->isDirty($field)) {
                        throw new \DomainException(__('admin.fixed_assets.errors.disposed_immutable'));
                    }
                }
            }

            // ── Once transferred, what the legs froze is locked (point 18, 2026-09-12) ─────────
            // The legs carry `cost` and `accumulated_depreciation` as the act's own figures; the
            // four columns they were read from stay DERIVED/PROSPECTIVE for an asset that never
            // moved (a re-cost is a supported correction), and lock the moment a transfer row
            // exists — else the old mall's books carry the difference for an asset it no longer
            // holds, invisibly, because the portfolio trial balance still foots.
            if ($fixedAsset->exists && $fixedAsset->isDirty(self::TRANSFER_FROZEN) && $fixedAsset->historyLockedByTransfer()) {
                throw new \DomainException(__('admin.fixed_assets.errors.transferred_history_locked'));
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

        // ── THE NUMBER COMES FROM THE CLASS (meeting 2026-09-02, point 11) ─────────────────
        // A blank tag is allocated `{prefix}-0001` in this property's series for the category,
        // under the document-number lock held across the INSERT (`AllocatesDocumentNumber`), so
        // two operators registering chillers at once do not both get `HVAC-0007`. A tag the
        // operator typed or a migrating register supplied is KEPT — its accountant's paperwork
        // already carries it, which is the `AllocatesPartyCode` rule for a counterparty code. An
        // asset whose class has no series (a legacy value with no row) keeps needing a tag: the
        // column is NOT NULL, and the form requires it on that path — a blank there would reach the
        // database as a raw constraint error, which is not a refusal anybody can read.
        static::creating(function (self $fixedAsset): void {
            if (filled($fixedAsset->tag)) {
                return;
            }

            $prefix = FixedAssetCategory::defaultsFor($fixedAsset->category)['tag_prefix'] ?? null;

            if ($prefix === null || $prefix === '' || ! $fixedAsset->asset_id) {
                return;
            }

            $assetId = (int) $fixedAsset->asset_id;

            $fixedAsset->tag = $fixedAsset->allocateDocumentNumber(
                "fixed-asset-tag:{$assetId}:{$prefix}",
                fn (): string => static::generateUniqueTag($assetId, $prefix),
            );
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
        // acquisition_cost (the disposal's Furniture credit) and asset_id — which since point
        // 18 (2026-09-12) re-dimensions a child ONLY on the free re-home of an UNCOMMITTED
        // asset; after a transfer `propertyOn()` answers each posted month its old property and
        // the re-read is a no-op, which is exactly the check worth running. Bump the children
        // so the sweep re-derives them — else a re-costed or re-homed asset strands its child
        // entries (they key on their own updated_at).
        static::updated(function (self $fixedAsset) {
            if ($fixedAsset->wasChanged('asset_id') || $fixedAsset->wasChanged('acquisition_cost')) {
                foreach ($fixedAsset->ledgerChildRelations() as $relation) {
                    $relation->withTrashed()->update(['updated_at' => now()]);
                }
            }
        });
    }
}
