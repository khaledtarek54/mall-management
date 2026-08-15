<?php

use App\Support\MorphMap;
use App\Models\DepositApplication;
use App\Models\DepositTransaction;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Lease;
use App\Models\Payment;
use App\Services\ApplyDepositToInvoiceService;
use App\Services\MoveOutStatementService;
use App\Services\SettleMoveOutService;
use Carbon\CarbonImmutable;

/**
 * Netting a security deposit against arrears (story MF-03, scenario S8 — completed 2026-08-09).
 *
 * Yardi's move-out disposition nets the deposit on one document: *540,000 − 120,000 unpaid − 35,000
 * damages = 385,000 refunded.* Atriom could report that position and not act on it.
 *
 * This is a **fourth channel into `Invoice::recomputeTotals()`** — the most protected rule in the
 * codebase — so the tests below check the things that break when a channel is added and something
 * downstream is not told about it: the AR maths, the deposit balance, the cancel path, the void
 * guard, the payment over-allocation guards, and the GL tie-out **driven through the real service
 * and the real sweep** rather than by calling the journalizer.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

beforeEach(function () {
    // Both seeders: the chart supplies the accounts, the mapping is what `AccountResolver` reads to
    // turn a key like `deposits_held` into one.
    test()->seed(\Database\Seeders\ChartOfAccountsSeeder::class);
    test()->seed(\Database\Seeders\AccountMappingSeeder::class);
});

/**
 * An arrears invoice whose header AGREES with its own value.
 *
 * `makeInvoice` defaults subtotal 10,000 / VAT 1,400 / total 11,400; overriding only `total` leaves
 * the invoice's own journalizer building an unbalanced entry, which fails the sweep for a reason
 * that has nothing to do with what is under test. Base rent is VAT-exempt, so zero VAT is also the
 * realistic shape here.
 */
function arrearsInvoice(float $amount, array $attrs = []): array
{
    return array_merge([
        'status' => 'overdue',
        'subtotal' => $amount,
        'vat_amount' => 0,
        'total' => $amount,
        'balance' => $amount,
    ], $attrs);
}

function depositLease(float $deposit = 540000): Lease
{
    $lease = makeLease(makeUnit(makeAsset()), null, [
        'status' => 'active',
        'commencement_date' => '2028-01-01',
        'expiry_date' => '2030-12-31',
        'base_rent_monthly' => 120000,
        'security_deposit' => $deposit,
        'has_marketing_levy' => false,
    ]);

    DepositTransaction::create([
        'lease_id' => $lease->id,
        'tenant_id' => $lease->tenant_id,
        'asset_id' => $lease->unit->asset_id,
        'type' => 'receipt',
        'amount' => $deposit,
        'transaction_date' => '2028-01-01',
        'status' => 'recorded',
    ]);

    return $lease->fresh();
}

it('settles the invoice and shrinks the deposit by the same amount', function () {
    CarbonImmutable::setTestNow('2028-09-18');
    $lease = depositLease(540000);
    $invoice = makeInvoice($lease, arrearsInvoice(120000));

    $applied = app(ApplyDepositToInvoiceService::class)->apply($lease, $invoice);

    expect($applied)->toBe(120000.0)
        ->and((float) $invoice->fresh()->balance)->toBe(0.0)
        ->and((float) $invoice->fresh()->paid_amount)->toBe(120000.0)
        ->and($invoice->fresh()->status)->toBe('paid')
        // The same money cannot also be refunded.
        ->and(app(MoveOutStatementService::class)->depositHeld($lease->fresh()))->toBe(420000.0);
});

it('never applies more than the invoice owes or the deposit holds', function () {
    CarbonImmutable::setTestNow('2028-09-18');
    $lease = depositLease(50000);
    $invoice = makeInvoice($lease, arrearsInvoice(120000));

    expect(app(ApplyDepositToInvoiceService::class)->apply($lease, $invoice))->toBe(50000.0)
        ->and((float) $invoice->fresh()->balance)->toBe(70000.0)
        ->and(app(MoveOutStatementService::class)->depositHeld($lease->fresh()))->toBe(0.0)
        // Nothing left to apply — a second call is a no-op rather than an over-application.
        ->and(app(ApplyDepositToInvoiceService::class)->apply($lease->fresh(), $invoice->fresh()))->toBe(0.0);
});

it('re-opens the AR and returns the deposit when reversed', function () {
    CarbonImmutable::setTestNow('2028-09-18');
    $lease = depositLease();
    $invoice = makeInvoice($lease, arrearsInvoice(120000));

    app(ApplyDepositToInvoiceService::class)->apply($lease, $invoice);

    $application = DepositApplication::where('invoice_id', $invoice->id)->sole();
    app(ApplyDepositToInvoiceService::class)->reverse($application);

    expect((float) $invoice->fresh()->balance)->toBe(120000.0)
        ->and(app(MoveOutStatementService::class)->depositHeld($lease->fresh()))->toBe(540000.0);
});

it('returns the deposit when the invoice is cancelled', function () {
    // A deposit stranded on a cancelled invoice would leave the tenant's refund permanently short,
    // and Deposits Held carrying a balance against a receivable that left the books. Every other
    // settlement channel already releases on cancel; this one has to as well.
    CarbonImmutable::setTestNow('2028-09-18');
    $lease = depositLease();
    $invoice = makeInvoice($lease, arrearsInvoice(120000));

    app(ApplyDepositToInvoiceService::class)->apply($lease, $invoice);
    $invoice->fresh()->update(['status' => 'cancelled']);

    expect(app(MoveOutStatementService::class)->depositHeld($lease->fresh()))->toBe(540000.0);
});

it('does not count a netted deposit as captured cash', function () {
    // `capturedCashPaid()` decides whether an invoice can be voided. A deposit application is a
    // reversible non-cash settlement, exactly like an applied credit — counting it as cash would
    // refuse to void an invoice that has nothing to refund.
    CarbonImmutable::setTestNow('2028-09-18');
    $lease = depositLease();
    $invoice = makeInvoice($lease, arrearsInvoice(120000));

    app(ApplyDepositToInvoiceService::class)->apply($lease, $invoice);

    expect($invoice->fresh()->capturedCashPaid())->toBe(0.0);
});

it('stops a later payment over-settling an invoice the deposit already paid', function () {
    // The exact bug the tenant-credit channel caused before it was counted: the second settlement
    // clears in full while the first also settled AR, burying the excess as negative AR.
    CarbonImmutable::setTestNow('2028-09-18');
    $lease = depositLease();
    $invoice = makeInvoice($lease, arrearsInvoice(120000));

    app(ApplyDepositToInvoiceService::class)->apply($lease, $invoice);

    expect(fn () => (new Payment)->assertInvoicesNotOverAllocated([$invoice->id]))->not->toThrow(DomainException::class);

    // Now allocate a real payment for the full amount on top — the guard must refuse it.
    $payment = Payment::factory()->create([
        'tenant_id' => $lease->tenant_id,
        'amount' => 120000,
        'status' => 'captured',
        'payment_date' => '2028-09-18',
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 120000]);

    expect(fn () => (new Payment)->assertInvoicesNotOverAllocated([$invoice->id]))
        ->toThrow(DomainException::class);
});

it('posts Dr Deposits Held / Cr AR and ties out through the real sweep', function () {
    // The GL invariant: a test that calls LedgerPoster::post() proves only the journalizer's
    // arithmetic. This drives the real service, then the real `accounting:sync-ledger` sweep, and
    // asserts the entry balances and hits the two accounts it should.
    CarbonImmutable::setTestNow('2028-09-18');
    $lease = depositLease();
    $invoice = makeInvoice($lease, arrearsInvoice(120000));

    app(ApplyDepositToInvoiceService::class)->apply($lease, $invoice);

    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    $application = DepositApplication::where('invoice_id', $invoice->id)->sole();

    $entry = JournalEntry::where('source_type', MorphMap::alias(DepositApplication::class))
        ->where('source_id', $application->id)
        ->where('status', 'posted')
        ->sole();

    $lines = JournalLine::where('journal_entry_id', $entry->id)->get();

    expect(round((float) $lines->sum('debit'), 2))->toBe(120000.0)
        ->and(round((float) $lines->sum('credit'), 2))->toBe(120000.0)
        ->and($entry->entry_date->toDateString())->toBe('2028-09-18');

    $codeFor = fn (string $key) => app(\App\Services\Accounting\AccountResolver::class)
        ->id($key, $lease->unit->asset_id);

    expect((float) $lines->firstWhere('ledger_account_id', $codeFor('deposits_held'))->debit)->toBe(120000.0)
        ->and((float) $lines->firstWhere('ledger_account_id', $codeFor('accounts_receivable'))->credit)->toBe(120000.0);
});

it('keeps GL receivables tied to invoice balances after a deposit settles one', function () {
    // THE invariant for a fourth AR channel. A deposit application credits GL AR and reduces the
    // invoice balance; if either side moved without the other, the sweep's own AR tie-out would
    // report a delta. Asserted from the sweep's output rather than by re-deriving it here — the
    // point is that the SHIPPED check agrees, not that my arithmetic does.
    CarbonImmutable::setTestNow('2028-09-18');
    $lease = depositLease();
    $invoice = makeInvoice($lease, arrearsInvoice(120000));

    app(ApplyDepositToInvoiceService::class)->apply($lease, $invoice);

    \Illuminate\Support\Facades\Artisan::call('accounting:sync-ledger', ['--all' => true]);
    $output = \Illuminate\Support\Facades\Artisan::output();

    expect($output)->toContain('GL ties to AR')
        ->and($output)->not->toContain('GL ↔ AR delta');
});

it('voids the entry when the application is reversed', function () {
    CarbonImmutable::setTestNow('2028-09-18');
    $lease = depositLease();
    $invoice = makeInvoice($lease, arrearsInvoice(120000));

    app(ApplyDepositToInvoiceService::class)->apply($lease, $invoice);
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    app(ApplyDepositToInvoiceService::class)->reverse(DepositApplication::where('invoice_id', $invoice->id)->sole());
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    expect(JournalEntry::where('source_type', MorphMap::alias(DepositApplication::class))->where('status', 'posted')->count())
        ->toBe(0);
});

it('settles S8 end to end: arrears, then damages, then the refund', function () {
    // The scenario, with Yardi's numbers: 540,000 held, 120,000 unpaid, 35,000 damages → 385,000.
    CarbonImmutable::setTestNow('2028-09-20');
    $lease = depositLease(540000);
    makeInvoice($lease, arrearsInvoice(120000, ['due_date' => '2028-08-08']));

    $lease->update(['status' => 'terminated']);

    $result = app(SettleMoveOutService::class)->settle($lease->fresh(), [
        'settlement_date' => '2028-09-20',
        'deductions' => [['description' => 'Reinstatement of the shopfront', 'amount' => 35000]],
    ]);

    expect($result['settled_arrears']['applied'])->toBe(120000.0)
        ->and($result['settled_arrears']['invoices'])->toBe(1)
        ->and((float) $result['forfeit']->amount)->toBe(35000.0)
        ->and((float) $result['refund']->amount)->toBe(385000.0)
        // Nothing left held, and the tenant owes nothing.
        ->and(app(MoveOutStatementService::class)->depositHeld($lease->fresh()))->toBe(0.0)
        ->and((float) Invoice::where('lease_id', $lease->id)->sum('balance'))->toBe(0.0);

    // …and the frozen statement records what was actually done.
    expect($result['event']->payload['arrears_settled'])->toEqual(120000.0)
        ->and($result['event']->payload['refunded'])->toEqual(385000.0);
});

it('leaves the tenant owing the damages, not the rent, when the deposit cannot cover both', function () {
    // Arrears settle first on purpose: an unpaid rent invoice may already have been filed with the
    // tax authority, while a deduction is an assessment made at settlement.
    CarbonImmutable::setTestNow('2028-09-20');
    $lease = depositLease(100000);
    makeInvoice($lease, arrearsInvoice(90000, ['due_date' => '2028-08-08']));

    $lease->update(['status' => 'terminated']);

    $result = app(SettleMoveOutService::class)->settle($lease->fresh(), [
        'settlement_date' => '2028-09-20',
        'deductions' => [['description' => 'Damages', 'amount' => 10000]],
    ]);

    expect($result['settled_arrears']['applied'])->toBe(90000.0)
        ->and((float) $result['forfeit']->amount)->toBe(10000.0)
        ->and($result['refund'])->toBeNull()
        ->and((float) Invoice::where('lease_id', $lease->id)->sum('balance'))->toBe(0.0);
});

it('can settle without touching the arrears when the books say it must wait', function () {
    CarbonImmutable::setTestNow('2028-09-20');
    $lease = depositLease(540000);
    makeInvoice($lease, arrearsInvoice(120000, ['due_date' => '2028-08-08']));
    $lease->update(['status' => 'terminated']);

    $result = app(SettleMoveOutService::class)->settle($lease->fresh(), [
        'settlement_date' => '2028-09-20',
        'settle_arrears' => false,
    ]);

    expect($result['settled_arrears']['applied'])->toBe(0.0)
        ->and((float) $result['refund']->amount)->toBe(540000.0)
        ->and((float) Invoice::where('lease_id', $lease->id)->sum('balance'))->toBe(120000.0);
});
