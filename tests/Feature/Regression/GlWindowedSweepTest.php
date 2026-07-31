<?php

use App\Filament\Admin\Resources\Payments\Pages\EditPayment;
use App\Models\InventoryItem;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\VendorBill;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\Accounting\AccountResolver;
use App\Services\StockMovementService;
use App\Services\VendorBillService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * GL integrity hardening — Phase 0 regression guards.
 *
 * The `accounting:sync-ledger` DAILY run is WINDOWED: it only visits sources whose
 * own `updated_at` falls in the recent 2-day window. A money-affecting edit that does
 * NOT bump the swept source's updated_at is therefore invisible to the daily sweep and
 * the GL stays stale until a manual `--all`. Each guard below reproduces that by
 * pushing the source's updated_at OUT of the window, making the edit, then running the
 * DEFAULT (windowed) command — so it fails without the fix and passes with it.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
});

/** The current (posted, non-void) journal entry for a source document. */
function currentEntry(\Illuminate\Database\Eloquent\Model $source): ?JournalEntry
{
    return JournalEntry::where('source_type', $source->getMorphClass())
        ->where('source_id', $source->getKey())
        ->where('status', 'posted')
        ->latest('id')
        ->first()?->load('lines');
}

/** Force a source's updated_at out of the sweep's 2-day window (no events fired). */
function backdateOutOfWindow(\Illuminate\Database\Eloquent\Model $source): void
{
    DB::table($source->getTable())->where('id', $source->getKey())
        ->update(['updated_at' => now()->subDays(30)]);
}

it('re-derives an invoice entry after a money-neutral line-item TYPE change (F3)', function () {
    // A single-item invoice whose total matches the item, so the entry balances:
    //   Dr Accounts Receivable 10,000 / Cr Rent Revenue 10,000.
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset())), [
        'subtotal' => 10000, 'vat_amount' => 0, 'total' => 10000, 'balance' => 10000,
    ]);
    $item = $invoice->items()->create([
        'type' => 'base_rent', 'description' => 'Rent', 'amount' => 10000,
        'vat_rate' => 0, 'vat_amount' => 0, 'total' => 10000,
    ]);

    $this->artisan('accounting:sync-ledger --all')->assertSuccessful();

    $rent = app(AccountResolver::class)->id('rent_revenue');
    $service = app(AccountResolver::class)->id('service_charge_revenue');
    expect(currentEntry($invoice)->lines->firstWhere('ledger_account_id', $rent))->not->toBeNull();

    // Simulate a long-posted invoice, then re-type the line (money-neutral) — this
    // remaps the revenue account but does NOT change the invoice total.
    backdateOutOfWindow($invoice);
    $item->update(['type' => 'service_charge']); // $touches bumps invoice.updated_at

    $this->artisan('accounting:sync-ledger')->assertSuccessful(); // DEFAULT windowed run

    $entry = currentEntry($invoice);
    expect($entry->lines->firstWhere('ledger_account_id', $service))->not->toBeNull()
        ->and($entry->lines->firstWhere('ledger_account_id', $rent))->toBeNull();
});

it('re-dimensions a stock movement entry after its warehouse is re-homed (F9)', function () {
    $x = makeAsset();
    $y = makeAsset();
    $warehouse = Warehouse::create(['asset_id' => $x->id, 'name' => 'Store', 'code' => 'WH-'.uniqid()]);
    $item = InventoryItem::create(['sku' => 'SKU-'.uniqid(), 'name' => 'Seal', 'unit' => 'each', 'unit_cost' => 25]);
    $movement = app(StockMovementService::class)->receive($warehouse, $item, 40, 25); // value 1,000

    $this->artisan('accounting:sync-ledger --all')->assertSuccessful();
    expect((int) currentEntry($movement)->asset_id)->toBe($x->id);

    // Re-home the warehouse to another property, then run only the windowed sweep.
    backdateOutOfWindow($movement);
    $warehouse->update(['asset_id' => $y->id]); // Warehouse cascade bumps movement.updated_at

    $this->artisan('accounting:sync-ledger')->assertSuccessful();

    expect((int) currentEntry($movement->fresh())->asset_id)->toBe($y->id);
});

it('re-dimensions a vendor-bill payment entry after the bill is re-homed (F9)', function () {
    $x = makeAsset();
    $y = makeAsset();
    $bill = VendorBill::create([
        'vendor_id' => Vendor::factory()->create()->id, 'asset_id' => $x->id,
        'category' => 'utilities', 'status' => 'approved', 'bill_date' => now()->toDateString(),
        'subtotal' => 2000, 'vat_amount' => 280, 'total' => 2280, 'balance' => 2280,
    ]);
    app(VendorBillService::class)->recordPayment($bill, 1000, 'bank_transfer');
    $payment = $bill->payments()->first();

    $this->artisan('accounting:sync-ledger --all')->assertSuccessful();
    expect((int) currentEntry($payment)->asset_id)->toBe($x->id);

    backdateOutOfWindow($payment);
    $bill->update(['asset_id' => $y->id]); // VendorBill cascade bumps payment.updated_at

    $this->artisan('accounting:sync-ledger')->assertSuccessful();

    expect((int) currentEntry($payment->fresh())->asset_id)->toBe($y->id);
});

it('voids a vendor-bill payment entry when the payment is soft-deleted (F7)', function () {
    $bill = VendorBill::create([
        'vendor_id' => Vendor::factory()->create()->id, 'asset_id' => makeAsset()->id,
        'category' => 'utilities', 'status' => 'approved', 'bill_date' => now()->toDateString(),
        'subtotal' => 2000, 'vat_amount' => 280, 'total' => 2280, 'balance' => 2280,
    ]);
    app(VendorBillService::class)->recordPayment($bill, 1000, 'bank_transfer');
    $payment = $bill->payments()->first();

    $this->artisan('accounting:sync-ledger --all')->assertSuccessful();
    expect(currentEntry($payment))->not->toBeNull();

    // Soft-delete is now possible (F7) — the sweep must void the orphaned entry.
    trashBypassingDeletionPolicy($payment);
    $this->artisan('accounting:sync-ledger')->assertSuccessful();

    $entries = JournalEntry::where('source_type', $payment->getMorphClass())
        ->where('source_id', $payment->id)->get();
    expect($entries->where('status', 'void')->count())->toBe(1);

    // The bill's own payable is restored and the books still balance.
    expect((float) $bill->fresh()->paid_amount)->toBe(0.0);
    expect(app(\App\Services\Accounting\LedgerReportService::class)->trialBalance()['balanced'])->toBeTrue();
});

it('voids a bill AND its payments on the windowed sweep when the bill is soft-deleted (F9/High)', function () {
    $bill = VendorBill::create([
        'vendor_id' => Vendor::factory()->create()->id, 'asset_id' => makeAsset()->id,
        'category' => 'utilities', 'status' => 'approved', 'bill_date' => now()->toDateString(),
        'subtotal' => 2000, 'vat_amount' => 280, 'total' => 2280, 'balance' => 2280,
    ]);
    app(VendorBillService::class)->recordPayment($bill, 1000, 'bank_transfer');
    $payment = $bill->payments()->first();

    $this->artisan('accounting:sync-ledger --all')->assertSuccessful();
    expect(currentEntry($bill))->not->toBeNull()
        ->and(currentEntry($payment))->not->toBeNull();

    // Soft-delete the whole bill. The delete cascade must trash the payment too, so the
    // DAILY windowed sweep voids BOTH the bill's Cr-AP entry and the payment's Dr-AP/
    // Cr-Cash entry — otherwise AP/cash are left understated (the reviewer's High find).
    trashBypassingDeletionPolicy($bill);
    $this->artisan('accounting:sync-ledger')->assertSuccessful(); // windowed, not --all

    $voided = fn ($src) => JournalEntry::where('source_type', $src->getMorphClass())
        ->where('source_id', $src->getKey())->where('status', 'void')->count();
    expect($voided($bill))->toBe(1)
        ->and($voided($payment))->toBe(1);

    $tb = app(\App\Services\Accounting\LedgerReportService::class)->trialBalance();
    expect($tb['balanced'])->toBeTrue()
        ->and($tb['total_debit'])->toEqualWithDelta(0.0, 0.001); // fully unwound

    // Restoring the bill must bring BOTH back: the restoring cascade un-trashes only the
    // payments this delete removed, and the sweep re-posts both entries.
    $bill->restore();
    $this->artisan('accounting:sync-ledger')->assertSuccessful();

    expect(currentEntry($bill->fresh()))->not->toBeNull()
        ->and(currentEntry($payment->fresh()))->not->toBeNull()
        ->and((float) $bill->fresh()->paid_amount)->toBe(1000.0);
    expect(app(\App\Services\Accounting\LedgerReportService::class)->trialBalance()['balanced'])->toBeTrue();
});

it('bumps a payment updated_at when its allocations are edited via the page (F8)', function () {
    $this->seed(\Database\Seeders\RolesPermissionsSeeder::class); // grant payments.edit
    $this->actingAs(makeUser('super_admin'));

    $asset = makeAsset();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($asset); // the admin panel's "tenant" is a property (Asset)
    $lease = makeLease(makeUnit($asset));
    $invoice = makeInvoice($lease, ['subtotal' => 5000, 'vat_amount' => 0, 'total' => 5000, 'balance' => 5000]);
    $payment = Payment::create([
        'reference' => 'PAY-'.uniqid(), 'tenant_id' => $lease->tenant_id, 'amount' => 5000,
        'method' => 'bank_transfer', 'status' => 'captured', 'payment_date' => now()->toDateString(),
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 5000]);

    backdateOutOfWindow($payment);
    $before = $payment->fresh()->updated_at;

    // Re-allocate (5000 → 4000 on the same invoice) — the payment's OWN columns don't
    // change, so only the afterSave touch can bump its updated_at.
    Livewire::test(EditPayment::class, ['record' => $payment->getRouteKey()])
        ->fillForm(['allocations' => [['invoice_id' => $invoice->id, 'allocated_amount' => 4000]]])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($payment->fresh()->updated_at->gt($before))->toBeTrue();
});
