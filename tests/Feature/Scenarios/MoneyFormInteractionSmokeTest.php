<?php

use App\Filament\Admin\Resources\CreditNotes\Pages\CreateCreditNote;
use App\Filament\Admin\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Admin\Resources\Payments\Pages\CreatePayment;
use App\Filament\Admin\Resources\PostDatedCheques\Pages\CreatePostDatedCheque;
use App\Filament\Admin\Resources\VendorBills\Pages\CreateVendorBill;
use App\Models\Charge;
use App\Models\Vendor;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
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
 * lands in the general ledger.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

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
