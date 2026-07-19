<?php

use App\Filament\Admin\Resources\Payments\Pages\CreatePayment;
use App\Models\AccountingPeriod;
use App\Models\Payment;
use App\Services\Accounting\FiscalCalendar;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * Page-level guards on the admin Payment create form (Module 06 close-out 2026-07-19):
 *  - posting-date guard (a receipt back-dated into a closed period is refused before it relieves AR)
 *  - at-least-one-allocation (no orphaned on-account receipt invisible in the scoped UI)
 *  - duplicate allocation rows SUM instead of one silently winning
 *  - the over-allocation form cap actually blocks a page submit (finding: no page-level coverage)
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    ensureAllPropertiesAsset();
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $this->asset = makeAsset();
    $this->tenant = makeTenant();
    $this->lease = makeLease(makeUnit($this->asset), $this->tenant, ['status' => 'active']);
    $this->invoice = makeInvoice($this->lease, [
        'status' => 'issued', 'issue_date' => now()->toDateString(),
        'total' => 1000, 'balance' => 1000, 'paid_amount' => 0,
    ]);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

function paymentFormData(array $overrides = []): array
{
    return array_merge([
        'tenant_id' => test()->tenant->id,
        'amount' => 1000,
        'method' => 'cash',
        'status' => 'captured',
        'payment_date' => now()->toDateString(),
        'allocations' => [['invoice_id' => test()->invoice->id, 'allocated_amount' => 1000]],
    ], $overrides);
}

it('refuses a receipt dated into a closed accounting period', function () {
    // The month covering the receipt date is closed; a late cash receipt is then recorded into it.
    AccountingPeriod::forDate(now())->update(['status' => 'closed']);

    Livewire::test(CreatePayment::class)
        ->fillForm(paymentFormData(['payment_date' => now()->toDateString()]))
        ->call('create');

    // Refused before the row commits — AR is never relieved against a GL leg that can't post.
    expect(Payment::count())->toBe(0);
});

it('requires at least one invoice allocation (no orphan on-account receipt)', function () {
    Livewire::test(CreatePayment::class)
        ->fillForm(paymentFormData(['allocations' => []]))
        ->call('create');

    expect(Payment::count())->toBe(0);
});

it('sums duplicate allocation rows for the same invoice instead of dropping one', function () {
    Livewire::test(CreatePayment::class)
        ->fillForm(paymentFormData([
            'amount' => 1000,
            'allocations' => [
                ['invoice_id' => test()->invoice->id, 'allocated_amount' => 400],
                ['invoice_id' => test()->invoice->id, 'allocated_amount' => 600],
            ],
        ]))
        ->call('create');

    $payment = Payment::first();
    expect($payment)->not->toBeNull()
        ->and((float) $payment->invoices()->first()->pivot->allocated_amount)->toBe(1000.0) // 400 + 600, not 600
        ->and((float) $this->invoice->fresh()->balance)->toBe(0.0);
});

it('blocks an allocation that exceeds the invoice balance (page-level over-allocation cap)', function () {
    Livewire::test(CreatePayment::class)
        ->fillForm(paymentFormData([
            'amount' => 2000,
            'allocations' => [['invoice_id' => test()->invoice->id, 'allocated_amount' => 2000]], // balance is 1000
        ]))
        ->call('create')
        ->assertHasFormErrors();

    expect(Payment::count())->toBe(0);
});
