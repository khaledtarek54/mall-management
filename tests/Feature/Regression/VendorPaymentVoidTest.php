<?php

use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\LedgerAccount;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorBillPayment;
use App\Services\Reconciliation\BooksReconciliationService;
use App\Services\VendorBillService;
use App\Services\VoidVendorBillPaymentService;
use App\Settings\TaxSettings;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * Regression — a vendor payment must have a way back.
 *
 * THE HOLE (found 2026-08-11, change-impact plan F1). A cheque keyed against the wrong bill was
 * permanent. Three separate places promised a correction that did not exist:
 *
 *   - `DeletionPolicy` listed VendorBillPayment as never-deletable with the correction
 *     "void the payment — money left the bank";
 *   - `VendorBillService::cancel` refused a bill with payments, telling the operator to
 *     "reverse the payments first";
 *   - the payments relation manager was read-only, and the model has no
 *     `isCommittedForDeletionPurposes()` override, so the trait's `true` default refused even the
 *     soft-delete that would otherwise have self-healed the GL.
 *
 * So the AP balance was wrong, the bank leg was wrong, the withholding-tax liability was wrong, and
 * the bill could not be cancelled — with no operator path to any of it. Voiding a check is an
 * everyday operation in Yardi Voyager.
 *
 * Per the GL registry rule these drive the real service AND the real `accounting:sync-ledger`
 * sweep. A test that called LedgerPoster directly would prove only the journalizer's arithmetic
 * and would have passed just as happily while nothing dispatched.
 */
function voidTestBill(float $total = 1000, float $whtRate = 0.0): array
{
    $settings = app(TaxSettings::class);
    $settings->wht_enabled = $whtRate > 0;
    $settings->wht_default_rate = $whtRate;
    $settings->save();

    $asset = makeAsset();
    $vendor = Vendor::create(['name' => 'Nile Facilities '.uniqid(), 'status' => Vendor::STATUS_ACTIVE]);

    $bill = VendorBill::create([
        'number' => 'VB-'.uniqid(),
        'vendor_id' => $vendor->id,
        'asset_id' => $asset->id,
        'category' => 'cleaning_security',
        'status' => 'approved',
        'bill_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'subtotal' => $total, 'vat_amount' => 0, 'total' => $total, 'balance' => $total,
    ]);

    return [$asset, $vendor, $bill];
}

it('re-opens the payable and reverses the ledger entry through the real sweep', function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    [, , $bill] = voidTestBill(1000);

    app(VendorBillService::class)->recordPayment($bill, 1000.0);
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    $payment = $bill->refresh()->payments()->sole();
    $entry = JournalEntry::where('source_type', $payment->getMorphClass())
        ->where('source_id', $payment->id)->sole();

    // Precondition — pair the refusal with a control, or a no-op path passes for free.
    expect($entry->status)->toBe('posted')
        ->and((float) $bill->balance)->toBe(0.0)
        ->and($bill->status)->toBe('paid');

    app(VoidVendorBillPaymentService::class)->void($payment, 'Keyed against the wrong bill');
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    $bill->refresh();
    $payment->refresh();

    expect($payment->isVoided())->toBeTrue()
        ->and($payment->void_reason)->toBe('Keyed against the wrong bill')
        // The row STAYS — that is the difference between voiding and deleting.
        ->and(VendorBillPayment::whereKey($payment->id)->exists())->toBeTrue()
        // The document: the vendor is owed again.
        ->and((float) $bill->paid_amount)->toBe(0.0)
        ->and((float) $bill->balance)->toBe(1000.0)
        ->and($bill->status)->toBe('approved')
        // The ledger: the original entry is void and a balanced reversal points back at it.
        ->and($entry->refresh()->status)->toBe('void')
        ->and(JournalEntry::where('reversal_of_id', $entry->id)->where('status', 'posted')->exists())
        ->toBeTrue('the void did not post a reversing entry');
});

it('ties AP back out to the full bill after the void', function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    [, , $bill] = voidTestBill(1000);

    app(VendorBillService::class)->recordPayment($bill, 1000.0);
    app(VoidVendorBillPaymentService::class)->void($bill->refresh()->payments()->sole(), 'Wrong bill');
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    // The consequence stated in money: the payable is owed again, and the GL agrees. Before the
    // fix there was no path to this state at all — the cash was gone from the books for good.
    $tie = app(BooksReconciliationService::class)->glTieOut();

    expect($tie['ap']['expected'])->toBe(1000.0)
        ->and($tie['ap']['gl'])->toBe(1000.0)
        ->and($tie['ap']['delta'])->toBe(0.0);
});

it('returns the withholding leg too — the ETA liability follows the cash', function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    [, , $bill] = voidTestBill(1000, whtRate: 3.0);

    app(VendorBillService::class)->recordPayment($bill, 1000.0);
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    $payment = $bill->refresh()->payments()->sole();
    expect((float) $payment->withholding_amount)->toBe(30.0); // precondition

    app(VoidVendorBillPaymentService::class)->void($payment, 'Cheque cancelled at the bank');
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    // Net the original entry TOGETHER WITH its reversal — both stay on the books, which is what
    // voiding means, and every one of the three legs must net to zero. A void that reversed AP and
    // cash but left the withholding credit standing would overstate what is owed to the tax
    // authority: money the operator would be asked for and would not owe.
    //
    // Status is deliberately not filtered. Counting only `posted` lines would see the reversal
    // alone and net to −1000 on every leg, which reads as a broken void and is really a broken
    // query — the original is `void`, not absent.
    $sourceEntryIds = JournalEntry::where('source_type', $payment->getMorphClass())
        ->where('source_id', $payment->id)->pluck('id');

    $lines = JournalLine::query()
        ->whereHas('entry', fn ($q) => $q
            ->whereIn('id', $sourceEntryIds)
            ->orWhereIn('reversal_of_id', $sourceEntryIds))
        ->get();

    $net = fn (string $code) => round(
        $lines->where('ledger_account_id', LedgerAccount::where('code', $code)->value('id'))
            ->sum(fn (JournalLine $l) => (float) $l->debit - (float) $l->credit),
        2,
    );

    expect($net('21101001'))->toBe(0.0)  // accounts payable
        ->and($net('11102001'))->toBe(0.0) // bank
        ->and($net('21303001'))->toBe(0.0); // withholding tax payable
});

it('is idempotent — voiding a void changes nothing', function () {
    [, , $bill] = voidTestBill(1000);
    app(VendorBillService::class)->recordPayment($bill, 1000.0);
    $payment = $bill->refresh()->payments()->sole();

    $service = app(VoidVendorBillPaymentService::class);
    $service->void($payment, 'First');
    $firstVoidedAt = $payment->refresh()->voided_at;

    $service->void($payment->refresh(), 'Second');

    expect($payment->refresh()->void_reason)->toBe('First')
        ->and($payment->voided_at->eq($firstVoidedAt))->toBeTrue()
        ->and((float) $bill->refresh()->balance)->toBe(1000.0);
});

it('returns only the voided payment — a sibling still settles its share', function () {
    [, , $bill] = voidTestBill(1000);
    $service = app(VendorBillService::class);
    $service->recordPayment($bill, 400.0, notes: 'first');
    $service->recordPayment($bill->refresh(), 600.0, notes: 'second');

    expect((float) $bill->refresh()->balance)->toBe(0.0); // precondition

    $first = $bill->payments()->where('notes', 'first')->sole();
    app(VoidVendorBillPaymentService::class)->void($first, 'Duplicate entry');

    expect((float) $bill->refresh()->paid_amount)->toBe(600.0)
        ->and((float) $bill->balance)->toBe(400.0)
        ->and($bill->status)->toBe('partially_paid');
});

it('turns the cancel refusal into an instruction the operator can follow', function () {
    [, , $bill] = voidTestBill(1000);
    app(VendorBillService::class)->recordPayment($bill, 1000.0);

    // The refusal — this is what the operator hit before, with nowhere to go.
    expect(fn () => app(VendorBillService::class)->cancel($bill->refresh()))
        ->toThrow(DomainException::class);

    app(VoidVendorBillPaymentService::class)->void($bill->refresh()->payments()->sole(), 'Bill was a duplicate');

    // …and the control: having followed the instruction, the cancel now succeeds.
    $cancelled = app(VendorBillService::class)->cancel($bill->refresh());

    expect($cancelled->status)->toBe('cancelled')
        ->and((float) $cancelled->balance)->toBe(0.0);
});

it('grants the void permission to the roles that pay bills, and to no one else by accident', function () {
    $this->seed(RolesPermissionsSeeder::class);

    // Paired: a role that must hold it, and one that must not. A permission assertion with only
    // the negative half passes just as well when the permission does not exist at all.
    expect(makeUser('accounting')->can('vendor_bills.void_payment'))->toBeTrue()
        ->and(makeUser('super_admin')->can('vendor_bills.void_payment'))->toBeTrue()
        ->and(makeUser('viewer')->can('vendor_bills.void_payment'))->toBeFalse()
        ->and(makeUser('leasing')->can('vendor_bills.void_payment'))->toBeFalse();
});
