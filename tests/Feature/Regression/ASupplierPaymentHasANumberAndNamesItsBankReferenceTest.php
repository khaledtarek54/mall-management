<?php

use App\Filament\Admin\Resources\BankStatements\Pages\EditBankStatement;
use App\Filament\Admin\Resources\BankStatements\RelationManagers\LinesRelationManager;
use App\Filament\Admin\Resources\VendorBills\Pages\EditVendorBill;
use App\Filament\Admin\Resources\VendorBills\RelationManagers\VendorBillPaymentsRelationManager;
use App\Models\BankAccount;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorBillPayment;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Services\Accounting\MintBankLedgerAccountService;
use App\Services\VendorBillService;
use App\Support\DocumentNumbering;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * **A supplier payment is a money document, so it carries a number of its own — and it says what
 * the bank printed.**
 *
 * Found by driving AP as the accountant (2026-09-13 workflow audit): `vendor_bill_payments.
 * reference` was created for the document's number (its migration comment says so) and nothing
 * ever wrote it, so every payment read "Vendor payment #9" on the ledger, "—" on the bill's
 * payments tab and "#9" in the bank-reconciliation picker — a payment nobody could cite on a
 * remittance advice, a reconciliation or an audit request. The recording modal also never asked
 * for the cheque number or transfer reference, which is the ONE token a statement line and a book
 * posting share.
 *
 * Two facts, two columns, as the receipt already keeps `reference` (RCT-…) apart from
 * `cheque_number`: `reference` = `PMT-{mall}-{period}-NNNN`, allocated on create under the
 * document-number lock, LENGTH-first like every other series (EG-10); `bank_reference` = what the
 * operator typed from the bank's paper, asked on the modal, shown on the tab and beside every
 * candidate in the reconciliation picker.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $this->asset = makeAsset(['code' => 'PMX']);
    $this->actingAs(makeUser('accounting', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    $vendor = Vendor::create(['name' => 'Delta Cleaning '.uniqid(), 'status' => Vendor::STATUS_ACTIVE]);
    $this->bill = VendorBill::create([
        'vendor_id' => $vendor->id,
        'asset_id' => $this->asset->id,
        'category' => 'cleaning_security',
        'status' => 'approved',
        'bill_date' => now()->toDateString(),
        'due_date' => now()->addDays(30)->toDateString(),
        'subtotal' => 10000, 'vat_amount' => 0, 'total' => 10000, 'balance' => 10000,
    ]);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** The series a payment of THIS mall, dated today, is numbered in. */
function supplierPaymentPrefix(): string
{
    return VendorBillPayment::numberPrefix('PMX', now());
}

it('numbers the payment recorded through the real modal, keeps the bank\'s reference, and says so', function () {
    $page = Livewire::test(EditVendorBill::class, ['record' => $this->bill->getRouteKey()])
        ->callAction('record_payment', data: [
            'amount' => 4000,
            'method' => 'bank_transfer',
            'payment_date' => now()->toDateString(),
            'bank_reference' => ' TRF-9001 ',
        ])
        ->assertHasNoActionErrors();

    $payment = $this->bill->payments()->sole();

    expect($payment->reference)->toBe(supplierPaymentPrefix().'0001')
        ->and($payment->bank_reference)->toBe('TRF-9001')
        ->and(DocumentNumbering::prefixFor('vendor_payment'))->toBe('PMT');

    // The toast names the document — the number is what a remittance advice quotes. Read from
    // the session BEFORE `assertNotified()`, which pulls the notifications out of it: the
    // dehydrate hook has already CLAIMED them into the second key.
    $sent = collect(session()->get('filament.notifications', []) ?? [])
        ->merge(session()->get('filament.claimed_notifications', []) ?? [])
        ->pluck('body')->implode(' ');
    expect($sent)->toContain($payment->reference);
    $page->assertNotified();

    // A second payment continues the series; a cash one may carry no bank reference at all.
    app(VendorBillService::class)->recordPayment($this->bill->fresh(), 1000, 'cash');

    $second = $this->bill->payments()->latest('id')->first();
    expect($second->reference)->toBe(supplierPaymentPrefix().'0002')
        ->and($second->bank_reference)->toBeNull();
});

it('shows the number and the bank reference on the bill\'s payments tab', function () {
    app(VendorBillService::class)->recordPayment($this->bill, 4000, 'bank_transfer', now(), null, null, 'CHQ 771');

    Livewire::test(VendorBillPaymentsRelationManager::class, [
        'ownerRecord' => $this->bill->fresh(),
        'pageClass' => EditVendorBill::class,
    ])
        ->assertCanSeeTableRecords($this->bill->payments)
        ->assertSee(supplierPaymentPrefix().'0001')
        ->assertSee('CHQ 771');
});

it('names the number in the ledger narrative instead of a row id', function () {
    app(VendorBillService::class)->recordPayment($this->bill, 4000, 'bank_transfer', now(), null, null, 'TRF-9001');
    $payment = $this->bill->payments()->sole();

    app(LedgerPoster::class)->sync($payment);

    $entry = JournalEntry::query()
        ->where('source_type', 'vendor_bill_payment')->where('source_id', $payment->id)
        ->where('status', 'posted')->firstOrFail();

    expect($entry->displayDescription())->toContain($payment->reference)
        ->not->toContain('#'.$payment->id);
});

it('puts the bank reference beside the candidate in the reconciliation picker', function () {
    // The mall's bank, its own chart leaf, a payment through it, and the statement line that is it.
    $ledger = app(MintBankLedgerAccountService::class)->mint('CIB — current', $this->asset->id);
    $bank = BankAccount::create(['asset_id' => $this->asset->id, 'name' => 'CIB — current', 'ledger_account_id' => $ledger->id]);

    app(VendorBillService::class)->recordPayment($this->bill, 4000, 'bank_transfer', now(), null, $bank->id, 'TRF-9001');
    app(LedgerPoster::class)->sync($this->bill->payments()->sole());

    $statement = BankStatement::create([
        'bank_account_id' => $bank->id,
        'period_start' => now()->startOfMonth()->toDateString(),
        'period_end' => now()->endOfMonth()->toDateString(),
        'opening_balance' => 0, 'closing_balance' => -4000,
    ]);
    $line = BankStatementLine::create([
        'bank_statement_id' => $statement->id,
        'value_date' => now()->toDateString(),
        'amount' => -4000,
        'reference' => 'TRF-9001',
        'row_hash' => BankStatementLine::hashFor(now()->toDateString(), -4000, 'TRF-9001', null),
    ]);

    // A searchable Select loads its options through a Livewire call, not into the modal's HTML —
    // so the picker's labels are read off the mounted schema's own component.
    $tab = Livewire::test(LinesRelationManager::class, ['ownerRecord' => $statement, 'pageClass' => EditBankStatement::class])
        ->mountTableAction('match', $line);

    $picker = collect($tab->instance()->getSchema('mountedActionSchema0')->getFlatComponents())
        ->first(fn ($component) => method_exists($component, 'getName') && $component->getName() === 'journal_line_id');

    $labels = array_values($picker->getOptions());

    expect($labels)->toHaveCount(1)
        ->and($labels[0])->toContain(supplierPaymentPrefix().'0001')
        // The bank's token, in the picker, next to the candidate that carries it.
        ->toContain('· TRF-9001');

    // The receipt's twin: a tenant's cheque banked into the same account carries `cheque_number`,
    // and the picker shows that too — the second document `bankReferenceOf()` reads, and the
    // tooth the first cut of this file did not have (the mutation dropping it stayed green).
    $tenant = makeTenant();
    $receipt = Payment::create([
        'tenant_id' => $tenant->id,
        'bank_account_id' => $bank->id,
        'amount' => 12000,
        'method' => 'cheque',
        'cheque_number' => 'CHQ-55123',
        'payment_date' => now()->toDateString(),
        'status' => 'captured',
    ]);
    app(LedgerPoster::class)->sync($receipt);

    $inflow = BankStatementLine::create([
        'bank_statement_id' => $statement->id,
        'value_date' => now()->toDateString(),
        'amount' => 12000,
        'reference' => '55123',
        'row_hash' => BankStatementLine::hashFor(now()->toDateString(), 12000, '55123', null),
    ]);

    $tab->unmountTableAction()->mountTableAction('match', $inflow);
    $picker = collect($tab->instance()->getSchema('mountedActionSchema0')->getFlatComponents())
        ->first(fn ($component) => method_exists($component, 'getName') && $component->getName() === 'journal_line_id');

    expect(array_values($picker->getOptions()))->toHaveCount(1)
        ->and(array_values($picker->getOptions())[0])->toContain('· CHQ-55123');
});

it('counts past its zero-padding — LENGTH first, like every other series', function () {
    // `…-9999` sorts ABOVE `…-10000` as a string, so with BOTH on file a plain MAX reads 9999 and
    // proposes 10000 — a number already taken (EG-10). Both must exist for the tooth to bite: with
    // 9999 alone either ordering answers the same row, which is how a first cut of this stayed
    // green under the mutation.
    foreach (['9999', '10000'] as $tail) {
        app(VendorBillService::class)->recordPayment($this->bill->fresh(), 100, 'cash');
        VendorBillPayment::query()->whereKey($this->bill->payments()->latest('id')->first()->id)
            ->update(['reference' => supplierPaymentPrefix().$tail]);
    }

    app(VendorBillService::class)->recordPayment($this->bill->fresh(), 100, 'cash');

    expect($this->bill->payments()->latest('id')->first()->reference)->toBe(supplierPaymentPrefix().'10001');
});

it('numbers the payments recorded before the series existed, in order, once', function () {
    // Two rows written the way the old code wrote them — no number — behind one that has one.
    app(VendorBillService::class)->recordPayment($this->bill, 100, 'cash');
    $numbered = $this->bill->payments()->sole();

    foreach ([1, 2] as $i) {
        DB::table('vendor_bill_payments')->insert([
            'vendor_bill_id' => $this->bill->id,
            'reference' => null,
            'amount' => 100 * $i,
            'withholding_amount' => 0,
            'method' => 'cash',
            'payment_date' => now()->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    expect(VendorBillPayment::backfillMissingNumbers())->toBe(2);

    $refs = VendorBillPayment::query()->orderBy('id')->pluck('reference')->all();
    expect($refs)->toBe([
        $numbered->reference,
        supplierPaymentPrefix().'0002',
        supplierPaymentPrefix().'0003',
    ]);

    // Idempotent: the deploy step can be re-run, and it moves nothing the second time. (The
    // paging discipline — `lazyById`, never offset chunks over a set the loop shrinks — is stated
    // in the method and not exercised here: it only shows past a chunk's size.)
    expect(VendorBillPayment::backfillMissingNumbers())->toBe(0)
        ->and(VendorBillPayment::query()->orderBy('id')->pluck('reference')->all())->toBe($refs);
});
