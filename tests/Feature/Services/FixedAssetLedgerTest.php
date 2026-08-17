<?php

use App\Models\DepreciationEntry;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Services\Accounting\LedgerReportService;
use App\Services\DepreciationService;
use App\Services\DisposeFixedAssetService;
use App\Services\Reconciliation\BooksReconciliationService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $this->poster = app(LedgerPoster::class);
    $this->accounts = app(AccountResolver::class);
});

/** A fixed asset on a fresh property, acquired at the start of the current year. */
function faLedgerAsset(array $attrs = []): FixedAsset
{
    return FixedAsset::create(array_merge([
        'asset_id' => makeAsset()->id,
        'name' => 'Chiller',
        'tag' => 'FA-'.uniqid(),
        'acquisition_date' => now()->startOfYear()->toDateString(),
        'acquisition_cost' => 12000,
        'salvage_value' => 0,
        'useful_life_months' => 12,
        'method' => 'straight_line',
        'funded_from' => 'cash',
    ], $attrs));
}

/** Post depreciation for the first N months of the year (each = 1 charge/asset). */
function faDepreciate(int $months): void
{
    for ($m = 0; $m < $months; $m++) {
        app(DepreciationService::class)->run(now()->startOfYear()->addMonths($m));
    }
}

/* ---- Acquisition --------------------------------------------------------- */

it('journalizes an acquisition as Dr Furniture & Equipment / Cr Cash', function () {
    $fa = faLedgerAsset(['acquisition_cost' => 12000, 'funded_from' => 'cash']);

    $entry = $this->poster->post($fa);

    expect($entry)->not->toBeNull();
    expect($entry->isBalanced())->toBeTrue();
    expect((int) $entry->asset_id)->toBe($fa->asset_id); // dimensioned to the property

    $byAccount = $entry->lines->keyBy('ledger_account_id');
    expect((float) $byAccount[$this->accounts->id('furniture_equipment')]->debit)->toEqualWithDelta(12000.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('cash')]->credit)->toEqualWithDelta(12000.0, 0.001);
    // Must NOT touch the AP control account (that would break the GL↔AP tie-out).
    expect($byAccount->has($this->accounts->id('accounts_payable')))->toBeFalse();
});

it('credits the bank when the asset is funded from bank', function () {
    $fa = faLedgerAsset(['funded_from' => 'bank']);

    $entry = $this->poster->post($fa);
    $byAccount = $entry->lines->keyBy('ledger_account_id');

    expect($byAccount->has($this->accounts->id('bank')))->toBeTrue();
    expect($byAccount->has($this->accounts->id('cash')))->toBeFalse();
});

it('skips a zero-cost asset (no GL effect)', function () {
    $fa = faLedgerAsset(['acquisition_cost' => 0]);

    expect($this->poster->post($fa))->toBeNull();
});

it('is idempotent — re-posting the same acquisition does not double-book', function () {
    $fa = faLedgerAsset();

    $first = $this->poster->post($fa);
    $second = $this->poster->post($fa);

    expect($second->id)->toBe($first->id);
});

/* ---- Depreciation charge ------------------------------------------------- */

it('journalizes a depreciation entry as Dr Depreciation Expense / Cr Accumulated Depreciation', function () {
    $fa = faLedgerAsset(['acquisition_cost' => 12000, 'useful_life_months' => 12]); // 1000/mo
    app(DepreciationService::class)->run(now()); // one entry, 1000

    $charge = DepreciationEntry::where('fixed_asset_id', $fa->id)->firstOrFail();
    $entry = $this->poster->post($charge);

    expect($entry)->not->toBeNull();
    expect($entry->isBalanced())->toBeTrue();
    expect((int) $entry->asset_id)->toBe($fa->asset_id);

    $byAccount = $entry->lines->keyBy('ledger_account_id');
    expect((float) $byAccount[$this->accounts->id('depreciation_expense')]->debit)->toEqualWithDelta(1000.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('accumulated_depreciation')]->credit)->toEqualWithDelta(1000.0, 0.001);
    // Neither line touches AR/AP — tie-out-safe.
    expect($byAccount->has($this->accounts->id('accounts_receivable')))->toBeFalse();
    expect($byAccount->has($this->accounts->id('accounts_payable')))->toBeFalse();
});

it('skips a zero-amount depreciation entry (no GL effect)', function () {
    $fa = faLedgerAsset();
    $charge = $fa->depreciationEntries()->create(['period_month' => now()->startOfMonth()->toDateString(), 'amount' => 0]);

    expect($this->poster->post($charge))->toBeNull();
});

/* ---- Void on delete ------------------------------------------------------ */

/** Assert every listed chart account's closing balance is ~0. */
function faExpectAllZero(array $codes): void
{
    foreach ($codes as $code) {
        $account = LedgerAccount::where('code', $code)->first();
        $statement = app(LedgerReportService::class)->accountLedger($account);
        expect($statement['closing'])->toEqualWithDelta(0.0, 0.001, "account {$code} should net to zero");
    }
}

it('voids the acquisition and depreciation entries when the asset is soft-deleted', function () {
    $fa = faLedgerAsset(['acquisition_cost' => 12000, 'useful_life_months' => 12]);
    app(DepreciationService::class)->run(now()); // 1000 charge
    $charge = DepreciationEntry::where('fixed_asset_id', $fa->id)->firstOrFail();

    // Post both.
    expect($this->poster->sync($fa->fresh()))->not->toBeNull();
    expect($this->poster->sync($charge->fresh()))->not->toBeNull();

    // Delete the register row (super-admin correction) — the cascade soft-deletes the
    // charge too, so its whole GL footprint voids.
    $fa->delete();

    expect($this->poster->sync(FixedAsset::withTrashed()->find($fa->id)))->toBeNull();          // acquisition voided
    expect($this->poster->sync(DepreciationEntry::withTrashed()->find($charge->id)))->toBeNull(); // depreciation voided

    faExpectAllZero(['12101001', '11101001', '51107001', '12201001']);
});

it('voids AGED depreciation entries through the WINDOWED sweep after a soft-delete (regression)', function () {
    // The bug: soft-deleting the parent did not bump the (older) charge rows'
    // updated_at, so the daily windowed sweep (updated_at >= now-2d) never re-visited
    // them → phantom depreciation lingered. The cascade must bring them back in-window.
    $fa = faLedgerAsset(['acquisition_cost' => 12000, 'useful_life_months' => 12]);
    app(DepreciationService::class)->run(now());
    $charge = DepreciationEntry::where('fixed_asset_id', $fa->id)->firstOrFail();

    // Post both, then age the charge well outside the sweep's 2-day window.
    $this->poster->sync($fa->fresh());
    $this->poster->sync($charge->fresh());
    DB::table('depreciation_entries')->where('id', $charge->id)->update(['updated_at' => now()->subDays(30)]);

    $fa->delete(); // cascade re-touches the charge → back inside the window

    // Run the ACTUAL windowed sweep (no --all), exactly like the scheduled task.
    $this->artisan('accounting:sync-ledger')->assertExitCode(0);

    faExpectAllZero(['12101001', '11101001', '51107001', '12201001']);
});

it('restores the depreciation charges when a soft-deleted asset is restored', function () {
    $fa = faLedgerAsset(['acquisition_cost' => 12000, 'useful_life_months' => 12]);
    app(DepreciationService::class)->run(now());
    $charge = DepreciationEntry::where('fixed_asset_id', $fa->id)->firstOrFail();

    $fa->delete();
    expect(DepreciationEntry::find($charge->id))->toBeNull();          // cascade-trashed
    expect(DepreciationEntry::withTrashed()->find($charge->id)->trashed())->toBeTrue();

    $fa->restore();
    expect(DepreciationEntry::find($charge->id))->not->toBeNull();     // brought back
});

it('restores ONLY the cascade-trashed charges, not one removed independently', function () {
    // Two monthly charges. One is removed on its own (a future per-entry correction);
    // then the whole asset is deleted + restored. The restore must bring back only the
    // charge the asset's own delete cascaded — not resurrect the independently-removed
    // one (which would silently re-inflate accumulated depreciation).
    $fa = faLedgerAsset(['acquisition_cost' => 12000, 'useful_life_months' => 12]);
    app(DepreciationService::class)->run(now()->subMonthNoOverflow());
    app(DepreciationService::class)->run(now());
    $charges = DepreciationEntry::where('fixed_asset_id', $fa->id)->orderBy('period_month')->get();
    expect($charges)->toHaveCount(2);
    [$chargeA, $chargeB] = [$charges[0], $charges[1]];

    // Independently remove charge A, back-dated so its deleted_at can't collide.
    trashBypassingDeletionPolicy($chargeA);
    DB::table('depreciation_entries')->where('id', $chargeA->id)->update(['deleted_at' => now()->subDays(5)]);

    $fa->delete();   // cascade-trashes only the still-live charge B (with the parent's deleted_at)
    $fa->restore();  // must restore only B

    expect(DepreciationEntry::find($chargeB->id))->not->toBeNull();       // B is back
    expect(DepreciationEntry::find($chargeA->id))->toBeNull();            // A stays removed
    expect(DepreciationEntry::withTrashed()->find($chargeA->id)->trashed())->toBeTrue();
});

it('re-dimensions the depreciation entries when the asset is re-homed to another property', function () {
    $propA = makeAsset(['code' => 'RHA']);
    $propB = makeAsset(['code' => 'RHB']);
    $fa = faLedgerAsset(['asset_id' => $propA->id, 'acquisition_cost' => 12000, 'useful_life_months' => 12]);
    app(DepreciationService::class)->run(now());
    $charge = DepreciationEntry::where('fixed_asset_id', $fa->id)->firstOrFail();

    // Post both on property A, then age the charge outside the window.
    $this->poster->sync($fa->fresh());
    $this->poster->sync($charge->fresh());
    DB::table('depreciation_entries')->where('id', $charge->id)->update(['updated_at' => now()->subDays(30)]);

    $fa->update(['asset_id' => $propB->id]); // re-home → hook touches the charge

    $this->artisan('accounting:sync-ledger')->assertExitCode(0);

    // Both the acquisition and the depreciation entry now sit on property B.
    $chargeEntry = JournalEntry::where('source_type', $charge->getMorphClass())
        ->where('source_id', $charge->id)->where('status', 'posted')->latest('id')->first();
    expect((int) $chargeEntry->asset_id)->toBe($propB->id);
});

/* ---- Disposal write-off (Phase 2b) --------------------------------------- */

it('journalizes a disposal write-off with a loss (proceeds below net book value)', function () {
    $fa = faLedgerAsset(['acquisition_cost' => 12000, 'useful_life_months' => 12]);
    faDepreciate(3); // accumulated 3000 → NBV 9000
    $disposal = app(DisposeFixedAssetService::class)->dispose($fa, ['disposed_on' => now()->toDateString(), 'proceeds' => 0]);

    $entry = $this->poster->post($disposal);

    expect($entry)->not->toBeNull();
    expect($entry->isBalanced())->toBeTrue();
    expect((int) $entry->asset_id)->toBe($fa->asset_id);

    $byAccount = $entry->lines->keyBy('ledger_account_id');
    expect((float) $byAccount[$this->accounts->id('accumulated_depreciation')]->debit)->toEqualWithDelta(3000.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('furniture_equipment')]->credit)->toEqualWithDelta(12000.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('loss_on_disposal')]->debit)->toEqualWithDelta(9000.0, 0.001);
    expect($byAccount->has($this->accounts->id('gain_on_disposal')))->toBeFalse();
    expect($byAccount->has($this->accounts->id('cash')))->toBeFalse(); // no proceeds
    // Tie-out-safe: never touches AR/AP.
    expect($byAccount->has($this->accounts->id('accounts_receivable')))->toBeFalse();
    expect($byAccount->has($this->accounts->id('accounts_payable')))->toBeFalse();
});

it('journalizes a disposal with proceeds and a gain (sold above net book value)', function () {
    $fa = faLedgerAsset(['acquisition_cost' => 12000, 'useful_life_months' => 12]);
    faDepreciate(2); // accumulated 2000 → NBV 10000
    $disposal = app(DisposeFixedAssetService::class)->dispose($fa, [
        'disposed_on' => now()->toDateString(), 'proceeds' => 12000, 'proceeds_account' => 'bank',
    ]);

    $entry = $this->poster->post($disposal);
    expect($entry->isBalanced())->toBeTrue();

    $byAccount = $entry->lines->keyBy('ledger_account_id');
    expect((float) $byAccount[$this->accounts->id('accumulated_depreciation')]->debit)->toEqualWithDelta(2000.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('bank')]->debit)->toEqualWithDelta(12000.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('furniture_equipment')]->credit)->toEqualWithDelta(12000.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('gain_on_disposal')]->credit)->toEqualWithDelta(2000.0, 0.001); // 12000 − 10000
    expect($byAccount->has($this->accounts->id('loss_on_disposal')))->toBeFalse();
});

it('writes off a fully depreciated asset with no gain or loss', function () {
    $fa = faLedgerAsset(['acquisition_cost' => 12000, 'useful_life_months' => 1]);
    faDepreciate(1); // accumulated 12000 → NBV 0
    $disposal = app(DisposeFixedAssetService::class)->dispose($fa, ['disposed_on' => now()->toDateString(), 'proceeds' => 0]);

    $entry = $this->poster->post($disposal);
    expect($entry->isBalanced())->toBeTrue();

    $byAccount = $entry->lines->keyBy('ledger_account_id');
    expect((float) $byAccount[$this->accounts->id('accumulated_depreciation')]->debit)->toEqualWithDelta(12000.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('furniture_equipment')]->credit)->toEqualWithDelta(12000.0, 0.001);
    expect($byAccount->has($this->accounts->id('gain_on_disposal')))->toBeFalse();
    expect($byAccount->has($this->accounts->id('loss_on_disposal')))->toBeFalse();
});

it('respects salvage value on disposal (NBV floors at salvage)', function () {
    // cost 12000, salvage 2000, life 10 → 1000/mo, accumulated caps at 10000 (cost − salvage).
    $fa = faLedgerAsset(['acquisition_cost' => 12000, 'salvage_value' => 2000, 'useful_life_months' => 10]);
    faDepreciate(10); // accumulated 10000 → NBV = 2000 (= salvage)
    $disposal = app(DisposeFixedAssetService::class)->dispose($fa, ['disposed_on' => now()->toDateString(), 'proceeds' => 0]);

    $entry = $this->poster->post($disposal);
    expect($entry->isBalanced())->toBeTrue();

    $byAccount = $entry->lines->keyBy('ledger_account_id');
    expect((float) $byAccount[$this->accounts->id('accumulated_depreciation')]->debit)->toEqualWithDelta(10000.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('furniture_equipment')]->credit)->toEqualWithDelta(12000.0, 0.001);
    expect((float) $byAccount[$this->accounts->id('loss_on_disposal')]->debit)->toEqualWithDelta(2000.0, 0.001); // the un-depreciated salvage
});

it('nets Furniture and Accumulated Depreciation to zero after acquire + depreciate + dispose', function () {
    $fa = faLedgerAsset(['acquisition_cost' => 12000, 'useful_life_months' => 12]);
    faDepreciate(3); // accumulated 3000
    $this->poster->sync($fa->fresh()); // acquisition
    DepreciationEntry::where('fixed_asset_id', $fa->id)->get()->each(fn ($c) => $this->poster->sync($c->fresh()));
    $disposal = app(DisposeFixedAssetService::class)->dispose($fa, ['disposed_on' => now()->toDateString(), 'proceeds' => 0]);
    $this->poster->sync($disposal->fresh());

    // Furniture: +12000 −12000 = 0; Accumulated: −3000 +3000 = 0.
    faExpectAllZero(['12101001', '12201001']);
    // The loss (= NBV 9000) lands on the P&L; the depreciation expense 3000 stays (real).
    $loss = LedgerAccount::where('code', '52102001')->first();
    expect(app(LedgerReportService::class)->accountLedger($loss)['closing'])->toEqualWithDelta(9000.0, 0.001);
});

it('voids the disposal entry through the WINDOWED sweep when the asset is soft-deleted', function () {
    $fa = faLedgerAsset(['acquisition_cost' => 12000, 'useful_life_months' => 12]);
    faDepreciate(1);
    $disposal = app(DisposeFixedAssetService::class)->dispose($fa, ['disposed_on' => now()->toDateString(), 'proceeds' => 0]);

    $this->poster->sync($fa->fresh());
    DepreciationEntry::where('fixed_asset_id', $fa->id)->get()->each(fn ($c) => $this->poster->sync($c->fresh()));
    $this->poster->sync($disposal->fresh());
    // Age the disposal outside the sweep window — the delete cascade must re-touch it.
    DB::table('fixed_asset_disposals')->where('id', $disposal->id)->update(['updated_at' => now()->subDays(30)]);

    $fa->delete();
    $this->artisan('accounting:sync-ledger')->assertExitCode(0);

    // The asset's entire GL footprint (acquisition, depreciation, disposal) nets to zero.
    faExpectAllZero(['12101001', '11101001', '51107001', '12201001', '52102001', '42102001']);
});

it('does not strand Furniture when a disposed asset is re-costed (cascade covers the disposal)', function () {
    // The register UI blocks editing a disposed asset (terminal); this proves the MODEL
    // cascade keeps the GL consistent even if acquisition_cost changes by any path — the
    // disposal's Furniture credit must re-derive alongside the acquisition debit.
    $fa = faLedgerAsset(['acquisition_cost' => 12000, 'useful_life_months' => 12]);
    faDepreciate(3);
    $this->poster->sync($fa->fresh());
    DepreciationEntry::where('fixed_asset_id', $fa->id)->get()->each(fn ($c) => $this->poster->sync($c->fresh()));
    $disposal = app(DisposeFixedAssetService::class)->dispose($fa, ['disposed_on' => now()->toDateString(), 'proceeds' => 0]);
    $this->poster->sync($disposal->fresh());

    // Age the disposal outside the sweep window, then change the cost.
    DB::table('fixed_asset_disposals')->where('id', $disposal->id)->update(['updated_at' => now()->subDays(30)]);
    $fa->update(['acquisition_cost' => 20000]); // updated() hook must bump the disposal too

    $this->artisan('accounting:sync-ledger')->assertExitCode(0);

    // Acquisition Dr 20000 is offset by the re-derived disposal Cr 20000 → Furniture = 0.
    faExpectAllZero(['12101001']);
});

it('marks the asset disposed, records the row, and rejects a second disposal (terminal)', function () {
    $fa = faLedgerAsset();
    $disposal = app(DisposeFixedAssetService::class)->dispose($fa, [
        'disposed_on' => now()->toDateString(), 'proceeds' => 500, 'proceeds_account' => 'bank',
    ]);

    expect($fa->fresh()->status)->toBe('disposed');
    expect((float) $disposal->proceeds)->toBe(500.0);
    expect($disposal->proceeds_account)->toBe('bank');

    expect(fn () => app(DisposeFixedAssetService::class)->dispose($fa->fresh(), ['disposed_on' => now()->toDateString()]))
        ->toThrow(HttpException::class);
});

/* ---- Tie-out safety (the GRNI-class regression) -------------------------- */

it('keeps the GL↔AR/AP tie-out balanced after posting fixed-asset entries', function () {
    // AR baseline: an issued invoice.
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset())));
    $this->poster->sync($invoice->fresh());

    // AP baseline: an approved vendor bill.
    $bill = VendorBill::create([
        'vendor_id' => Vendor::factory()->create()->id, 'asset_id' => makeAsset()->id,
        'category' => 'utilities', 'status' => 'approved', 'bill_date' => now()->toDateString(),
        'subtotal' => 2000, 'vat_amount' => 0, 'total' => 2000, 'balance' => 2000,
    ]);
    $this->poster->sync($bill->fresh());

    // Now post a fixed-asset acquisition (Dr Furniture / Cr Cash) and a depreciation
    // charge (Dr Depr Exp / Cr Accum) — neither may inflate the AR/AP control accounts.
    $fa = faLedgerAsset(['acquisition_cost' => 12000, 'useful_life_months' => 12]);
    app(DepreciationService::class)->run(now());
    $this->poster->sync($fa->fresh());
    $this->poster->sync(DepreciationEntry::where('fixed_asset_id', $fa->id)->firstOrFail()->fresh());

    $tie = app(BooksReconciliationService::class)->glTieOut();
    expect($tie['ar']['delta'])->toBe(0.0);
    expect($tie['ap']['delta'])->toBe(0.0);
    expect($tie['ap']['gl'])->toBe(2000.0); // AP still just the vendor bill

    $report = app(BooksReconciliationService::class)->run();
    expect(collect($report['checks'])->firstWhere('key', 'gl_tie_out')['passed'])->toBeTrue();
});
