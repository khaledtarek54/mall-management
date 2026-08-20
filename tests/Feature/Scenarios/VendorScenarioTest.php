<?php

/*
|--------------------------------------------------------------------------
| Vendors + Vendor contracts — lifecycle, command idempotency, scoping
|--------------------------------------------------------------------------
| NET-NEW vs tests/Feature/Console/ExpireVendorContractsCommandTest.php
| (which only covers: one active-past-end is expired, --dry-run is read-only,
| clean-exit when nothing matches). Here we pin down the parts that test left
| open:
|   - STATE TRANSITION: the command touches ONLY status=active rows past
|     end_date. draft / expired / terminated / null-end_date are inert; an
|     already-expired run is IDEMPOTENT (second run finds nothing).
|   - BOUNDARY: the cut is strict `< today` — a contract ending today (or
|     tomorrow) survives; one that ended yesterday flips.
|   - SCOPING: VendorContract carries asset_id. A restricted operator pinned
|     to property A sees A's contracts via TenantScope but never B's, and the
|     audit-fixed asset picker in the ContractsRelationManager offers A only —
|     a foreign property's row is leak-free. The nav badge counts only the
|     scoped property's expiring contracts.
|   - VALIDATION: the contract form rejects a negative value (minValue 0).
|
| We drive the real command, the real TenantScope helpers, and the real
| Filament relation-manager form — not just model writes.
*/

use App\Filament\Admin\Resources\Vendors\Pages\EditVendor;
use App\Filament\Admin\Resources\Vendors\RelationManagers\ContractsRelationManager;
use App\Filament\Admin\Resources\Vendors\VendorResource;
use App\Models\Asset;
use App\Models\Vendor;
use App\Models\VendorContract;
use App\Support\TenantScope;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

afterEach(function () {
    Filament::setTenant(null, isQuiet: true);
    Carbon::setTestNow();
});

/** A vendor with a stable, unique slug. */
function makeVendor(array $attrs = []): Vendor
{
    return Vendor::create(array_merge([
        'name' => 'Vendor '.uniqid(),
        'slug' => 'vendor-'.uniqid(),
        'type' => 'service_provider',
        'status' => 'active',
    ], $attrs));
}

/** A contract row; caller supplies vendor + asset + the dates/status under test. */
function makeContract(Vendor $vendor, ?Asset $asset, array $attrs = []): VendorContract
{
    return VendorContract::create(array_merge([
        'vendor_id' => $vendor->id,
        'asset_id' => $asset?->id,
        'name' => 'Contract '.uniqid(),
        'status' => 'active',
        'start_date' => '2025-01-01',
        'end_date' => '2026-01-01',
        'currency' => 'EGP',
    ], $attrs));
}

/* =========================================================================
 | State transition — the command only touches active rows past end_date
 ========================================================================= */

describe('expire-contracts state transitions', function () {
    beforeEach(function () {
        ensureAllPropertiesAsset();
        Carbon::setTestNow('2026-06-01'); // "today"
        $this->asset = makeAsset();
        $this->vendor = makeVendor();
    });

    it('leaves a DRAFT contract untouched even when it is past its end_date', function () {
        $draft = makeContract($this->vendor, $this->asset, [
            'status' => 'draft',
            'end_date' => '2026-04-30', // ended in the past...
        ]);

        $this->artisan('vendors:expire-contracts')
            ->expectsOutputToContain('No active vendor contracts past their end_date.')
            ->assertExitCode(0);

        // ...but draft is not "active", so the command leaves it alone.
        expect($draft->fresh()->status)->toBe('draft');
    });

    it('leaves a TERMINATED contract untouched (terminal, not active)', function () {
        $terminated = makeContract($this->vendor, $this->asset, [
            'status' => 'terminated',
            'end_date' => '2026-04-30',
        ]);

        $this->artisan('vendors:expire-contracts')->assertExitCode(0);

        expect($terminated->fresh()->status)->toBe('terminated');
    });

    it('does not touch an active contract whose end_date is NULL (open-ended)', function () {
        $openEnded = makeContract($this->vendor, $this->asset, [
            'status' => 'active',
            'end_date' => null,
        ]);

        $this->artisan('vendors:expire-contracts')
            ->expectsOutputToContain('No active vendor contracts past their end_date.')
            ->assertExitCode(0);

        expect($openEnded->fresh()->status)->toBe('active');
    });

    it('is IDEMPOTENT — a second run finds nothing and the row stays expired', function () {
        $contract = makeContract($this->vendor, $this->asset, [
            'status' => 'active',
            'end_date' => '2026-04-30',
        ]);

        // First run flips it.
        $this->artisan('vendors:expire-contracts')
            ->expectsOutputToContain('Expired 1 vendor contract')
            ->assertExitCode(0);
        expect($contract->fresh()->status)->toBe('expired');

        // Second run: the now-expired row is no longer "active", so it's not a
        // candidate — the command reports nothing and does not re-process it.
        $this->artisan('vendors:expire-contracts')
            ->expectsOutputToContain('No active vendor contracts past their end_date.')
            ->assertExitCode(0);
        expect($contract->fresh()->status)->toBe('expired');
    });

    it('expires several at once and reports the exact count, sparing in-window rows', function () {
        $a = makeContract($this->vendor, $this->asset, ['status' => 'active', 'end_date' => '2026-01-15']);
        $b = makeContract($this->vendor, $this->asset, ['status' => 'active', 'end_date' => '2026-05-31']);
        $live = makeContract($this->vendor, $this->asset, ['status' => 'active', 'end_date' => '2026-12-31']);

        $this->artisan('vendors:expire-contracts')
            ->expectsOutputToContain('Expired 2 vendor contract')
            ->assertExitCode(0);

        expect($a->fresh()->status)->toBe('expired')
            ->and($b->fresh()->status)->toBe('expired')
            ->and($live->fresh()->status)->toBe('active');
    });
});

/* =========================================================================
 | Boundary — the cut is strict `end_date < today`
 ========================================================================= */

describe('expire-contracts end_date boundary', function () {
    beforeEach(function () {
        ensureAllPropertiesAsset();
        Carbon::setTestNow('2026-06-01');
        $this->asset = makeAsset();
        $this->vendor = makeVendor();
    });

    it('does NOT expire a contract ending exactly today (boundary: < today, not <=)', function () {
        $endsToday = makeContract($this->vendor, $this->asset, [
            'status' => 'active',
            'end_date' => '2026-06-01', // == today
        ]);

        $this->artisan('vendors:expire-contracts')
            ->expectsOutputToContain('No active vendor contracts past their end_date.')
            ->assertExitCode(0);

        expect($endsToday->fresh()->status)->toBe('active');
    });

    it('does NOT expire a contract ending tomorrow (still in window)', function () {
        $endsTomorrow = makeContract($this->vendor, $this->asset, [
            'status' => 'active',
            'end_date' => '2026-06-02',
        ]);

        $this->artisan('vendors:expire-contracts')->assertExitCode(0);

        expect($endsTomorrow->fresh()->status)->toBe('active');
    });

    it('DOES expire a contract that ended yesterday', function () {
        $endedYesterday = makeContract($this->vendor, $this->asset, [
            'status' => 'active',
            'end_date' => '2026-05-31',
        ]);

        $this->artisan('vendors:expire-contracts')
            ->expectsOutputToContain('Expired 1 vendor contract')
            ->assertExitCode(0);

        expect($endedYesterday->fresh()->status)->toBe('expired');
    });
});

/* =========================================================================
 | Scoping — VendorContract.asset_id + TenantScope + the audit-fixed picker
 ========================================================================= */

describe('vendor contract property scoping', function () {
    beforeEach(function () {
        $this->all = ensureAllPropertiesAsset();
        $this->a = makeAsset(['code' => 'AAA', 'name' => 'Alpha Mall']);
        $this->b = makeAsset(['code' => 'BBB', 'name' => 'Beta Mall']);
        $this->vendor = makeVendor(['name' => 'Cross-Property HVAC']);

        $this->contractA = makeContract($this->vendor, $this->a, ['name' => 'Alpha HVAC', 'status' => 'active']);
        $this->contractB = makeContract($this->vendor, $this->b, ['name' => 'Beta HVAC', 'status' => 'active']);
    });

    it('a manager pinned to property A sees A\'s contract but never B\'s via TenantScope', function () {
        $manager = makeUser('manager', [$this->a->id]);
        $this->actingAs($manager);

        asTenant($this->a, function () {
            // The way the nav badge + any scoped vendor-contract list filter:
            // VendorContract::query()->where('asset_id', currentAssetId()).
            $query = TenantScope::applyTo(VendorContract::query());
            $ids = $query->pluck('id')->all();

            expect(TenantScope::currentAssetId())->toBe($this->a->id)
                ->and($ids)->toContain($this->contractA->id)
                ->and($ids)->not->toContain($this->contractB->id);
        });
    });

    it('the asset picker offers only A to a manager pinned to A — B is leak-free', function () {
        $this->seed(RolesPermissionsSeeder::class);
        $manager = makeUser('manager', [$this->a->id]);
        $this->actingAs($manager);

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->a);

        $options = Livewire::test(ContractsRelationManager::class, [
            'ownerRecord' => $this->vendor,
            'pageClass' => EditVendor::class,
        ])
            ->mountTableAction('create')
            ->instance()
            ->getMountedTableActionForm()
            ->getComponent('asset_id')
            ->getOptions();

        expect($options)->toHaveKey($this->a->id)            // pinned property → offered
            ->and($options)->not->toHaveKey($this->b->id)    // foreign property → hidden
            ->and($options)->not->toHaveKey($this->all->id); // synthetic ALL row → excluded
    });

    it('offers BOTH real properties to super_admin in the All-Properties view', function () {
        // In All-Properties mode currentAssetId() is null, so the picker is
        // enabled and unconstrained for super_admin — every real property is
        // selectable, the synthetic ALL row is still excluded.
        $this->seed(RolesPermissionsSeeder::class);
        $this->actingAs(makeUser('super_admin'));

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->all);

        $assetField = Livewire::test(ContractsRelationManager::class, [
            'ownerRecord' => $this->vendor,
            'pageClass' => EditVendor::class,
        ])
            ->mountTableAction('create')
            ->instance()
            ->getMountedTableActionForm()
            ->getComponent('asset_id');

        expect($assetField->getOptions())->toHaveKey($this->a->id)
            ->and($assetField->getOptions())->toHaveKey($this->b->id)
            ->and($assetField->getOptions())->not->toHaveKey($this->all->id)
            ->and($assetField->isDisabled())->toBeFalse(); // free to pick a property
    });

    it('locks the asset picker to the pinned property when a real tenant is active', function () {
        // When scoped to a single property the picker is disabled and defaulted
        // to that property — the contract cannot be filed against another mall.
        $this->seed(RolesPermissionsSeeder::class);
        $this->actingAs(makeUser('super_admin'));

        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->a);

        $assetField = Livewire::test(ContractsRelationManager::class, [
            'ownerRecord' => $this->vendor,
            'pageClass' => EditVendor::class,
        ])
            ->mountTableAction('create')
            ->instance()
            ->getMountedTableActionForm()
            ->getComponent('asset_id');

        expect($assetField->isDisabled())->toBeTrue()                 // locked to current property
            ->and($assetField->getState())->toBe($this->a->id)        // defaulted to A
            ->and($assetField->getOptions())->not->toHaveKey($this->b->id); // B never offered
    });

    it('the nav badge counts only the scoped property\'s soon-expiring contracts', function () {
        Carbon::setTestNow('2026-06-01');

        // One contract on each property expiring within the 30-day window.
        $soonA = makeContract($this->vendor, $this->a, ['status' => 'active', 'end_date' => '2026-06-20']);
        $soonB = makeContract($this->vendor, $this->b, ['status' => 'active', 'end_date' => '2026-06-20']);

        $manager = makeUser('manager', [$this->a->id]);
        $this->actingAs($manager);

        // Pinned to A: badge counts A's expiring contract only (1), not B's.
        asTenant($this->a, function () {
            expect(VendorResource::getNavigationBadge())->toBe('1');
        });
    });
});

/* =========================================================================
 | Validation — the contract form rejects a negative value
 ========================================================================= */

describe('vendor contract form validation', function () {
    beforeEach(function () {
        $this->seed(RolesPermissionsSeeder::class);
        ensureAllPropertiesAsset();
        $this->asset = makeAsset();
        $this->vendor = makeVendor();
    });

    it('rejects a negative contract value (minValue 0) and writes nothing', function () {
        $this->actingAs(makeUser('super_admin'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->asset);

        Livewire::test(ContractsRelationManager::class, [
            'ownerRecord' => $this->vendor,
            'pageClass' => EditVendor::class,
        ])
            ->callTableAction('create', data: [
                'name' => 'Underpriced Deal',
                'status' => 'draft',
                'asset_id' => $this->asset->id,
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'value' => -500, // illegal
            ])
            ->assertHasTableActionErrors(['value']);

        expect(VendorContract::where('name', 'Underpriced Deal')->exists())->toBeFalse();
    });

    it('accepts a valid contract through the relation-manager form', function () {
        $this->actingAs(makeUser('super_admin'));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->asset);

        Livewire::test(ContractsRelationManager::class, [
            'ownerRecord' => $this->vendor,
            'pageClass' => EditVendor::class,
        ])
            ->callTableAction('create', data: [
                'name' => 'Annual Cleaning',
                'status' => 'active',
                'asset_id' => $this->asset->id,
                'start_date' => '2026-01-01',
                'end_date' => '2026-12-31',
                'value' => 120000,
            ])
            ->assertHasNoTableActionErrors();

        $contract = VendorContract::where('name', 'Annual Cleaning')->first();
        expect($contract)->not->toBeNull()
            ->and($contract->vendor_id)->toBe($this->vendor->id)
            ->and($contract->asset_id)->toBe($this->asset->id)
            ->and((float) $contract->value)->toBe(120000.0)
            ->and($contract->status)->toBe('active');
    });
});
