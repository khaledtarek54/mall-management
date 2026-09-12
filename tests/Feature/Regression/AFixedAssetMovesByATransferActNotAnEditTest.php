<?php

use App\Filament\Admin\RelationManagers\FixedAssetTransfersRelationManager;
use App\Filament\Admin\Resources\FixedAssets\FixedAssetResource;
use App\Filament\Admin\Resources\FixedAssets\Pages\EditFixedAsset;
use App\Filament\Imports\FixedAssetImporter;
use App\Models\AccountingPeriod;
use App\Models\Asset;
use App\Models\BankAccount;
use App\Models\DepreciationEntry;
use App\Models\FixedAsset;
use App\Models\FixedAssetTransfer;
use App\Models\FixedAssetTransferLeg;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerReportService;
use App\Services\Accounting\PeriodService;
use App\Services\DepreciationService;
use App\Services\DisposeFixedAssetService;
use App\Services\TransferFixedAssetService;
use App\Support\JournalNarrative;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\FixedAssetCategorySeeder;
use Database\Seeders\PaymentMethodSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\Models\Import;
use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\PermissionRegistrar;

/**
 * Meeting 2026-09-02, point 18 — *"na2l asl le fixed assets law hnwde mo3dat mn mkan le mkan"*:
 * moving equipment from one property to another.
 *
 * Until this change the only way to move a fixed asset was to edit `fixed_assets.asset_id`, and
 * that re-homed the WHOLE history — the acquisition entry and every posted depreciation charge
 * voided and re-posted into the new mall's dimension, restating months that may be closed (and
 * refused outright once one was). SAP's ABUMN and Yardi's asset transfer post cost and accumulated
 * depreciation OUT of the old books and IN to the new on the transfer date and leave history where
 * it was. So a transfer is a dated ACT with a required reason (`TransferFixedAssetService`), it
 * posts TWO balanced entries — one per property, because every statement here scopes on the
 * entry's own `asset_id` — and the free edit of the property is REFUSED once the asset has begun
 * depreciating (`ChangeImpact` REFUSED, enforced by `RefusesRestatementOfCommittedMoney`).
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    ensureAllPropertiesAsset();
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);
    $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00'));

    $this->from = makeAsset(['code' => 'TNG', 'name' => 'Nile Gate']);
    $this->to = makeAsset(['code' => 'TVP', 'name' => 'Val Plaza']);
    $this->accounts = app(AccountResolver::class);
    $this->actingAs(makeUser('super_admin'));
});

/** A chiller bought in January at 120,000 over 12 months (10,000 a month), depreciated Jan–Mar. */
function p18Chiller(int $assetId, array $attrs = []): FixedAsset
{
    return FixedAsset::create(array_merge([
        'asset_id' => $assetId,
        'name' => 'Lobby chiller',
        'tag' => 'FA-'.substr(uniqid(), -6),
        'acquisition_date' => '2026-01-01',
        'acquisition_cost' => 120000,
        'salvage_value' => 0,
        'useful_life_months' => 12,
        'method' => 'straight_line',
        'funded_from' => 'cash',
        'status' => 'active',
    ], $attrs));
}

function p18Depreciate(int $throughMonth): void
{
    for ($m = 1; $m <= $throughMonth; $m++) {
        app(DepreciationService::class)->run(CarbonImmutable::create(2026, $m, 1));
    }
}

function p18Sweep(): void
{
    test()->artisan('accounting:sync-ledger', ['--all' => true])->assertExitCode(0);
}

function p18Transfer(FixedAsset $asset, int $to, string $on = '2026-04-15', string $reason = 'Chiller moved to the Val Plaza plant room'): FixedAssetTransfer
{
    return app(TransferFixedAssetService::class)->transfer($asset, ['to_asset_id' => $to, 'transferred_on' => $on, 'reason' => $reason]);
}

/** The one posted entry for a GL source. */
function p18Entry(object $source): ?JournalEntry
{
    return JournalEntry::where('source_type', $source->getMorphClass())->where('source_id', $source->getKey())->where('status', 'posted')->first();
}

// ── The act ─────────────────────────────────────────────────────────────────────────────────

it('posts one balanced leg in each property, dated on the transfer, carrying cost and accumulated depreciation to date', function () {
    $fa = p18Chiller($this->from->id);
    p18Depreciate(3);
    p18Sweep();

    $transfer = p18Transfer($fa, $this->to->id);
    p18Sweep();

    expect((float) $transfer->cost)->toBe(120000.0)
        ->and((float) $transfer->accumulated_depreciation)->toBe(30000.0)
        ->and($transfer->legs)->toHaveCount(2)
        ->and($fa->fresh()->asset_id)->toBe($this->to->id);

    $out = $transfer->legs->firstWhere('direction', FixedAssetTransferLeg::OUT);
    $in = $transfer->legs->firstWhere('direction', FixedAssetTransferLeg::IN);
    $outEntry = p18Entry($out);
    $inEntry = p18Entry($in);

    expect($outEntry)->not->toBeNull()->and($inEntry)->not->toBeNull()
        ->and($outEntry->isBalanced())->toBeTrue()->and($inEntry->isBalanced())->toBeTrue()
        ->and((int) $outEntry->asset_id)->toBe($this->from->id)
        ->and((int) $inEntry->asset_id)->toBe($this->to->id)
        ->and($outEntry->entry_date->toDateString())->toBe('2026-04-15');

    $outBy = $outEntry->lines->keyBy('ledger_account_id');
    expect((float) $outBy[$this->accounts->id('furniture_equipment', $this->from->id)]->credit)->toBe(120000.0)
        ->and((float) $outBy[$this->accounts->id('accumulated_depreciation', $this->from->id)]->debit)->toBe(30000.0)
        ->and((float) $outBy[$this->accounts->id('inter_property_clearing', $this->from->id)]->debit)->toBe(90000.0);

    $inBy = $inEntry->lines->keyBy('ledger_account_id');
    expect((float) $inBy[$this->accounts->id('furniture_equipment', $this->to->id)]->debit)->toBe(120000.0)
        ->and((float) $inBy[$this->accounts->id('accumulated_depreciation', $this->to->id)]->credit)->toBe(30000.0)
        ->and((float) $inBy[$this->accounts->id('inter_property_clearing', $this->to->id)]->credit)->toBe(90000.0);

    // Per property the clearing account says what one mall handed the other; portfolio-wide it
    // nets to nothing — the trial balance is the reader's proof.
    $clearing = LedgerAccount::findOrFail($this->accounts->id('inter_property_clearing'));
    $tb = fn (?array $ids) => collect(app(LedgerReportService::class)->trialBalance($ids)['rows'])->firstWhere('code', $clearing->code);
    expect((float) $tb([$this->from->id])['debit_balance'])->toBe(90000.0)
        ->and((float) $tb([$this->to->id])['credit_balance'])->toBe(90000.0)
        ->and((float) ($tb(null)['debit_balance'] ?? 0))->toBe(0.0)
        ->and((float) ($tb(null)['credit_balance'] ?? 0))->toBe(0.0);

    // A second sweep re-reads both legs and moves nothing.
    p18Sweep();
    expect(JournalEntry::where('source_type', $out->getMorphClass())->count())->toBe(2);
});

it('leaves the acquisition and every posted charge where they were, and lands the next charge in the new property', function () {
    $fa = p18Chiller($this->from->id);
    p18Depreciate(3);
    p18Sweep();

    $acquisition = p18Entry($fa);
    $charges = DepreciationEntry::where('fixed_asset_id', $fa->id)->orderBy('period_month')->get();
    $chargeEntries = $charges->map(fn ($c) => p18Entry($c));
    expect($chargeEntries->every(fn ($e) => $e !== null))->toBeTrue();

    p18Transfer($fa, $this->to->id);
    p18Sweep();

    // The SAME entries, still posted, still in Nile Gate — not voided and re-posted into Val Plaza.
    expect(p18Entry($fa)?->id)->toBe($acquisition->id)
        ->and((int) p18Entry($fa)->asset_id)->toBe($this->from->id);
    foreach ($charges as $i => $charge) {
        expect(p18Entry($charge)?->id)->toBe($chargeEntries[$i]->id)
            ->and((int) p18Entry($charge)->asset_id)->toBe($this->from->id)
            ->and(JournalEntry::where('source_type', $charge->getMorphClass())->where('source_id', $charge->id)->count())->toBe(1);
    }

    // April's charge — the transfer month — belongs to the receiving property.
    app(DepreciationService::class)->run(CarbonImmutable::create(2026, 4, 1));
    p18Sweep();
    $april = DepreciationEntry::where('fixed_asset_id', $fa->id)->whereDate('period_month', '2026-04-01')->firstOrFail();
    expect((int) p18Entry($april)->asset_id)->toBe($this->to->id);

    // The register can answer "where was it": month by month, from the transfer rows.
    $fresh = $fa->fresh();
    expect($fresh->propertyOn(CarbonImmutable::parse('2026-01-31')))->toBe($this->from->id)
        ->and($fresh->propertyOn(CarbonImmutable::parse('2026-03-31')))->toBe($this->from->id)
        ->and($fresh->propertyOn(CarbonImmutable::parse('2026-04-01')))->toBe($this->to->id)
        ->and($fresh->propertyOn(CarbonImmutable::parse('2026-12-01')))->toBe($this->to->id);
});

it('carries the write-off taken before this system existed out with the asset', function () {
    // A cut-over asset: 120,000 cost, 40,000 already written off in the accountant's opening entry.
    $fa = p18Chiller($this->from->id, ['opening_accumulated_depreciation' => 40000, 'is_opening_balance' => true]);
    p18Depreciate(2); // + 20,000
    $transfer = p18Transfer($fa, $this->to->id, '2026-03-15');
    p18Sweep();

    expect((float) $transfer->accumulated_depreciation)->toBe(60000.0);
    $out = p18Entry($transfer->legs->firstWhere('direction', 'out'));
    $by = $out->lines->keyBy('ledger_account_id');
    expect((float) $by[$this->accounts->id('accumulated_depreciation', $this->from->id)]->debit)->toBe(60000.0)
        ->and((float) $by[$this->accounts->id('inter_property_clearing', $this->from->id)]->debit)->toBe(60000.0);
});

it('records the reason as data in the audit trail, and words both legs in the reader\'s language', function () {
    $fa = p18Chiller($this->from->id);
    p18Depreciate(3);
    $transfer = p18Transfer($fa, $this->to->id, reason: 'Plant room at Nile Gate decommissioned');
    p18Sweep();

    $row = Activity::query()->where('log_name', 'fixed_asset')->where('event', 'transferred')
        ->where('subject_id', $fa->id)->latest('id')->firstOrFail();
    expect($row->description)->toBe('fixed_asset.transferred')
        ->and($row->properties['reason'])->toBe('Plant room at Nile Gate decommissioned')
        ->and((int) $row->properties['to_asset_id'])->toBe($this->to->id)
        ->and($row->causer_id)->toBe(auth()->id());

    $out = p18Entry($transfer->legs->firstWhere('direction', 'out'));
    $in = p18Entry($transfer->legs->firstWhere('direction', 'in'));
    foreach (['en', 'ar'] as $locale) {
        $outText = JournalNarrative::resolve($out->description_key, $out->description_data, locale: $locale);
        $inText = JournalNarrative::resolve($in->description_key, $in->description_data, locale: $locale);
        expect($outText)->toContain('Lobby chiller')->toContain('Val Plaza')->not->toContain(':property')
            ->and($inText)->toContain('Lobby chiller')->toContain('Nile Gate')->not->toContain(':property');
        if ($locale === 'ar') {
            expect($outText)->toMatch('/\p{Arabic}/u')->and($inText)->toMatch('/\p{Arabic}/u');
        }
    }
});

// ── The free edit is shut, and only for a committed asset ───────────────────────────────────

it('refuses a free edit of the property once the asset has begun depreciating, and still allows it before', function () {
    $committed = p18Chiller($this->from->id);
    p18Depreciate(1);
    expect(fn () => $committed->fresh()->update(['asset_id' => $this->to->id]))->toThrow(DomainException::class);
    expect($committed->fresh()->asset_id)->toBe($this->from->id);

    // A wrong property at registration is a correction while nothing has been charged.
    $fresh = p18Chiller($this->from->id, ['tag' => 'FA-NEW']);
    $fresh->update(['asset_id' => $this->to->id]);
    expect($fresh->fresh()->asset_id)->toBe($this->to->id);

    // And a DISPOSED asset is committed too — its disposal is dimensioned where it was sold from.
    // The predicate is asserted directly because the disposed FREEZE on the model refuses the
    // same write first (mutation: dropping `disposed` from `isCommittedMoney()` left the update
    // refused), and `ChangeImpact`'s committed sentence says "or been disposed".
    $disposed = p18Chiller($this->from->id, ['tag' => 'FA-OLD']);
    app(DisposeFixedAssetService::class)->dispose($disposed, ['disposed_on' => '2026-05-01', 'proceeds' => 0]);
    expect($disposed->fresh()->isCommittedMoney())->toBeTrue();
    expect(fn () => $disposed->fresh()->update(['asset_id' => $this->to->id]))->toThrow(DomainException::class);
});

it('lets ONLY the transfer act write the property — a round trip does not reopen the free edit', function () {
    $fa = p18Chiller($this->from->id);
    p18Depreciate(2);

    p18Transfer($fa, $this->to->id, '2026-03-10');
    p18Transfer($fa->fresh(), $this->from->id, '2026-03-20', 'Sent back — wrong chiller');
    expect($fa->fresh()->asset_id)->toBe($this->from->id);

    // A row "from Nile Gate to Val Plaza" exists on disk now. It must not vouch for a free edit
    // in that direction: the carve-out reads the LATEST transfer, not any.
    expect(fn () => $fa->fresh()->update(['asset_id' => $this->to->id]))->toThrow(DomainException::class);

    // Nor does a transfer row let a payload that ALSO retypes money through: the shape is
    // `asset_id` alone. Reachable from outside only by writing the transfer row directly — the
    // service does it inside one transaction, so this stands in for that moment: the row says
    // Nile Gate → Val Plaza and the asset is still in Nile Gate.
    $fa->fresh()->transfers()->create([
        'from_asset_id' => $this->from->id, 'to_asset_id' => $this->to->id, 'transferred_on' => '2026-05-01',
        'cost' => 120000, 'accumulated_depreciation' => 30000, 'reason' => 'stand-in for the act mid-transaction',
    ]);
    // (130,000 — a re-cost the recost guard accepts, so THIS clause is the one refusing.)
    expect(fn () => $fa->fresh()->update(['asset_id' => $this->to->id, 'acquisition_cost' => 130000]))->toThrow(DomainException::class);
    expect($fa->fresh()->acquisition_cost)->toEqual(120000);
    $fa->fresh()->update(['asset_id' => $this->to->id]); // the act's own shape — through
    expect($fa->fresh()->asset_id)->toBe($this->to->id);
});

it('keeps the purchase bank in the property the asset was bought in, so the bank guard does not block the move', function () {
    $this->seed(PaymentMethodSeeder::class);
    $leaf = LedgerAccount::create(['code' => '11900101', 'name_en' => 'CIB — TNG', 'name_ar' => 'CIB — TNG', 'type' => 'asset', 'is_postable' => true, 'is_active' => true]);
    $bank = BankAccount::create(['asset_id' => $this->from->id, 'name' => 'CIB', 'account_number' => 'TNG-1', 'purpose' => BankAccount::PURPOSE_OPERATING, 'is_default' => true, 'ledger_account_id' => $leaf->id]);
    $fa = p18Chiller($this->from->id, ['funded_from' => 'bank_transfer', 'bank_account_id' => $bank->id]);
    expect($fa->bank_account_id)->toBe($bank->id);
    p18Depreciate(1);
    p18Sweep();

    // The guard on `RecordsBankAccount` asks which property the PURCHASE belongs to; read off
    // `asset_id` it would refuse this save (the bank is Nile Gate's, the asset is now Val Plaza's).
    p18Transfer($fa, $this->to->id, '2026-02-15');
    p18Sweep();

    expect($fa->fresh()->asset_id)->toBe($this->to->id)
        ->and($fa->fresh()->bank_account_id)->toBe($bank->id);
    $acquisition = p18Entry($fa);
    expect((int) $acquisition->asset_id)->toBe($this->from->id)
        ->and($acquisition->lines->firstWhere('ledger_account_id', $leaf->id)?->credit)->not->toBeNull();

    // And the asset's own page, under the RECEIVING mall, still saves a name-only edit. The bank
    // picker narrowed to the selected mall until the review (2026-09-12): it could label neither
    // the buying mall's bank the row names nor accept the receiving mall's (the guard above), so
    // every save was refused on a field nobody touched.
    asTenant($this->to, function () use ($fa) {
        Livewire::test(EditFixedAsset::class, ['record' => $fa->getKey()])
            ->fillForm(['name' => 'Lobby chiller (relocated)'])
            ->call('save')
            ->assertHasNoFormErrors();
    });
    expect($fa->fresh()->name)->toBe('Lobby chiller (relocated)')
        ->and($fa->fresh()->bank_account_id)->toBe($bank->id);
});

// ── What the legs froze is locked (found by review) ──────────────────────────────────────────

it('locks the cost, the acquisition date and the opening figures once transferred, and disables them on the form', function () {
    $fa = p18Chiller($this->from->id);
    p18Depreciate(3);

    // Before the transfer a re-cost is the supported correction it always was.
    $fa->fresh()->update(['acquisition_cost' => 130000]);
    expect((float) $fa->fresh()->acquisition_cost)->toBe(130000.0);

    p18Transfer($fa->fresh(), $this->to->id);

    // After it, each of the four figures the legs carried is refused — with the way through named.
    foreach (['acquisition_cost' => 150000, 'acquisition_date' => '2026-05-01', 'is_opening_balance' => true, 'opening_accumulated_depreciation' => 5000] as $field => $value) {
        expect(fn () => $fa->fresh()->update([$field => $value]))
            ->toThrow(DomainException::class, __('admin.fixed_assets.errors.transferred_history_locked'));
    }
    expect((float) $fa->fresh()->acquisition_cost)->toBe(130000.0);

    // Housekeeping stays open, and a PROSPECTIVE term (the life) still moves future charges.
    $fa->fresh()->update(['name' => 'Renamed chiller', 'useful_life_months' => 24]);
    expect($fa->fresh()->name)->toBe('Renamed chiller');

    // Under the importer the model's refusal would be a message-less failed row; the importer's
    // own hook words it. A re-import of the asset by tag under its NEW property, restating the cost.
    $this->seed(FixedAssetCategorySeeder::class);
    $import = Import::create([
        'completed_at' => null, 'file_name' => 'fa.csv', 'file_path' => 'fa.csv',
        'importer' => FixedAssetImporter::class, 'processed_rows' => 0, 'total_rows' => 1, 'successful_rows' => 0,
        'user_id' => auth()->id(),
    ]);
    $row = ['asset_code' => 'TVP', 'tag' => $fa->tag, 'name' => 'Renamed chiller', 'category' => 'HVAC', 'acquisition_date' => '2026-01-01',
        'acquisition_cost' => '150000', 'opening_accumulated_depreciation' => '0', 'useful_life_months' => '24'];
    $map = collect(array_keys($row))->mapWithKeys(fn ($k) => [$k => $k])->all();
    expect(fn () => (new FixedAssetImporter($import, $map, []))($row))
        ->toThrow(RowImportFailedException::class, __('admin.fixed_assets.errors.transferred_history_locked'));
    expect((float) $fa->fresh()->acquisition_cost)->toBe(130000.0);

    // A guarded field must LOOK guarded: both on-form columns render disabled under the new mall.
    asTenant($this->to, function () use ($fa) {
        $form = Livewire::test(EditFixedAsset::class, ['record' => $fa->getKey()])->instance()->form;
        $fields = collect($form->getFlatComponents())->filter(fn ($c) => $c instanceof Field)->keyBy(fn ($c) => $c->getName());
        expect($fields['acquisition_cost']->isDisabled())->toBeTrue()
            ->and($fields['acquisition_date']->isDisabled())->toBeTrue()
            ->and($fields['name']->isDisabled())->toBeFalse();
    });
});

it('refuses a transfer while a month before it is still uncharged, and a disposal dated before the transfer', function () {
    // Jan and Feb posted, March MISSED (a scheduler that did not run), transfer in April: the OUT
    // leg would carry 20,000 while 30,000 is owed, and a March catch-up after the move would be
    // dimensioned to Nile Gate with no leg ever carrying it across.
    $fa = p18Chiller($this->from->id);
    p18Depreciate(2);
    expect(fn () => p18Transfer($fa, $this->to->id))
        ->toThrow(DomainException::class, __('admin.fixed_assets.errors.transfer_month_uncharged', ['month' => 'March 2026']));

    // Control: post March and the same transfer goes through carrying 30,000.
    app(DepreciationService::class)->run(CarbonImmutable::create(2026, 3, 1));
    $transfer = p18Transfer($fa->fresh(), $this->to->id);
    expect((float) $transfer->accumulated_depreciation)->toBe(30000.0);

    // A disposal cannot be dated before the transfer — it would write the asset off in the
    // property it LEFT, with the receiving property keeping it at cost for ever.
    expect(fn () => app(DisposeFixedAssetService::class)->dispose($fa->fresh(), ['disposed_on' => '2026-03-10', 'proceeds' => 0]))
        ->toThrow(DomainException::class, __('admin.fixed_assets.errors.disposed_before_transfer', ['date' => '15 April 2026']));
    expect($fa->fresh()->status)->toBe('active');

    // Control: on or after the transfer it is disposed of from Val Plaza.
    $disposal = app(DisposeFixedAssetService::class)->dispose($fa->fresh(), ['disposed_on' => '2026-05-10', 'proceeds' => 0]);
    p18Sweep();
    expect((int) p18Entry($disposal)->asset_id)->toBe($this->to->id);

    // A fully-depreciated asset has nothing left to charge, so an uncharged month is no gap;
    // and a cut-over asset's pre-system months are the accountant's opening figure, not a gap.
    $done = p18Chiller($this->from->id, ['tag' => 'FA-DONE', 'acquisition_date' => '2025-01-01', 'useful_life_months' => 12]);
    for ($m = 1; $m <= 12; $m++) {
        app(DepreciationService::class)->run(CarbonImmutable::create(2025, $m, 1));
    }
    expect(app(DepreciationService::class)->firstUnchargedMonthBefore($done->fresh(), CarbonImmutable::parse('2026-04-01')))->toBeNull();
    $cutOver = p18Chiller($this->from->id, ['tag' => 'FA-CUT', 'acquisition_date' => '2023-01-01', 'useful_life_months' => 120, 'is_opening_balance' => true, 'opening_accumulated_depreciation' => 36000]);
    app(DepreciationService::class)->run(CarbonImmutable::create(2026, 1, 1));
    expect(app(DepreciationService::class)->firstUnchargedMonthBefore($cutOver->fresh(), CarbonImmutable::parse('2026-02-01')))->toBeNull()
        ->and(app(DepreciationService::class)->firstUnchargedMonthBefore($cutOver->fresh(), CarbonImmutable::parse('2026-04-01'))?->toDateString())->toBe('2026-02-01');
});

it('scopes a property\'s depreciation run by who HELD the asset that month, not by where it is today', function () {
    $fa = p18Chiller($this->from->id);
    p18Depreciate(2);
    // March is missed; the transfer is refused for it, so post March through the SENDING mall's
    // own scoped run — the "Post this month" button of an operator pinned to Nile Gate.
    expect(app(DepreciationService::class)->run(CarbonImmutable::create(2026, 3, 1), [$this->to->id]))->toBe(0);
    expect(app(DepreciationService::class)->run(CarbonImmutable::create(2026, 3, 1), [$this->from->id]))->toBe(1);

    p18Transfer($fa->fresh(), $this->to->id);

    // April belongs to the receiver: the sender's scoped run writes nothing, the receiver's posts
    // it, and the charge is dimensioned to Val Plaza.
    expect(app(DepreciationService::class)->run(CarbonImmutable::create(2026, 4, 1), [$this->from->id]))->toBe(0);
    expect(app(DepreciationService::class)->run(CarbonImmutable::create(2026, 4, 1), [$this->to->id]))->toBe(1);
    p18Sweep();
    $april = DepreciationEntry::where('fixed_asset_id', $fa->id)->whereDate('period_month', '2026-04-01')->firstOrFail();
    expect((int) p18Entry($april)->asset_id)->toBe($this->to->id);
});

// ── Refusals, each beside the control that must still pass ───────────────────────────────────

it('refuses without a reason, a disposed asset, the same property, the pseudo-property, a future date, and a closed period', function () {
    $fa = p18Chiller($this->from->id);
    p18Depreciate(3);

    expect(fn () => p18Transfer($fa, $this->to->id, reason: '   '))->toThrow(DomainException::class, __('admin.fixed_assets.errors.transfer_reason_required'));
    expect(fn () => p18Transfer($fa, $this->from->id))->toThrow(DomainException::class, __('admin.fixed_assets.errors.transfer_same_property'));
    expect(fn () => p18Transfer($fa, Asset::where('code', Asset::ALL_PROPERTIES_CODE)->firstOrFail()->id))
        ->toThrow(DomainException::class, __('admin.fixed_assets.errors.transfer_property_not_held'));
    expect(fn () => p18Transfer($fa, $this->to->id, '2026-06-16'))->toThrow(DomainException::class, __('admin.posting.errors.future'));

    p18Sweep();
    app(PeriodService::class)->closePeriod(AccountingPeriod::forDate(CarbonImmutable::parse('2026-03-01')));
    expect(fn () => p18Transfer($fa, $this->to->id, '2026-03-15'))->toThrow(DomainException::class, __('admin.posting.errors.period_closed', ['month' => '2026-03']));

    $disposed = p18Chiller($this->from->id, ['tag' => 'FA-DISP']);
    app(DisposeFixedAssetService::class)->dispose($disposed, ['disposed_on' => '2026-05-01', 'proceeds' => 0]);
    expect(fn () => p18Transfer($disposed->fresh(), $this->to->id, '2026-05-15'))->toThrow(DomainException::class, __('admin.fixed_assets.errors.transfer_disposed'));

    // Control: the same asset, a real destination, an open month after everything above.
    expect(p18Transfer($fa->fresh(), $this->to->id, '2026-04-15'))->toBeInstanceOf(FixedAssetTransfer::class);
    expect(FixedAssetTransfer::count())->toBe(1);
});

it('refuses a date in the acquisition month or in a month already depreciated here, and dates chronologically', function () {
    $fa = p18Chiller($this->from->id);
    expect(fn () => p18Transfer($fa, $this->to->id, '2026-01-20'))->toThrow(DomainException::class, __('admin.fixed_assets.errors.transfer_in_acquisition_month', ['month' => 'January 2026', 'from' => '1 February 2026']));

    p18Depreciate(3);
    expect(fn () => p18Transfer($fa, $this->to->id, '2026-03-31'))->toThrow(DomainException::class, __('admin.fixed_assets.errors.transfer_month_already_charged', ['month' => 'March 2026', 'from' => '1 April 2026']));
    expect(fn () => p18Transfer($fa, $this->to->id, '2026-02-10'))->toThrow(DomainException::class);

    p18Transfer($fa, $this->to->id, '2026-04-15');
    expect(fn () => p18Transfer($fa->fresh(), $this->from->id, '2026-04-10', 'back'))->toThrow(DomainException::class, __('admin.fixed_assets.errors.transfer_out_of_order', ['date' => '15 April 2026']));

    // Control: on or after the earlier transfer is fine, even in the same month.
    p18Transfer($fa->fresh(), $this->from->id, '2026-04-20', 'Sent back');
    expect($fa->fresh()->asset_id)->toBe($this->from->id)
        ->and($fa->fresh()->propertyOn(CarbonImmutable::parse('2026-04-01')))->toBe($this->from->id);
});

it('refuses a destination the actor does not hold, and a tag the destination already uses', function () {
    $third = makeAsset(['code' => 'TXX', 'name' => 'Third Mall']);
    $this->actingAs(makeUser('manager', [$this->from->id, $this->to->id]));

    $fa = p18Chiller($this->from->id, ['tag' => 'FA-0007']);
    p18Depreciate(3);
    expect(fn () => p18Transfer($fa, $third->id))->toThrow(DomainException::class, __('admin.fixed_assets.errors.transfer_property_not_held'));

    p18Chiller($this->to->id, ['tag' => 'FA-0007', 'name' => 'Another chiller']);
    expect(fn () => p18Transfer($fa, $this->to->id))->toThrow(DomainException::class, __('admin.fixed_assets.errors.transfer_tag_taken', ['tag' => 'FA-0007', 'property' => 'Val Plaza']));

    // Control: re-tag the other asset, and the held destination takes it.
    FixedAsset::where('asset_id', $this->to->id)->where('tag', 'FA-0007')->update(['tag' => 'FA-0008']);
    expect(p18Transfer($fa, $this->to->id))->toBeInstanceOf(FixedAssetTransfer::class);
});

// ── The doors: the act on the record page, the tab that answers "where did it go" ───────────

it('offers Transfer on the asset\'s own page to whoever holds another property, and follows the asset there', function () {
    $fa = p18Chiller($this->from->id);
    p18Depreciate(3);
    $this->actingAs(makeUser('manager', [$this->from->id, $this->to->id]));

    asTenant($this->from, function () use ($fa) {
        Livewire::test(EditFixedAsset::class, ['record' => $fa->getKey()])
            ->assertActionVisible('transfer')
            ->callAction('transfer', data: ['to_asset_id' => $this->to->id, 'transferred_on' => '2026-04-15', 'reason' => 'Moved to Val Plaza'])
            ->assertHasNoActionErrors()
            ->assertRedirect(FixedAssetResource::getUrl('edit', ['record' => $fa], tenant: $this->to));
    });

    expect($fa->fresh()->asset_id)->toBe($this->to->id)
        ->and(FixedAssetTransfer::where('fixed_asset_id', $fa->id)->count())->toBe(1);

    // A manager holding ONE mall has nowhere to move it: no button, rather than a modal with
    // nothing to pick. The service refuses the same actor the same way.
    $pinned = makeUser('manager', [$this->to->id]);
    $this->actingAs($pinned);
    asTenant($this->to, function () use ($fa) {
        Livewire::test(EditFixedAsset::class, ['record' => $fa->getKey()])->assertActionHidden('transfer');
    });
    expect(fn () => p18Transfer($fa->fresh(), $this->from->id, '2026-05-15'))->toThrow(DomainException::class, __('admin.fixed_assets.errors.transfer_property_not_held'));
});

it('lists the transfer on the asset\'s Transfers tab, with the property it came from and went to', function () {
    // The tab is on the asset's own page — a manager written and never registered lists nothing.
    expect(FixedAssetResource::getRelations())->toContain(FixedAssetTransfersRelationManager::class);

    $fa = p18Chiller($this->from->id);
    p18Depreciate(3);
    $transfer = p18Transfer($fa, $this->to->id);

    asTenant($this->to, function () use ($fa, $transfer) {
        Livewire::test(FixedAssetTransfersRelationManager::class, ['ownerRecord' => $fa->fresh(), 'pageClass' => EditFixedAsset::class])
            ->assertCanSeeTableRecords([$transfer])
            ->assertSee('Nile Gate')
            ->assertSee('Val Plaza')
            ->assertSee('Chiller moved to the Val Plaza plant room');
    });
});
