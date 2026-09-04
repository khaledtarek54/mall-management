<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\CamExpensePools\Pages\EditCamExpensePool;
use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Services\CamReconciliationService;
use App\Services\SyncCamPoolFromLedgerService;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * SW-221 — A POOL WHOSE SHARES ARE BILLED SAYS SO BEFORE YOU PRESS SYNC.
 *
 * `SyncCamPoolFromLedgerService::sync()` refused only `reconciled|closed`, while
 * `CamExpensePoolActions::canGenerate()` keeps the Sync button live on `draft|reconciling` — and
 * what actually freezes the two totals is neither status but BILLING (`hasBilledAllocations()`,
 * the 2026-08-31 decision). So on a `reconciling` pool with one billed allocation the write fell
 * through to `CamExpensePool::booted()` and came back as
 * `admin.refusals.cam_basis_locked_after_billing`: a sentence written for the EDIT FORM ("the CAM
 * recovery basis cannot change…"), raised by a button that had given no sign it would not work.
 *
 * **And it was intermittent, which is why nobody met it.** A re-sync producing the SAME two figures
 * leaves neither column dirty, so the model guard never fired and the button appeared to work.
 * SW-135 (unit owners' assessments counted into the billed estimate) and SW-216 (credit notes
 * netted off it) both MOVE that number, so the first sync after either shipped is the one that
 * refuses — on a pool that had been synced happily for a year.
 *
 * The refusal itself is deliberate and is NOT loosened here: this service's own docblock says a
 * bill arriving in March for December must not silently restate allocations already billed to
 * tenants. What changed is that it names the ACT and the way out, and the button is disabled with
 * that same sentence as its tooltip — the split `markReconciled` beside it already uses.
 */
afterEach(function (): void {
    Filament::setTenant(null, isQuiet: true);
    CarbonImmutable::setTestNow();
});

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2029-01-15');

    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    ensureAllPropertiesAsset();

    $this->asset = makeAsset(['code' => 'CAM-SYNC', 'leasable_area_sqm' => 200]);
    makeLease(
        makeUnit($this->asset, ['area_sqm' => 100]),
        null,
        ['status' => 'active', 'commencement_date' => '2027-01-01', 'expiry_date' => '2032-12-31'],
    );

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    $expense = LedgerAccount::query()->where('is_postable', true)->where('type', 'expense')->firstOrFail();
    $bank = LedgerAccount::query()->where('is_postable', true)->where('type', 'asset')->firstOrFail();

    // Built the way the product builds one: lines onto a DRAFT, then posted. Hand-crafting lines
    // onto an already-posted entry is a state production cannot reach and `JournalLine` refuses.
    $entry = JournalEntry::create([
        'entry_date' => '2028-05-15',
        'description_en' => 'Cleaning contractor',
        'status' => 'draft',
        'asset_id' => $this->asset->id,
        'is_manual' => true,
    ]);
    JournalLine::create([
        'journal_entry_id' => $entry->id, 'ledger_account_id' => $expense->id,
        'debit' => 500000, 'credit' => 0, 'asset_id' => $this->asset->id,
    ]);
    JournalLine::create([
        'journal_entry_id' => $entry->id, 'ledger_account_id' => $bank->id,
        'debit' => 0, 'credit' => 500000, 'asset_id' => $this->asset->id,
    ]);
    $entry->update(['status' => 'posted']);

    $this->pool = CamExpensePool::create([
        'asset_id' => $this->asset->id,
        'period_year' => 2028,
        'pool_code' => CamExpensePool::CODE_CAM,
        'status' => 'reconciling',
        'denominator_basis' => CamExpensePool::DENOMINATOR_OCCUPIED,
        'expense_basis' => CamExpensePool::BASIS_LEDGER,
        'estimate_basis' => CamExpensePool::BASIS_STATED,
        'total_actual_expense' => 0,
        'total_estimated_collected' => 0,
        'recovery_vat_rate' => 14,
    ]);
    $this->pool->ledgerAccounts()->attach($expense->id);

    app(CamReconciliationService::class)->generateAllocations($this->pool);

    // Posting the batch is what freezes the pool. Written as an UPDATE rather than through
    // `billAllocation()` so the fixture states the one fact under test and nothing else.
    $this->bill = fn () => CamAllocation::query()
        ->where('cam_expense_pool_id', $this->pool->id)
        ->update(['status' => 'billed']);
});

it('refuses to re-source a pool whose shares have already been billed', function (): void {
    ($this->bill)();

    expect(fn () => app(SyncCamPoolFromLedgerService::class)->sync($this->pool->fresh()))
        ->toThrow(DomainException::class, __('admin.refusals.cam_sync_locked_after_billing'));

    // Nothing was written on the way to the refusal.
    expect((float) $this->pool->fresh()->total_actual_expense)->toBe(0.0)
        ->and($this->pool->fresh()->expense_synced_at)->toBeNull();
});

it('refuses even when the ledger has not moved — which is what used to make it silent', function (): void {
    // THE CASE THE MODEL GUARD CANNOT SEE. With the stored figure already equal to the ledger's,
    // `forceFill()` leaves `total_actual_expense` clean, `$basisChanged` is false and
    // `CamExpensePool::booted()` never fires — so before this fix the sync SUCCEEDED, stamped
    // `expense_synced_at`, and reported success on a frozen pool.
    $this->pool->update(['total_actual_expense' => 500000]);

    ($this->bill)();

    expect(fn () => app(SyncCamPoolFromLedgerService::class)->sync($this->pool->fresh()))
        ->toThrow(DomainException::class, __('admin.refusals.cam_sync_locked_after_billing'));

    expect($this->pool->fresh()->expense_synced_at)->toBeNull();
});

it('still re-sources a pool whose shares are all still pending', function (): void {
    // THE CONTROL, and the one a stricter fix would break: the bar is BILLING, not calculating. A
    // pool whose allocations are all `pending` must stay correctable, because `void` refuses a
    // pending allocation and the pool cannot be deleted while it has any.
    $result = app(SyncCamPoolFromLedgerService::class)->sync($this->pool->fresh());

    expect($result['expense'])->toBe(500000.0)
        ->and((float) $this->pool->fresh()->total_actual_expense)->toBe(500000.0)
        ->and($this->pool->fresh()->expense_synced_at)->not->toBeNull();
});

it('shows the Sync button disabled, with the reason, once a share is billed', function (): void {
    ($this->bill)();

    Livewire::test(EditCamExpensePool::class, ['record' => $this->pool->getKey()])
        // VISIBLE and disabled, not hidden: `assertActionDisabled` passes for a hidden action too
        // (`isDisabled()` is true when `isHidden()` is), so without this line a "fix" that simply
        // took the button away would read as a pass.
        ->assertActionVisible('syncFromLedger')
        ->assertActionDisabled('syncFromLedger');
});

it('leaves the Sync button pressable while nothing has been billed', function (): void {
    Livewire::test(EditCamExpensePool::class, ['record' => $this->pool->getKey()])
        ->assertActionVisible('syncFromLedger')
        ->assertActionEnabled('syncFromLedger');
});
