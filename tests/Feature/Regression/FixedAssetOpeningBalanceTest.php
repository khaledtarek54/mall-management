<?php

use App\Filament\Admin\Resources\FixedAssets\FixedAssetResource;
use App\Filament\Imports\FixedAssetImporter;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\Accounting\FiscalCalendar;
use App\Services\DepreciationService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Validation\ValidationException;

/**
 * A mall arrives owning chillers bought years ago. Loading them must not invent money.
 *
 * The fixed-asset register feeds depreciation and the balance sheet from day one, so this is the
 * importer with the most immediate accounting consequence — and two things go wrong if a legacy
 * asset is created as an ordinary one:
 *
 *  - **The acquisition posts.** `Dr Furniture & Equipment / Cr Cash` dated 2023, double-counting
 *    cost the accountant's opening journal entry already carries — or refused for landing in a
 *    closed period and stranded inside the best-effort sync job.
 *  - **It depreciates from zero.** `accumulatedFor()` sums `depreciation_entries`, and a legacy
 *    asset has none, so a chiller three years into a ten-year life charges its FULL cost again over
 *    another ten years while the balance sheet carries it at cost.
 *
 * The second fix had a trap of its own: **accumulated depreciation was computed in FOUR places** —
 * `DepreciationService`, `FixedAssetDisposalJournalizer` (its own copy, for gain-or-loss on sale),
 * and a SQL `withSum` feeding both the table and the register CSV. Teaching one about the opening
 * figure and not the others would have posted a wrong gain on every legacy asset ever sold, and
 * reported every imported asset at cost on the balance-sheet schedule.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $this->asset = makeAsset(['code' => 'MALL']);

    $this->import = Import::create([
        'completed_at' => null,
        'file_name' => 'fixed-assets.csv',
        'file_path' => 'fixed-assets.csv',
        'importer' => FixedAssetImporter::class,
        'processed_rows' => 0,
        'total_rows' => 1,
        'successful_rows' => 0,
        'user_id' => User::factory()->create()->id,
    ]);
});

function importFixedAssetRow(array $row): void
{
    $columnMap = collect(array_keys($row))->mapWithKeys(fn ($k) => [$k => $k])->all();

    (new FixedAssetImporter(test()->import, $columnMap, []))($row);
}

/** A chiller bought three years ago, 360,000 of a 1,200,000 cost already written off. */
function importLegacyChiller(array $overrides = []): FixedAsset
{
    importFixedAssetRow(array_merge([
        'asset_code' => 'MALL',
        'tag' => 'CH-001',
        'name' => 'Chiller 1',
        'acquisition_date' => '2023-08-01',
        'acquisition_cost' => '1200000',
        'opening_accumulated_depreciation' => '360000',
        'useful_life_months' => '120',
    ], $overrides));

    return FixedAsset::sole();
}

it('loads a legacy asset with the depreciation it has already taken', function () {
    $chiller = importLegacyChiller();

    expect((float) $chiller->acquisition_cost)->toBe(1200000.0)
        ->and($chiller->accumulatedDepreciation())->toBe(360000.0)
        ->and($chiller->is_opening_balance)->toBeTrue();
});

it('posts NOTHING to the general ledger', function () {
    // The cost is already in the accountant's opening entry. Posting it again would double the
    // fixed-asset balance and credit cash that never moved.
    $chiller = importLegacyChiller();
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    $posted = JournalEntry::query()
        ->where('source_type', $chiller->getMorphClass())
        ->where('source_id', $chiller->id)
        ->where('status', 'posted')
        ->exists();

    expect($posted)->toBeFalse();
});

it('still posts a NORMAL acquisition — the paired control', function () {
    // Suppression must be scoped to the import. An asset the operator actually buys through the
    // form is a real purchase and must still hit the books, or this "fix" quietly stops the
    // fixed-asset module posting at all.
    $bought = FixedAsset::create([
        'asset_id' => $this->asset->id,
        'name' => 'New generator', 'tag' => 'GEN-9',
        'acquisition_date' => now()->toDateString(),
        'acquisition_cost' => 50000, 'useful_life_months' => 60,
        'status' => 'active', 'funded_from' => 'bank',
    ]);

    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    expect(JournalEntry::query()
        ->where('source_type', $bought->getMorphClass())
        ->where('source_id', $bought->id)
        ->where('status', 'posted')
        ->exists())->toBeTrue();
});

it('reports the right net book value instead of carrying it at cost', function () {
    $chiller = importLegacyChiller();

    // 1,200,000 − 360,000. Before the opening column this read 1,200,000.
    expect(app(DepreciationService::class)->netBookValue($chiller))->toBe(840000.0);
});

it('depreciates only what is LEFT, not the whole cost again', function () {
    $chiller = importLegacyChiller();

    // 1,200,000 over 120 months = 10,000 a month. Seven years of life remain, so the charge
    // continues at 10,000 — but it must stop after the remaining 840,000, not run a fresh 1.2m.
    $service = app(DepreciationService::class);
    $service->run(CarbonImmutable::now()->startOfMonth());

    $fresh = $chiller->fresh();

    expect($fresh->accumulatedDepreciation())->toBe(370000.0)
        ->and($service->netBookValue($fresh))->toBe(830000.0);
});

it('agrees with the SQL the table and the register CSV read', function () {
    // The fourth reader. `FixedAssetResource::getEloquentQuery()` computes `accumulated` in SQL
    // because the table sorts on it, so it is a second expression of one rule — and this is the
    // only thing keeping the two equal.
    $chiller = importLegacyChiller();

    $viaSql = FixedAssetResource::getEloquentQuery()->whereKey($chiller->id)->first();

    expect(round((float) $viaSql->accumulated, 2))->toBe($chiller->accumulatedDepreciation());
});

it('measures a disposal against the opening write-off too', function () {
    // `FixedAssetDisposalJournalizer` had its OWN sum of the entries. On a legacy asset that made
    // the carrying amount look like full cost, so selling a written-down chiller booked a large
    // phantom LOSS.
    $chiller = importLegacyChiller();

    expect($chiller->accumulatedDepreciation())
        ->toBe(app(DepreciationService::class)->accumulatedFor($chiller));
});

it('is idempotent — re-running the file updates rather than duplicating', function () {
    importLegacyChiller();
    importLegacyChiller(['name' => 'Chiller 1 (main)']);

    expect(FixedAsset::count())->toBe(1)
        ->and(FixedAsset::sole()->name)->toBe('Chiller 1 (main)');
});

it('keys on the property AND the tag, because two malls both have a CH-001', function () {
    $other = makeAsset(['code' => 'OTHER']);
    importLegacyChiller();
    importFixedAssetRow([
        'asset_code' => 'OTHER', 'tag' => 'CH-001', 'name' => 'Their chiller',
        'acquisition_date' => '2024-01-01', 'acquisition_cost' => '900000',
        'opening_accumulated_depreciation' => '90000', 'useful_life_months' => '120',
    ]);

    expect(FixedAsset::count())->toBe(2)
        ->and(FixedAsset::where('asset_id', $other->id)->sole()->tag)->toBe('CH-001');
});

it('requires the depreciation-to-date figure rather than assuming zero', function () {
    // "The operator did not say" and "nothing has been depreciated" are different answers, and only
    // one of them is safe to assume. A silent zero is the version nobody notices for a year.
    // Mapped but EMPTY — which is what a spreadsheet with the column left blank actually sends.
    // Omitting the key entirely would leave the column unmapped, and Filament does not validate a
    // column nobody mapped, so that version of this test would pass for the wrong reason.
    expect(fn () => importFixedAssetRow([
        'asset_code' => 'MALL', 'tag' => 'CH-002', 'name' => 'Chiller 2',
        'acquisition_date' => '2023-08-01', 'acquisition_cost' => '1200000',
        'opening_accumulated_depreciation' => '',
        'useful_life_months' => '120',
    ]))->toThrow(ValidationException::class);

    expect(FixedAsset::count())->toBe(0);
});

it('refuses a row for a property the importer cannot see', function () {
    // An import bypasses the Create/Edit pages, which are the only place `assertAssetInScope()`
    // runs. Without the clamp a restricted user writes to another mall's books.
    //
    // A real operator restricted to MALL — not a mock. `visibleAssetIds()` reads the signed-in
    // user's assigned set, so faking it would prove nothing about the clamp.
    $hidden = makeAsset(['code' => 'HIDDEN']);
    auth()->login(makeUser('manager', [$this->asset->id]));

    // The row is SKIPPED, not thrown on: `resolveRecord()` returns null and Filament drops it —
    // the same mechanism `LeaseImporter` uses. Asserted as "nothing was written", which is the
    // security property; expecting an exception here would be asserting the implementation.
    importFixedAssetRow([
        'asset_code' => 'HIDDEN', 'tag' => 'X-1', 'name' => 'Not theirs',
        'acquisition_date' => '2023-01-01', 'acquisition_cost' => '1000',
        'opening_accumulated_depreciation' => '0', 'useful_life_months' => '12',
    ]);

    expect(FixedAsset::where('asset_id', $hidden->id)->count())->toBe(0)
        ->and(FixedAsset::count())->toBe(0);
});

it('still imports the property that operator CAN see — the paired control', function () {
    // Without this, the clamp could be refusing everything and the test above would still pass.
    auth()->login(makeUser('manager', [$this->asset->id]));

    importLegacyChiller();

    expect(FixedAsset::where('asset_id', $this->asset->id)->count())->toBe(1);
});
