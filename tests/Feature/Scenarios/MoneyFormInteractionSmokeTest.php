<?php

use App\Filament\Admin\Resources\CamExpensePools\Pages\CreateCamExpensePool;
use App\Filament\Admin\Resources\CreditNotes\Pages\CreateCreditNote;
use App\Filament\Admin\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Admin\Resources\FacilityWorkOrders\Pages\CreateFacilityWorkOrder;
use App\Filament\Admin\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Admin\Resources\Payments\Pages\CreatePayment;
use App\Filament\Admin\Resources\PostDatedCheques\Pages\CreatePostDatedCheque;
use App\Filament\Admin\Resources\TenantRequests\Pages\CreateTenantRequest;
use App\Filament\Admin\Resources\Units\Pages\CreateUnit;
use App\Filament\Admin\Resources\VendorBills\Pages\CreateVendorBill;
use App\Models\Area;
use App\Models\CamExpensePool;
use App\Models\Charge;
use App\Models\Equipment;
use App\Models\TaxCode;
use App\Models\Vendor;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Database\Seeders\TaxCodeSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * **The money forms' LIVE callbacks must survive being driven, not just rendered.**
 *
 * WHY THIS EXISTS, and why it is separate from `ResourceFormSmokeTest`. That file mounts every
 * resource Create form and proves the schema builds. It would NOT have caught the bug that prompted
 * both files: `InvoiceForm::prefillItemsFromLease()` shipped with a closure missing `use ($get)` —
 * an `Undefined variable` on PHP 8 — and it fired from `afterStateUpdated`, i.e. only once an
 * operator picked a lease. The page mounted perfectly. The first click 500'd, for five days, on the
 * primary path for raising a manual invoice.
 *
 * A schema is code that runs in two phases and the second one is the dangerous one: `->live()`
 * callbacks prefill lines, derive due dates, suggest allocations and recompute totals. They touch
 * money, they run only in a browser, and this suite otherwise drives SERVICES.
 *
 * ## Deliberately a smoke test, not a behaviour suite
 *
 * Each case sets the ONE field that drives the cascade and asserts the component survived and did
 * something. What each callback computes is owned by the module's own tests (`BillingScenarioTest`,
 * `PaymentScenarioTest`, `CreditNoteScenarioTest`); duplicating those here would be a second copy
 * to keep in step. The property being pinned is narrower and was the one nothing held: **this code
 * path executes at all.**
 *
 * The forms chosen are the ones where a 500 is most expensive — every document that moves money or
 * lands in the general ledger — plus the two cascades reworked on 2026-08-17 (the tenant-request
 * unit suggestion and the unit form's property → zone reset), which are new code and therefore the
 * least proven.
 *
 * There is no generic sweep of every `->live()` field, and that is a decision rather than an
 * omission. Most of these callbacks open with `if (! $state) return;` — the invoice one did — so a
 * sweep that set every live field to null would run the guard clause, touch nothing, and report
 * coverage it does not have. Driving a cascade needs a REAL value, and a real value needs a fixture.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    // The tax catalogue: the expense form derives its VAT from it, and without the rows the
    // cascade runs, resolves nothing and leaves a zero — a green test over a dead branch.
    $this->seed(TaxCodeSeeder::class);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->asset = makeAsset(['code' => 'MF']);
    $this->tenant = makeTenant(['name' => 'Money Form Retail']);
    $this->lease = makeLease(makeUnit($this->asset, ['code' => 'M-01']), $this->tenant);

    Charge::create([
        'lease_id' => $this->lease->id, 'type' => 'base_rent', 'name' => 'Base rent',
        'amount' => 5000, 'frequency' => 'monthly', 'is_active' => true, 'start_date' => '2026-01-01',
    ]);

    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('survives picking a lease on the invoice form', function () {
    // The exact interaction that 500'd. `->set()` rather than `fillForm()`: a fillForm hands every
    // field over at once and can skip the cascade, which is how the original bug hid.
    $page = Livewire::test(CreateInvoice::class)
        ->set('data.issue_date', '2026-04-10')
        ->set('data.lease_id', $this->lease->id)
        ->assertOk()
        ->assertHasNoFormErrors();

    $data = $page->get('data');

    // It ran to the end rather than dying somewhere quiet: the debtor and the lines both came from
    // the lease.
    expect((int) $data['tenant_id'])->toBe((int) $this->tenant->id)
        ->and($data['items'])->not->toBeEmpty();
});

it('survives picking a tenant and an amount on the payment form', function () {
    makeInvoice($this->lease);

    Livewire::test(CreatePayment::class)
        ->set('data.tenant_id', $this->tenant->id)   // suggests allocations
        ->set('data.amount', 1500)                    // re-suggests them against the new figure
        ->assertOk()
        ->assertHasNoFormErrors();
});

it('survives picking a tenant then an invoice on the credit-note form', function () {
    $invoice = makeInvoice($this->lease);

    Livewire::test(CreateCreditNote::class)
        ->set('data.tenant_id', $this->tenant->id)   // narrows the invoice + lease pickers
        ->set('data.invoice_id', $invoice->id)        // prefills the lines from the invoice
        ->assertOk()
        ->assertHasNoFormErrors();
});

it('survives picking a tenant on the post-dated cheque form', function () {
    makeInvoice($this->lease);

    Livewire::test(CreatePostDatedCheque::class)
        ->set('data.tenant_id', $this->tenant->id)   // narrows the invoice picker
        ->assertOk()
        ->assertHasNoFormErrors();
});

it('survives picking a vendor on the vendor-bill form', function () {
    Vendor::create(['name' => 'Money Form Supplier', 'type' => 'service_provider', 'status' => 'active']);

    Livewire::test(CreateVendorBill::class)
        ->set('data.vendor_id', Vendor::query()->value('id'))  // narrows contract + purchase pickers
        ->assertOk()
        ->assertHasNoFormErrors();
});

it('survives picking a tax code on the expense form', function () {
    // The cascade derives VAT from the catalogue for the DOCUMENT's date and re-totals — three
    // `$get`s and a support class, in a closure, on a document that posts to the GL.
    $page = Livewire::test(CreateExpense::class)
        ->set('data.expense_date', '2026-04-05')
        ->set('data.amount', 1000)
        ->set('data.tax_code', TaxCode::query()->where('direction', TaxCode::PURCHASES)->value('code'))
        ->assertOk()
        ->assertHasNoFormErrors();

    // It reached the end: the catalogue answered, and the total picked the tax up.
    expect((float) $page->get('data')['vat_amount'])->toBeGreaterThan(0)
        ->and((float) $page->get('data')['total'])->toBeGreaterThan(1000);
});

it('survives picking equipment on the work-order form', function () {
    $equipment = Equipment::create([
        'asset_id' => $this->asset->id, 'code' => 'ESC-99',
        'name_en' => 'Escalator', 'name_ar' => 'سلم', 'criticality' => Equipment::CRITICAL,
    ]);

    // Picking the machine re-grades the priority from its criticality — on create only.
    $page = Livewire::test(CreateFacilityWorkOrder::class)
        ->set('data.asset_id', $this->asset->id)
        ->set('data.equipment_id', $equipment->id)
        ->assertOk()
        ->assertHasNoFormErrors();

    expect($page->get('data')['priority'])->toBe($equipment->defaultWorkOrderPriority());
});

it('survives changing the pool code on the CAM form', function () {
    // Moving off `cam` resets the estimate basis — the callback that stops a tax pool inheriting
    // CAM's assumptions and subtracting a tenant's whole year of service charge.
    $page = Livewire::test(CreateCamExpensePool::class)
        ->set('data.asset_id', $this->asset->id)
        ->set('data.estimate_basis', CamExpensePool::BASIS_BILLED)
        ->set('data.pool_code', 'insurance')
        ->assertOk();

    expect($page->get('data')['estimate_basis'])->toBe(CamExpensePool::BASIS_STATED);
});

it('survives picking a tenant on the request form, and clears a unit that is now someone else\'s', function () {
    // Reworked 2026-08-17: the tenant drives the unit SUGGESTIONS, and changing it clears a unit
    // the new tenant does not hold — a stale value the narrowed picker would no longer offer and
    // the operator would never see.
    $other = makeTenant(['name' => 'Second Retailer']);
    $otherUnit = makeUnit($this->asset, ['code' => 'M-02']);
    makeLease($otherUnit, $other);

    $page = Livewire::test(CreateTenantRequest::class)
        ->set('data.tenant_id', $other->id)
        ->set('data.unit_id', $otherUnit->id)
        // Switching the reporter must not leave the previous tenant's unit selected.
        ->set('data.tenant_id', $this->tenant->id)
        ->assertOk();

    expect($page->get('data')['unit_id'])->toBeNull();
});

it('survives changing the property on the unit form, and clears a now-foreign zone', function () {
    // Also reworked 2026-08-17. `area_id` is scoped to the chosen property, so switching the
    // property has to drop a zone that belongs to the old one.
    $other = makeAsset(['code' => 'MF2']);
    $zone = Area::create(['asset_id' => $this->asset->id, 'name' => 'Food Court', 'code' => 'FC', 'is_active' => true]);

    $page = Livewire::test(CreateUnit::class)
        ->set('data.asset_id', $this->asset->id)
        ->set('data.area_id', $zone->id)
        ->set('data.asset_id', $other->id)
        ->assertOk();

    expect($page->get('data')['area_id'])->toBeNull();
});
