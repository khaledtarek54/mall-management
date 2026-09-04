<?php

use App\Filament\Admin\Resources\Payments\Pages\CreatePayment;
use App\Models\Payment;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| A receipt's typed fields fit the columns they are stored in (SW-026)
|--------------------------------------------------------------------------
| `gateway`, `gateway_transaction_id` and `cheque_number` are `varchar(255)` on `payments`
| (measured on `mall_management_qa`, 2026-09-03) and the form bounded none of them. Laravel ships
| MySQL `strict => true`, so the database answers `SQLSTATE[22001] Data too long for column` —
| reproduced on that server with 300 characters — which is a 500 on Create, not a field error: the
| operator loses the amount, the date, the allocations, everything.
|
| **This test measures the FORM, and it has to.** SQLite does not enforce a varchar width at all, so
| the whole suite runs on a database that would have accepted the 300-character value silently; the
| bound only exists here because it is declared on the field. That is also what makes the mutation
| meaningful — remove `maxLength()` and the refusal below stops happening, on this driver.
*/

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->seed(RolesPermissionsSeeder::class);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->asset = makeAsset();
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setTenant($this->asset);

    $this->lease = makeLease(makeUnit($this->asset), makeTenant());
    $this->invoice = makeInvoice($this->lease, [
        'status' => 'issued', 'subtotal' => 1000, 'vat_amount' => 0, 'total' => 1000,
        'paid_amount' => 0, 'balance' => 1000,
    ]);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('refuses a gateway or cheque value longer than its column, as a field error', function () {
    Livewire::test(CreatePayment::class)
        ->fillForm([
            'tenant_id' => $this->lease->tenant_id,
            'amount' => 1000,
            'method' => 'cash',
            'status' => 'captured',
            'payment_date' => CarbonImmutable::now()->toDateString(),
            'gateway' => str_repeat('g', 256),
            'gateway_transaction_id' => str_repeat('t', 256),
            'cheque_number' => str_repeat('c', 256),
            'allocations' => [['invoice_id' => $this->invoice->id, 'allocated_amount' => 1000]],
        ])
        ->call('create')
        ->assertHasFormErrors(['gateway', 'gateway_transaction_id', 'cheque_number']);

    // The receipt must not exist either — a refusal that still wrote the row would be worse than
    // the 500 it replaces.
    expect(Payment::count())->toBe(0);
});

it('accepts a reference that exactly fills the column — the control', function () {
    // A bound one character too tight is the same defect in the other direction: it refuses a real
    // bank reference and reads as the field being broken.
    Livewire::test(CreatePayment::class)
        ->fillForm([
            'tenant_id' => $this->lease->tenant_id,
            'amount' => 1000,
            'method' => 'cash',
            'status' => 'captured',
            'payment_date' => CarbonImmutable::now()->toDateString(),
            'gateway_transaction_id' => str_repeat('t', 255),
            'allocations' => [['invoice_id' => $this->invoice->id, 'allocated_amount' => 1000]],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(Payment::count())->toBe(1)
        ->and(strlen((string) Payment::first()->gateway_transaction_id))->toBe(255);
});
