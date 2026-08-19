<?php

require __DIR__.'/boot.php';
use App\Filament\Admin\Resources\Areas\Pages\CreateArea;
use App\Filament\Admin\Resources\CreditNotes\Pages\CreateCreditNote;
use App\Filament\Admin\Resources\DepositTransactions\Pages\CreateDepositTransaction;
use App\Filament\Admin\Resources\Expenses\Pages\CreateExpense;
use App\Filament\Admin\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Admin\Resources\Leases\Pages\CreateLease;
use App\Filament\Admin\Resources\Payments\Pages\CreatePayment;
use App\Filament\Admin\Resources\PostDatedCheques\Pages\CreatePostDatedCheque;
use App\Filament\Admin\Resources\PurchaseRequests\Pages\CreatePurchaseRequest;
use App\Filament\Admin\Resources\RentableItems\Pages\CreateRentableItem;
use App\Filament\Admin\Resources\UnitOwnerships\Pages\CreateUnitOwnership;
use App\Filament\Admin\Resources\Units\Pages\CreateUnit;
use App\Filament\Admin\Resources\VendorBills\Pages\CreateVendorBill;
use App\Filament\Admin\Resources\Vendors\Pages\CreateVendor;
use App\Models\Asset;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;

Filament::setCurrentPanel(Filament::getPanel('admin'));
$asset = Asset::where('code', 'AW')->firstOrFail();
Filament::setTenant($asset, true);
$admin = User::where('email', 'admin@mall.test')->firstOrFail();
Auth::login($admin);

qa_section('FORMS 1 — the invoice form DRIVES its live callbacks (the 5-day 500 bug class)');
$lease = Lease::whereHas('unit', fn ($q) => $q->where('asset_id', $asset->id))->where('status', 'active')->firstOrFail();
$t = Livewire::test(CreateInvoice::class);
qa_ok('the create-invoice page mounts', true);
try {
    $t->set('data.lease_id', $lease->id);
    qa_ok('picking a lease fires prefillItemsFromLease without throwing', true);
    $state = $t->get('data');
    $tenantId = $state['tenant_id'] ?? null;
    qa_eq('…and DERIVES the debtor from the lease', $lease->tenant_id, (int) $tenantId);
    $items = $state['items'] ?? [];
    printf("  prefilled %d line(s)\n", count($items));
    qa_ok('…and prefills the lease charge lines', count($items) > 0, json_encode(array_values(array_map(
        fn ($i) => ($i['type'] ?? '?').'='.($i['amount'] ?? 0), $items))));
    $rentLine = collect($items)->firstWhere('type', 'base_rent');
    if ($rentLine) {
        qa_eq('rent line is VAT-exempt on the FORM too', 0.0, (float) ($rentLine['vat_rate'] ?? -1));
        qa_eq('…at the rent in force', round((float) $lease->base_rent_monthly, 2), round((float) $rentLine['amount'], 2), 0.02);
    }
} catch (Throwable $e) {
    qa_ok('picking a lease fires prefillItemsFromLease without throwing', false, get_class($e).': '.mb_substr($e->getMessage(), 0, 200));
}

qa_section('FORMS 2 — a crafted debtor is overridden by the lease (the disabled-field payload)');
try {
    $other = Tenant::where('id', '!=', $lease->tenant_id)->firstOrFail();
    $t2 = Livewire::test(CreateInvoice::class)
        ->set('data.lease_id', $lease->id)
        ->set('data.tenant_id', $other->id)   // crafted: a disabled field still arrives in the payload
        ->set('data.issue_date', '2026-08-20')
        ->set('data.due_date', '2026-08-27')
        ->set('data.period_start', '2026-08-01')
        ->set('data.period_end', '2026-08-31')
        ->set('data.status', 'draft');
    $t2->call('create');
    $made = Invoice::latest('id')->first();
    qa_eq('the invoice bills the LEASE tenant, not the crafted one', $lease->tenant_id, (int) $made->tenant_id);
    qa_ok('…so a crafted request cannot bill a third party', (int) $made->tenant_id !== (int) $other->id);
} catch (Throwable $e) {
    qa_ok('crafted-debtor create handled', false, get_class($e).': '.mb_substr($e->getMessage(), 0, 220));
}

qa_section('FORMS 3 — the payment form allocation callbacks');
$open = Invoice::where('asset_id', $asset->id)->where('balance', '>', 0)
    ->whereIn('status', ['issued', 'partially_paid', 'overdue'])->firstOrFail();
try {
    $p = Livewire::test(CreatePayment::class)
        ->set('data.tenant_id', $open->tenant_id)
        ->set('data.amount', 1000)
        ->set('data.payment_date', now()->toDateString())
        ->set('data.method', 'cash')
        ->set('data.allocations', [['invoice_id' => $open->id, 'allocated_amount' => 1000]]);
    qa_ok('the payment form accepts an allocation without throwing', true);
    $p->call('create');
    $pay = Payment::latest('id')->first();
    qa_eq('the payment is captured against the invoice', 1000.00,
        (float) DB::table('invoice_payment')->where('payment_id', $pay->id)->value('allocated_amount'));
} catch (Throwable $e) {
    qa_ok('payment form create', false, get_class($e).': '.mb_substr($e->getMessage(), 0, 220));
}

qa_section('FORMS 4 — an over-allocating payment is refused BY THE PAGE');
$open2 = Invoice::where('asset_id', $asset->id)->where('balance', '>', 0)
    ->whereIn('status', ['issued', 'partially_paid', 'overdue'])->latest('id')->firstOrFail();
$before = Payment::count();
try {
    Livewire::test(CreatePayment::class)
        ->set('data.tenant_id', $open2->tenant_id)
        ->set('data.amount', 100)
        ->set('data.payment_date', now()->toDateString())
        ->set('data.method', 'cash')
        ->set('data.allocations', [['invoice_id' => $open2->id, 'allocated_amount' => 999999]])
        ->call('create');
} catch (Throwable $e) { /* halt() surfaces as an exception in some paths */
}
qa_ok('allocating more than the payment amount does not create a payment',
    Payment::count() === $before, 'payments before='.$before.' after='.Payment::count());

qa_section('FORMS 5 — a receipt with NO allocation is refused');
$before2 = Payment::count();
try {
    Livewire::test(CreatePayment::class)
        ->set('data.tenant_id', $open2->tenant_id)
        ->set('data.amount', 5000)
        ->set('data.payment_date', now()->toDateString())
        ->set('data.method', 'bank_transfer')
        ->set('data.allocations', [])
        ->call('create');
} catch (Throwable $e) { /* expected */
}
qa_ok('an unallocated receipt is refused (orphan money)', Payment::count() === $before2,
    'payments before='.$before2.' after='.Payment::count());

qa_section('FORMS 6 — every Create form in the four modules MOUNTS');
$creates = [
    CreateUnit::class,
    CreateArea::class,
    CreateUnitOwnership::class,
    CreateRentableItem::class,
    CreateLease::class,
    CreateInvoice::class,
    CreatePayment::class,
    CreateCreditNote::class,
    CreateDepositTransaction::class,
    CreatePostDatedCheque::class,
    CreateVendorBill::class,
    CreateVendor::class,
    CreateExpense::class,
    CreatePurchaseRequest::class,
];
foreach ($creates as $cls) {
    try {
        Livewire::test($cls);
        qa_ok('mounts: '.class_basename($cls), true);
    } catch (Throwable $e) {
        qa_ok('mounts: '.class_basename($cls), false, get_class($e).': '.mb_substr($e->getMessage(), 0, 160));
    }
}

qa_section('FORMS 7 — the property field is PINNED on every create form');
foreach ([CreateUnit::class,
    CreateExpense::class,
    CreateVendorBill::class] as $cls) {
    try {
        $c = Livewire::test($cls);
        $state = $c->get('data');
        if (array_key_exists('asset_id', $state)) {
            qa_eq('property pinned to the selected mall: '.class_basename($cls), $asset->id, (int) $state['asset_id']);
        } else {
            echo '  ('.class_basename($cls)." has no asset_id in state)\n";
        }
    } catch (Throwable $e) {
        qa_ok('property pin: '.class_basename($cls), false, mb_substr($e->getMessage(),0,140));
    }
}

qa_summary();
