<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\CamExpensePools\Pages\CreateCamExpensePool;
use App\Filament\Admin\Resources\CamExpensePools\Pages\EditCamExpensePool;
use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Services\CamReconciliationService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * A POOL'S ADDRESS AND ITS STAGE ARE NOT FIELDS AN OPERATOR TYPES.
 *
 * Two holes in one form, closed together because they are the same mistake — a column that only a
 * derivation or an ACT is entitled to write, offered as an input.
 *
 * **The year (SW-161).** Every recovery-basis field on `CamExpensePoolForm` is disabled once an
 * allocation has been billed, and the model's `updating` guard refuses the same five columns
 * behind them. `period_year` had neither, and it is the pool's ADDRESS: `generateAllocations()`
 * builds its 1 Jan → 31 Dec period from it and weights every tenure over that window, the billed
 * estimate subtracts that year's invoices, and the recovery invoice the tenant is holding names it.
 * Retyping it after billing re-keys a year of history under a heading the documents do not match.
 * `asset_id` and `pool_code` are the same question and travel with it in
 * `CamExpensePool::IDENTITY_COLUMNS`.
 *
 * **The stage (SW-162).** `status` was a free Select over all four values, so anyone holding
 * `cam.edit` could write `reconciled` straight onto the row, skipping both halves of
 * `CamExpensePoolActions::markReconciled` — the `cam.mark_reconciled` permission, and
 * `assertReadyToReconcile()`, which refuses to close a year while a tenant's share is unbilled.
 * Typed back to `draft` it re-opens `canGenerate()` on a pool that has already invoiced its shares.
 *
 * **The bar is BILLING, not calculating** — the 2026-08-31 decision recorded in
 * `CamReconciliationService::generateAllocations()`. A pool whose allocations are all still
 * `pending` may be corrected freely, because there is no way back otherwise: `void` refuses a
 * pending allocation and the pool cannot be deleted while it has any. The control cases below are
 * what pin that half, and they are the ones a stricter fix would break.
 */
afterEach(function (): void {
    Filament::setTenant(null, isQuiet: true);
    CarbonImmutable::setTestNow();
});

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2029-01-15');

    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->asset = makeAsset(['code' => 'CAM-IDENT', 'leasable_area_sqm' => 200]);
    $this->lease = makeLease(
        makeUnit($this->asset, ['area_sqm' => 100]),
        null,
        ['status' => 'active', 'commencement_date' => '2027-01-01', 'expiry_date' => '2032-12-31'],
    )->fresh();

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    $this->pool = CamExpensePool::create([
        'asset_id' => $this->asset->id,
        'period_year' => 2028,
        'pool_code' => CamExpensePool::CODE_CAM,
        'status' => 'reconciling',
        'denominator_basis' => CamExpensePool::DENOMINATOR_OCCUPIED,
        'expense_basis' => CamExpensePool::BASIS_STATED,
        'estimate_basis' => CamExpensePool::BASIS_STATED,
        'total_actual_expense' => 100_000,
        'total_estimated_collected' => 0,
        'recovery_vat_rate' => 14,
    ]);

    app(CamReconciliationService::class)->generateAllocations($this->pool);

    // Posting the batch is what freezes the pool. Written as an UPDATE rather than through
    // `billAllocation()` so the fixture states the one fact under test and nothing else.
    $this->post = fn () => CamAllocation::query()
        ->where('cam_expense_pool_id', $this->pool->id)
        ->update(['status' => 'billed']);
});

it('refuses to move a pool into another year once a share has been billed', function (): void {
    ($this->post)();

    expect(fn () => $this->pool->fresh()->update(['period_year' => 2029]))
        ->toThrow(DomainException::class, __('admin.refusals.cam_pool_identity_locked_after_billing'));

    expect((int) $this->pool->fresh()->period_year)->toBe(2028);
});

it('refuses to re-home a billed pool onto another mall', function (): void {
    ($this->post)();

    $other = makeAsset(['code' => 'CAM-OTHER']);

    expect(fn () => $this->pool->fresh()->update(['asset_id' => $other->id]))
        ->toThrow(DomainException::class, __('admin.refusals.cam_pool_identity_locked_after_billing'));

    expect((int) $this->pool->fresh()->asset_id)->toBe($this->asset->id);
});

it('still lets the year be corrected while every share is only pending', function (): void {
    // THE CONTROL, and the one that matters most: an "any allocation exists" bar would pass every
    // refusal above and make a mistyped year on the first run unrecoverable from the panel.
    $this->pool->fresh()->update(['period_year' => 2029]);

    expect((int) $this->pool->fresh()->period_year)->toBe(2029);
});

it('disables the year on the form once a share has been billed', function (): void {
    Livewire::test(EditCamExpensePool::class, ['record' => $this->pool->getKey()])
        ->assertFormFieldEnabled('period_year');

    ($this->post)();

    Livewire::test(EditCamExpensePool::class, ['record' => $this->pool->getKey()])
        ->assertFormFieldDisabled('period_year');
});

it('will not take a status typed into the edit form', function (): void {
    Livewire::test(EditCamExpensePool::class, ['record' => $this->pool->getKey()])
        ->set('data.status', 'reconciled')
        // The control rides in the same call: an ordinary field must really be saved, or a test
        // that measures nothing (a halted save, a 403) would read exactly like a pass.
        ->set('data.notes', 'Keyed in the same submit as the status.')
        ->call('save')
        ->assertHasNoFormErrors();

    $pool = $this->pool->fresh();

    expect($pool->status)->toBe('reconciling')
        ->and($pool->reconciled_at)->toBeNull()
        ->and($pool->notes)->toBe('Keyed in the same submit as the status.');
});

it('will not create a pool that is already reconciled', function (): void {
    Livewire::test(CreateCamExpensePool::class)
        ->fillForm([
            'period_year' => 2031,
            'pool_code' => CamExpensePool::CODE_CAM,
            'status' => 'reconciled',
            'participant_scope' => CamExpensePool::PARTICIPANTS_ALL,
            'denominator_basis' => CamExpensePool::DENOMINATOR_OCCUPIED,
            'expense_basis' => CamExpensePool::BASIS_STATED,
            'estimate_basis' => CamExpensePool::BASIS_STATED,
            'total_actual_expense' => 10_000,
            'total_estimated_collected' => 0,
            'recovery_vat_rate' => 14,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(CamExpensePool::query()
        ->where('asset_id', $this->asset->id)
        ->where('period_year', 2031)
        ->sole()
        ->status)->toBe('draft');
});

it('still moves the status through the act that owns it', function (): void {
    ($this->post)();

    Livewire::test(EditCamExpensePool::class, ['record' => $this->pool->getKey()])
        ->callAction('markReconciled');

    // The other control: taking the field away must not take the workflow with it.
    expect($this->pool->fresh()->status)->toBe('reconciled')
        ->and($this->pool->fresh()->reconciled_at)->not->toBeNull();
});
