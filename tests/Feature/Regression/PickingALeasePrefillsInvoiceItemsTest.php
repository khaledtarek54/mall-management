<?php

use App\Filament\Admin\Resources\Invoices\Pages\CreateInvoice;
use App\Models\Charge;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * **Picking a lease on the invoice form must not 500.**
 *
 * THE BUG. `InvoiceForm::prefillItemsFromLease()` maps the lease's charges into invoice lines, and
 * the mapper reached for `$get('issue_date')` to resolve each charge's VAT rate for the document's
 * date. PHP closures do not inherit the enclosing scope, and the closure had no `use ($get)` — so
 * on PHP 8 that is an `ErrorException: Undefined variable $get`, and the whole Livewire update
 * returned 500. Selecting a lease is the FIRST thing an operator does when raising a manual
 * invoice, so the form was unusable from its first interaction.
 *
 * It shipped in `72c2c007` (the VAT-rise fix) and survived a green suite because every existing
 * test of that behaviour calls the SERVICE. Nothing drove the form callback, which is the only
 * place the missing `use` exists. It was found by clicking the control in a browser.
 *
 * Two things this file therefore does deliberately:
 *
 *  1. It drives the real Livewire page and `->set('data.lease_id', …)` — the reachable input — so
 *     the callback actually runs. Asserting on `prefillItemsFromLease()` directly would re-create
 *     the blind spot, because a static call passes `$get` explicitly.
 *  2. It asserts the RESULT, not just the absence of an exception. A callback that silently
 *     returned early would satisfy "no 500" while leaving the operator an empty invoice, and that
 *     is the same class of quiet failure.
 *
 * The broader finding is recorded rather than fixed here: `phpstan.neon` excludes
 * `app/Filament/*​/Resources/*​/Schemas/*`, which is exactly where form callbacks live — so static
 * analysis could not have caught this either. The stated reason for the exclusion is that those
 * files are "exercised by the E2E suite", and that suite is advisory and not part of the push loop.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->asset = makeAsset(['code' => 'PREFILL']);
    $this->tenant = makeTenant(['name' => 'Prefill Retail']);
    $this->lease = makeLease(makeUnit($this->asset, ['code' => 'P-01']), $this->tenant);

    // Two recurring charges and one that must NOT be prefilled, so the assertion below is about
    // the rule rather than about "some rows appeared".
    Charge::create([
        'lease_id' => $this->lease->id, 'type' => 'base_rent', 'name' => 'Base rent',
        'amount' => 10000, 'frequency' => 'monthly', 'is_active' => true, 'start_date' => '2026-01-01',
    ]);
    Charge::create([
        'lease_id' => $this->lease->id, 'type' => 'service_charge', 'name' => 'Service charge',
        'amount' => 2000, 'frequency' => 'monthly', 'is_active' => true, 'start_date' => '2026-01-01',
    ]);
    Charge::create([
        'lease_id' => $this->lease->id, 'type' => 'service_charge', 'name' => 'Retired line',
        'amount' => 999, 'frequency' => 'monthly', 'is_active' => false, 'start_date' => '2026-01-01',
    ]);

    $this->actingAs(makeUser('manager', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('prefills the invoice lines when a lease is picked, instead of erroring', function () {
    $page = Livewire::test(CreateInvoice::class)
        ->assertOk()
        ->set('data.issue_date', '2026-03-10')
        // The reachable input: this is what the operator's click sends, and it is what fires
        // `afterStateUpdated` → `prefillItemsFromLease()`.
        ->set('data.lease_id', $this->lease->id)
        ->assertOk()
        ->assertHasNoFormErrors();

    $items = $page->get('data')['items'] ?? [];

    // The active recurring charges became lines…
    expect($items)->toHaveCount(2)
        ->and(collect($items)->pluck('description')->all())
        ->toEqualCanonicalizing(['Base rent', 'Service charge'])
        // …the inactive one did not…
        ->and(collect($items)->pluck('description')->all())->not->toContain('Retired line')
        // …and each line carries the amount and a resolved rate, which is the part that needed
        // `$get('issue_date')` and therefore the part that was erroring.
        ->and(collect($items)->firstWhere('description', 'Base rent')['amount'])->toEqual(10000.0)
        ->and(collect($items)->firstWhere('description', 'Base rent'))->toHaveKey('vat_rate');

    // The tenant is copied off the lease in the same callback — a control that the whole handler
    // ran to the end rather than dying somewhere quieter.
    expect($page->get('data')['tenant_id'])->toEqual($this->tenant->id);
});
