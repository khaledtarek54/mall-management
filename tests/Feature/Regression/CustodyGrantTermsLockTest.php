<?php

use App\Models\Custody;
use App\Models\Employee;
use App\Services\GrantCustodyService;
use App\Services\SettleCustodyService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * A عهدة's grant terms are fixed once it has been settled against — and its custodian is fixed
 * from the moment it is granted.
 *
 * THE GAP (module 25 close-out, 2026-08-11). Both rules are stated in the module doc as facts —
 * *"the custodian is fixed at grant (locked on edit) so the books dimension can't drift"* and
 * *"grant terms lock once settled — amount / date / paid-from become read-only once the custody has
 * any settlement (editing them would misstate outstanding)"*. Both were `->disabled()` on
 * `CustodyForm` and nothing else. `Custody::booted()` carried only the NOT-NULL amount coercion.
 *
 * The doc's own parenthesis is the failure scenario. Outstanding is DERIVED —
 * `amount − Σ(settlements)` — so lowering `amount` under what has already been settled makes
 * outstanding NEGATIVE: the register shows a custodian owing money that was never granted to them.
 * And the grant's journal entry (Dr Custodies / Cr Cash|Bank) re-derives at the new figure while
 * the settlements' credits do not move, so Custodies stops netting to zero as the عهدة is spent.
 *
 * Changing `paid_from` after the fact re-derives WHICH account was credited, after the cash has
 * already left that account. Changing the custodian moves `asset_id` with it — the books dimension
 * the doc says is protected — so a settled عهدة's entries land in another property.
 *
 * Fixed at the model, which is where the two rules the doc already states belong, and which covers
 * the import / console / API / future-screen paths the form never could. Same finding, same shape
 * and same fix as module 23's disposed-asset freeze.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->asset = makeAsset(['code' => 'CUS1']);
    $this->employee = Employee::create([
        'asset_id' => $this->asset->id, 'code' => 'E-1', 'name' => 'Mahmoud Fahmy',
        'hire_date' => '2026-01-01', 'status' => 'active',
        'base_salary' => 8000, 'payment_method' => 'bank',
    ]);

    $this->custody = app(GrantCustodyService::class)->grant($this->employee, [
        'amount' => 10000,
        'custody_date' => '2026-06-01',
        'paid_from' => 'cash',
        'purpose' => 'Site petty cash',
    ]);
});

it('refuses to move the grant amount below what has already been settled', function () {
    app(SettleCustodyService::class)->settle($this->custody->fresh(), [
        'type' => 'expense', 'amount' => 7000, 'category' => 'maintenance',
        'transaction_date' => '2026-06-10', 'description' => 'Filters',
    ]);

    // Outstanding is 3,000. Dropping the grant to 2,000 would make it −5,000.
    expect(fn () => $this->custody->fresh()->update(['amount' => 2000]))
        ->toThrow(DomainException::class);

    expect(round((float) $this->custody->fresh()->amount, 2))->toBe(10000.0);
});

it('refuses to change the grant terms at all once it has been settled against', function () {
    app(SettleCustodyService::class)->settle($this->custody->fresh(), [
        'type' => 'expense', 'amount' => 1000, 'category' => 'maintenance',
        'transaction_date' => '2026-06-10', 'description' => 'Filters',
    ]);

    // paid_from decides which account the grant CREDITED — after the cash has left it.
    expect(fn () => $this->custody->fresh()->update(['paid_from' => 'bank']))
        ->toThrow(DomainException::class);

    // custody_date is the grant entry's GL date.
    expect(fn () => $this->custody->fresh()->update(['custody_date' => '2026-05-01']))
        ->toThrow(DomainException::class);
});

it('allows the grant terms to be corrected while nothing has been settled', function () {
    // The control, and a real need: a عهدة keyed at the wrong figure must be fixable before it
    // is spent against. The rule is "locks once SETTLED", not "locks on grant".
    expect(fn () => $this->custody->fresh()->update(['amount' => 12000]))
        ->not->toThrow(DomainException::class);

    expect(round((float) $this->custody->fresh()->amount, 2))->toBe(12000.0);
});

it('fixes the custodian from the moment of the grant', function () {
    // asset_id is denormalised from the custodian, so moving them moves the books dimension —
    // which the doc says is exactly what fixing the custodian protects.
    $other = Employee::create([
        'asset_id' => $this->asset->id, 'code' => 'E-2', 'name' => 'Sara Nabil',
        'hire_date' => '2026-01-01', 'status' => 'active',
        'base_salary' => 9000, 'payment_method' => 'bank',
    ]);

    expect(fn () => $this->custody->fresh()->update(['employee_id' => $other->id]))
        ->toThrow(DomainException::class);
});

it('still allows the annotations an operator needs after the fact', function () {
    // The other control: purpose and reference carry no money and no GL dimension, so freezing
    // them would block an operator recording what the عهدة turned out to be for.
    app(SettleCustodyService::class)->settle($this->custody->fresh(), [
        'type' => 'expense', 'amount' => 1000, 'category' => 'maintenance',
        'transaction_date' => '2026-06-10', 'description' => 'Filters',
    ]);

    expect(fn () => $this->custody->fresh()->update([
        'purpose' => 'Site petty cash — north wing fit-out',
        'reference' => 'CUS-2026-014',
    ]))->not->toThrow(DomainException::class);
});

it('keeps outstanding derived, so it can never be written directly', function () {
    // The property that makes all of the above matter: outstanding has no column. If one is ever
    // added, this fails and the guards above stop being the whole story.
    expect(in_array('outstanding', (new Custody)->getFillable(), true))->toBeFalse()
        ->and(\Illuminate\Support\Facades\Schema::hasColumn('custodies', 'outstanding'))->toBeFalse();
});
