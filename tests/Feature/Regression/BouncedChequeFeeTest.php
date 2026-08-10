<?php

use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\PostDatedCheque;
use App\Services\BillBouncedChequeFeeService;
use App\Services\PostDatedChequeService;
use App\Settings\BillingSettings;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * Charging for a returned cheque (module 33; Yardi posts an NSF charge).
 *
 * **The gap.** A bounced cheque costs the landlord a bank return fee and the handling behind it, and
 * Atriom absorbed both silently. Voyager posts an NSF charge as a matter of course.
 *
 * **Atriom's reversal half is already better than Yardi's**, which is why only the fee was missing:
 * Voyager enters a receipt on deposit and reverses it on NSF, re-opening every charge it settled.
 * Atriom creates no `Payment` until a cheque CLEARS, so the tenant's invoice was never reduced and
 * there is nothing to un-apply. The test below asserts exactly that — the bounce leaves AR alone.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(\App\Services\Accounting\FiscalCalendar::class)->ensureYear((int) now()->year);
    app(BillingSettings::class)->nsf_fee_amount = 250.0;
});

afterEach(fn () => CarbonImmutable::setTestNow());

function bouncedCheque(): PostDatedCheque
{
    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset, ['code' => 'CQ-1']), null, ['status' => 'active']);

    $cheque = PostDatedCheque::create([
        'reference' => 'PDC-'.fake()->unique()->numberBetween(1000, 99999),
        'asset_id' => $asset->id,
        'tenant_id' => $lease->tenant_id,
        'lease_id' => $lease->id,
        'cheque_number' => '004421',
        'bank_name' => 'CIB',
        'amount' => 30000,
        'cheque_date' => now()->toDateString(),
        'received_date' => now()->subMonth()->toDateString(),
        'status' => PostDatedCheque::STATUS_DEPOSITED,
    ]);

    app(PostDatedChequeService::class)->bounce($cheque);

    return $cheque->fresh();
}

it('raises a fee invoice for a bounced cheque', function () {
    $cheque = bouncedCheque();

    $invoice = app(BillBouncedChequeFeeService::class)->bill($cheque);

    expect((float) $invoice->total)->toBe(250.0)
        ->and((float) $invoice->vat_amount)->toBe(0.0)   // a penalty is outside VAT scope
        ->and($invoice->items()->sole()->type)->toBe('nsf_fee')
        ->and($cheque->fresh()->nsf_fee_invoice_id)->toBe($invoice->id);
});

it('posts the fee to misc income through the REAL sweep', function () {
    // Not `LedgerPoster::post()` directly — that would prove only the journalizer's arithmetic.
    // This drives the service and then the sweep the scheduler runs, which is the house rule for
    // every GL source.
    $cheque = bouncedCheque();
    $invoice = app(BillBouncedChequeFeeService::class)->bill($cheque);

    $this->artisan('accounting:sync-ledger --all')->assertSuccessful();

    $entry = JournalEntry::where('source_type', $invoice->getMorphClass())
        ->where('source_id', $invoice->id)
        ->where('status', 'posted')
        ->sole();

    $credited = $entry->lines()->where('credit', '>', 0)->get();

    expect(round((float) $entry->lines()->sum('debit'), 2))->toBe(250.0)
        ->and(round((float) $entry->lines()->sum('credit'), 2))->toBe(250.0)
        // Misc income, mapped explicitly rather than reaching the fallback by accident.
        ->and($credited->pluck('ledger_account_id')->count())->toBe(1);
});

it('does not touch the tenant’s AR — there was no receipt to reverse', function () {
    // The half Atriom already does better than Yardi. A cheque that never cleared never created a
    // Payment, so a bounce re-opens nothing; only the fee is new money owed.
    $cheque = bouncedCheque();
    $lease = $cheque->lease;
    $before = Invoice::where('lease_id', $lease->id)->sum('balance');

    app(BillBouncedChequeFeeService::class)->bill($cheque);

    expect((float) Invoice::where('lease_id', $lease->id)->where('id', '!=', $cheque->fresh()->nsf_fee_invoice_id)->sum('balance'))
        ->toBe((float) $before);
});

it('never bills the same bounce twice', function () {
    $cheque = bouncedCheque();
    $service = app(BillBouncedChequeFeeService::class);

    $first = $service->bill($cheque);
    $second = $service->bill($cheque->fresh());

    expect($second->id)->toBe($first->id)
        ->and(Invoice::whereHas('items', fn ($q) => $q->where('type', 'nsf_fee'))->count())->toBe(1);
});

it('refuses when no fee is configured', function () {
    // 0 = off is how it ships, and the action stays hidden — but the service must refuse anyway
    // rather than raise a zero invoice.
    app(BillingSettings::class)->nsf_fee_amount = 0.0;
    $cheque = bouncedCheque();

    expect(fn () => app(BillBouncedChequeFeeService::class)->bill($cheque))
        ->toThrow(DomainException::class);

    // The control: with a fee set, the same call succeeds.
    app(BillingSettings::class)->nsf_fee_amount = 250.0;
    expect((float) app(BillBouncedChequeFeeService::class)->bill($cheque->fresh())->total)->toBe(250.0);
});

it('refuses on a cheque that has not bounced', function () {
    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset, ['code' => 'CQ-9']), null, ['status' => 'active']);
    $held = PostDatedCheque::create([
        'reference' => 'PDC-HELD', 'asset_id' => $asset->id, 'tenant_id' => $lease->tenant_id,
        'lease_id' => $lease->id, 'cheque_number' => '9', 'amount' => 100,
        'cheque_date' => now()->toDateString(), 'received_date' => now()->toDateString(),
        'status' => PostDatedCheque::STATUS_HELD,
    ]);

    expect(fn () => app(BillBouncedChequeFeeService::class)->bill($held))
        ->toThrow(DomainException::class);
});
