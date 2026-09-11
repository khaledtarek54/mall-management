<?php

use App\Models\CreditNote;
use App\Models\CreditNoteApplication;
use App\Models\DepositTransaction;
use App\Models\DocumentTemplate;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Services\TenantStatementPdfService;
use App\Services\WriteOffInvoiceService;
use App\Support\TenantLedger;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Facades\View;

/**
 * **The Statement of Account is the tenant ledger, printed** — client meeting 2026-09-02, points
 * 5 · 6 · 8 · 9 · 10: *"kashf 7esab: totals due to you and from you · show the deposit, debit /
 * credit / balance · the PDF with the same details as the screen, every detail, not generalised ·
 * the description says which invoice was paid and how · a footer: valid for X days."*
 *
 * Until 2026-09-11 the PDF was a different document from the on-screen ledger tab: four tables
 * (open invoices · credits · payments · other settlements), no balance brought forward, no running
 * balance, a payment line naming its rail and nothing it settled, the deposit HELD printed nowhere,
 * and a fixed footer. Yardi's tenant statement is a running-balance ledger with a balance forward
 * and the deposit on hand beside it; every Egyptian كشف حساب reads the same way. So the PDF now
 * renders `TenantLedger` — the derivation the screen already shows — at LINE grain (Yardi's
 * charge-code grain), with a deposit account, two totals, and an operator-editable footer.
 *
 * Two defects in the ledger itself came out of making it the statement: a WRITE-OFF had no row,
 * so the ledger closed above the collectable headline printed beside it; and a `written_off` or
 * legacy `credited` invoice was dropped while the credit note that relieved it (keyed on the
 * tenant, not the invoice) stayed — a credit with no debit above it.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    ensureAllPropertiesAsset();

    $this->asset = makeAsset();
    $this->tenant = makeTenant(['name' => 'Kiosk Corner']);
    $this->lease = makeLease(makeUnit($this->asset), $this->tenant, ['status' => 'active']);
    $this->svc = app(TenantStatementPdfService::class);
    CarbonImmutable::setTestNow('2026-09-11');
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** An issued invoice with two narrated lines — rent and service charge — dated as given. */
function statementInvoiceWithLines($ctx, string $issued, float $rent = 10000, float $service = 2000): Invoice
{
    $invoice = makeInvoice($ctx->lease, [
        'asset_id' => $ctx->asset->id, 'status' => 'issued',
        'issue_date' => $issued, 'due_date' => CarbonImmutable::parse($issued)->addDays(10)->toDateString(),
        'period_start' => CarbonImmutable::parse($issued)->startOfMonth()->toDateString(),
        'period_end' => CarbonImmutable::parse($issued)->endOfMonth()->toDateString(),
        'subtotal' => $rent + $service, 'vat_amount' => 0, 'total' => $rent + $service, 'balance' => $rent + $service,
    ]);

    foreach ([['Base Rent', 'base_rent', $rent], ['Service Charge', 'service_charge', $service]] as [$name, $type, $amount]) {
        if ($amount <= 0) {
            continue;
        }
        InvoiceItem::create([
            'invoice_id' => $invoice->id, 'description' => $name,
            'description_key' => 'billing.period', 'description_data' => ['name' => $name, 'period' => $invoice->period_start->toDateString()],
            'type' => $type, 'amount' => $amount, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => $amount,
        ]);
    }

    return $invoice->fresh();
}

function statementPayment($ctx, array $allocations, string $on, string $method = 'bank_transfer', ?string $reference = null): Payment
{
    $payment = Payment::create([
        'tenant_id' => $ctx->tenant->id, 'amount' => array_sum($allocations), 'method' => $method,
        'status' => 'captured', 'payment_date' => $on, 'currency' => 'EGP', 'reference' => $reference,
    ]);
    foreach ($allocations as $invoiceId => $amount) {
        $payment->invoices()->attach($invoiceId, ['allocated_amount' => $amount]);
    }
    $payment->recomputeAllocatedInvoices();

    return $payment;
}

it('prints every line of an invoice as its own row, worded for the reader, summing to the invoice', function () {
    $invoice = statementInvoiceWithLines($this, '2026-08-01');

    $rows = $this->svc->data($this->tenant)['ledger']['rows']->where('type', 'invoice')->values();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('reference')->unique()->all())->toBe([$invoice->number])
        ->and($rows->pluck('debit')->all())->toBe([10000.0, 2000.0])
        ->and($rows[0]['description'])->toBe('Base Rent - August 2026')
        ->and($rows[1]['description'])->toBe('Service Charge - August 2026')
        ->and($rows->sum('debit'))->toBe((float) $invoice->total);
});

it('is the same ledger the screen shows — row for row', function () {
    $invoice = statementInvoiceWithLines($this, '2026-07-01');
    statementPayment($this, [$invoice->id => 5000], '2026-07-20');

    $screen = TenantLedger::for($this->tenant)->map(fn (array $r) => [$r['reference'], $r['description'], $r['debit'], $r['credit'], $r['balance']]);
    $paper = $this->svc->data($this->tenant, null, '2026-01-01')['ledger']['rows']->map(fn (array $r) => [$r['reference'], $r['description'], $r['debit'], $r['credit'], $r['balance']]);

    expect($paper->all())->toBe($screen->all())
        ->and($paper->last()[4])->toBe(7000.0);
});

it('says on a payment row which invoices it settled and how it arrived', function () {
    $a = statementInvoiceWithLines($this, '2026-07-01', 6000, 0);
    $b = statementInvoiceWithLines($this, '2026-08-01', 4000, 0);
    $payment = statementPayment($this, [$a->id => 6000, $b->id => 4000], '2026-08-15', 'bank_transfer');

    $row = $this->svc->data($this->tenant)['ledger']['rows']->firstWhere('type', 'payment');

    // The reference is the RECEIPT number the system allocated (`RCT-…`), the document a tenant
    // can quote back.
    expect($row)->not->toBeNull()
        ->and($row['reference'])->toBe($payment->fresh()->reference)
        ->and($row['reference'])->toStartWith('RCT')
        ->and($row['description'])->toContain($a->number)
        ->and($row['description'])->toContain($b->number)
        ->and($row['description'])->toContain(PaymentMethod::labelFor('bank_transfer'))
        ->and($row['credit'])->toBe(10000.0);
});

it('opens with the balance brought forward and closes where the invoices say', function () {
    $jan = statementInvoiceWithLines($this, '2026-01-05');            // 12,000
    statementPayment($this, [$jan->id => 12000], '2026-01-20');       // settled
    $feb = statementInvoiceWithLines($this, '2026-02-05');            // 12,000 open
    statementPayment($this, [$feb->id => 2000], '2026-03-10');        // inside the window
    statementInvoiceWithLines($this, '2026-05-05', 500, 0);           // after the window

    $data = $this->svc->data($this->tenant, null, '2026-03-01', '2026-04-30');
    $ledger = $data['ledger'];

    // Everything before 1 March folds into one figure; the window's own rows run from it.
    expect($ledger['opening'])->toBe(12000.0)
        ->and($ledger['rows'])->toHaveCount(1)
        ->and($ledger['rows']->first()['type'])->toBe('payment')
        ->and($ledger['rows']->first()['balance'])->toBe(10000.0)
        ->and($ledger['closing'])->toBe(10000.0)
        // …and with no rows in the window the closing IS the opening — not zero.
        ->and($this->svc->data($this->tenant, null, '2026-04-01', '2026-04-30')['ledger']['closing'])->toBe(10000.0);

    // The unbounded statement closes exactly where the screen's ledger does, and where the
    // headline says.
    $whole = $this->svc->data($this->tenant);
    expect($whole['ledger']['closing'])->toBe(TenantLedger::closingBalance($this->tenant))
        ->toBe(10500.0)
        ->and($whole['summary']['due_from_tenant'])->toBe(10500.0)
        ->and($whole['summary']['outstanding'])->toBe(10500.0);
});

it('prints the security deposit as its own account, outside the running balance', function () {
    statementInvoiceWithLines($this, '2026-08-01');

    DepositTransaction::create([
        'lease_id' => $this->lease->id, 'type' => 'receipt', 'amount' => 30000,
        'transaction_date' => '2026-07-15', 'method' => 'bank', 'status' => 'recorded',
    ]);
    DepositTransaction::create([
        'lease_id' => $this->lease->id, 'type' => 'refund', 'amount' => 5000,
        'transaction_date' => '2026-08-20', 'method' => 'bank', 'status' => 'recorded',
    ]);
    // A CANCELLED deposit movement is not money — off the account entirely.
    DepositTransaction::create([
        'lease_id' => $this->lease->id, 'type' => 'receipt', 'amount' => 99999,
        'transaction_date' => '2026-08-21', 'method' => 'bank', 'status' => 'cancelled',
    ]);

    $data = $this->svc->data($this->tenant);

    expect($data['deposit']['held'])->toBe(25000.0)
        ->and($data['deposit']['rows']->pluck('in')->all())->toBe([30000.0, 0.0])
        ->and($data['deposit']['rows']->pluck('out')->all())->toBe([0.0, 5000.0])
        ->and($data['summary']['deposit_held'])->toBe(25000.0)
        // …and it never touches the receivable: the ledger still closes on the invoice alone.
        ->and($data['ledger']['closing'])->toBe(12000.0)
        ->and($data['ledger']['rows']->where('type', 'deposit'))->toBeEmpty();

    $html = View::make('tenants.statement', $data)->render();
    expect($html)->toContain(__('admin.statement.deposit_kinds.receipt'))
        ->and($html)->toContain(__('admin.statement.deposit_kinds.refund'))
        ->and($html)->toContain('25,000.00')
        ->and($html)->not->toContain('99,999.00');
});

it('states both sides of the account: due from the tenant, and held for them', function () {
    $invoice = statementInvoiceWithLines($this, '2026-08-01');           // 12,000 owed

    DepositTransaction::create([
        'lease_id' => $this->lease->id, 'type' => 'receipt', 'amount' => 30000,
        'transaction_date' => '2026-07-15', 'method' => 'bank', 'status' => 'recorded',
    ]);

    // A credit note issued and not yet applied — money owed back, not netted.
    CreditNote::create([
        'tenant_id' => $this->tenant->id, 'asset_id' => $this->asset->id, 'status' => 'issued',
        'issue_date' => '2026-08-10', 'reason' => 'adjustment',
        'subtotal' => 3000, 'vat_amount' => 0, 'total' => 3000, 'applied_amount' => 0, 'balance' => 3000, 'currency' => 'EGP',
    ]);

    // Money paid on account: a 7,000 receipt of which only 4,000 landed on an invoice.
    $payment = Payment::create([
        'tenant_id' => $this->tenant->id, 'amount' => 7000, 'method' => 'cash',
        'status' => 'captured', 'payment_date' => '2026-08-12', 'currency' => 'EGP',
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 4000]);
    $payment->recomputeAllocatedInvoices();

    $summary = $this->svc->data($this->tenant)['summary'];

    expect($summary['due_from_tenant'])->toBe(8000.0)
        ->and($summary['deposit_held'])->toBe(30000.0)
        ->and($summary['credit_notes_unapplied'])->toBe(3000.0)
        ->and($summary['credit_on_account'])->toBe(3000.0)
        ->and($summary['due_to_tenant'])->toBe(36000.0);

    $html = View::make('tenants.statement', $this->svc->data($this->tenant))->render();
    expect($html)->toContain(__('admin.statement.due_from_you'))
        ->and($html)->toContain(__('admin.statement.due_to_you'))
        ->and($html)->toContain('36,000.00');
});

it('carries a write-off as its own row, so the ledger closes on the collectable figure', function () {
    $invoice = statementInvoiceWithLines($this, '2026-07-01', 20000, 0);
    statementPayment($this, [$invoice->id => 5000], '2026-07-20');

    app(WriteOffInvoiceService::class)->write($invoice->fresh(), [
        'amount' => 15000, 'reason' => 'tenant_insolvent', 'entry_date' => '2026-08-01',
    ]);

    // A FULL write-off moves the status to `written_off`; the invoice stays on the ledger with
    // the payment it took and the write-off that relieved the rest — history, not a hole.
    expect($invoice->fresh()->status)->toBe('written_off');

    $data = $this->svc->data($this->tenant);
    $types = $data['ledger']['rows']->pluck('type')->all();

    expect($types)->toBe(['invoice', 'payment', 'write_off'])
        ->and($data['ledger']['rows']->firstWhere('type', 'write_off')['credit'])->toBe(15000.0)
        ->and($data['ledger']['closing'])->toBe(0.0)
        ->and(TenantLedger::closingBalance($this->tenant))->toBe(0.0);
});

it('leaves out a legacy written-off or credited invoice whose relief was never recorded', function () {
    // Imported rows can carry the terminal status with no write-off row and no stored credit
    // relief; listing them would print a debit nobody relieved. The control: the same status
    // WITH its relief on record — `credit_applied_amount`, the figure `recomputeTotals()` settles
    // with — is listed with it, and the note that names the invoice gives the row its number even
    // when no application row was ever written (the imported shape).
    makeInvoice($this->lease, ['asset_id' => $this->asset->id, 'status' => 'written_off', 'issue_date' => '2026-06-01', 'total' => 777, 'balance' => 777]);
    makeInvoice($this->lease, ['asset_id' => $this->asset->id, 'status' => 'credited', 'issue_date' => '2026-06-02', 'total' => 888, 'balance' => 888, 'paid_amount' => 0, 'credit_applied_amount' => 0]);

    $relieved = makeInvoice($this->lease, ['asset_id' => $this->asset->id, 'status' => 'credited', 'issue_date' => '2026-06-03', 'total' => 999, 'balance' => 0, 'paid_amount' => 999, 'credit_applied_amount' => 999]);
    $note = CreditNote::create([
        'tenant_id' => $this->tenant->id, 'invoice_id' => $relieved->id, 'asset_id' => $this->asset->id,
        'status' => 'applied', 'issue_date' => '2026-06-04', 'reason' => 'adjustment',
        'subtotal' => 999, 'vat_amount' => 0, 'total' => 999, 'applied_amount' => 999, 'balance' => 0, 'currency' => 'EGP',
    ]);

    $rows = TenantLedger::for($this->tenant);

    expect($rows->pluck('debit')->all())->toBe([999.0, 0.0])
        ->and($rows->pluck('credit')->all())->toBe([0.0, 999.0])
        ->and($rows->last()['reference'])->toBe($note->fresh()->number)
        ->and(TenantLedger::closingBalance($this->tenant))->toBe(0.0);
});

it('prints the operator\'s own footer per property, and the built-in sentence when none is written', function () {
    statementInvoiceWithLines($this, '2026-08-01');

    $floor = View::make('tenants.statement', $this->svc->data($this->tenant))->render();
    expect($floor)->toContain(__('admin.statement.footer'));

    DocumentTemplate::create(['key' => 'statement.footer', 'asset_id' => null, 'body_en' => 'House: valid for 14 days.']);
    DocumentTemplate::create(['key' => 'statement.footer', 'asset_id' => $this->asset->id, 'body_en' => 'This statement is valid for 7 days from its date.']);

    $data = $this->svc->data($this->tenant);
    $html = View::make('tenants.statement', $data)->render();

    // The MALL's own wording wins over the house default, which wins over the floor.
    expect($data['footerText'])->toBe('This statement is valid for 7 days from its date.')
        ->and($html)->toContain('This statement is valid for 7 days from its date.')
        ->and($html)->not->toContain('House: valid for 14 days.')
        ->and($html)->not->toContain(__('admin.statement.footer'));
});

it('reads in Arabic with no raw key on the page', function () {
    $invoice = statementInvoiceWithLines($this, '2026-08-01');
    statementPayment($this, [$invoice->id => 4000], '2026-08-15');
    DepositTransaction::create([
        'lease_id' => $this->lease->id, 'type' => 'receipt', 'amount' => 30000,
        'transaction_date' => '2026-07-15', 'method' => 'bank', 'status' => 'recorded',
    ]);

    app()->setLocale('ar');
    $html = View::make('tenants.statement', $this->svc->data($this->tenant))->render();
    app()->setLocale('en');

    expect($html)->toContain(__('admin.statement.account_ledger', [], 'ar'))
        ->and($html)->toContain(__('admin.statement.balance_forward', [], 'ar'))
        ->and($html)->toContain(__('admin.ledger.debit', [], 'ar'))
        ->and($html)->toContain(__('admin.statement.deposit_kinds.receipt', [], 'ar'))
        ->and($html)->not->toMatch('/admin\.[a-z_]+\.[a-z_.]+/');

    // The labels themselves are Arabic, not English sitting in the Arabic file.
    foreach (['account_ledger', 'balance_forward', 'due_from_you', 'due_to_you', 'deposit_account', 'balance_by_invoice'] as $key) {
        expect(__("admin.statement.{$key}", [], 'ar'))->toMatch('/\p{Arabic}/u');
    }
    foreach (['written_off', 'payment_description', 'credit_note_description'] as $key) {
        expect(__("admin.ledger.{$key}", [], 'ar'))->toMatch('/\p{Arabic}/u');
    }
});

it('lists a credit note applied to an invoice it does not name — the CAM true-up shape', function () {
    // `CreditNoteService::applyToInvoice()` never writes `credit_notes.invoice_id`; the record of
    // an application is `credit_note_applications`, and every negative CAM true-up is raised with
    // no invoice at all and applied FIFO. Keyed on `invoice_id` the ledger dropped exactly those
    // and closed ABOVE the collectable figure by their amount — found by the review of this change.
    $invoice = statementInvoiceWithLines($this, '2026-08-01', 12000, 0);

    $note = CreditNote::create([
        'tenant_id' => $this->tenant->id, 'asset_id' => $this->asset->id, 'status' => 'applied',
        'issue_date' => '2026-08-05', 'reason' => 'adjustment',
        'subtotal' => 3000, 'vat_amount' => 0, 'total' => 3000, 'applied_amount' => 3000, 'balance' => 0, 'currency' => 'EGP',
    ]);
    CreditNoteApplication::create([
        'credit_note_id' => $note->id, 'invoice_id' => $invoice->id, 'amount' => 3000, 'applied_at' => '2026-08-06 10:00:00',
    ]);
    $invoice->forceFill(['credit_applied_amount' => 3000])->saveQuietly();
    $invoice->fresh()->recomputeTotals();

    $data = $this->svc->data($this->tenant);
    $row = $data['ledger']['rows']->firstWhere('type', 'credit_note');

    expect($row)->not->toBeNull('the applied note has no ledger row')
        ->and($row['credit'])->toBe(3000.0)
        ->and($row['reference'])->toBe($note->fresh()->number)
        ->and($row['date']->toDateString())->toBe('2026-08-06')
        ->and($data['ledger']['closing'])->toBe(9000.0)
        ->and($data['ledger']['closing'])->toBe($invoice->fresh()->collectableBalance());
});

it('keeps one invoice\'s lines together when another invoice is issued the same day', function () {
    $a = statementInvoiceWithLines($this, '2026-08-01', 59500, 2975);
    $b = statementInvoiceWithLines($this, '2026-08-01', 10000, 0);

    $references = $this->svc->data($this->tenant)['ledger']['rows']->pluck('reference')->all();

    // A, A, B — not A, B, A by amount, which is what a debit-first tie-break alone produced.
    expect($references)->toBe([$a->number, $a->number, $b->number]);
});

it('prints a billed-and-paid deposit in the deposit account, and opens the account so it foots', function () {
    // A deposit BILLED on an invoice and paid is held (`depositHeld()` counts it) and had no row —
    // the account printed a header, an empty body and a footer. And a receipt dated before the
    // window must be brought forward, or the same empty body appears under a full footer.
    $deposit = makeInvoice($this->lease, [
        'asset_id' => $this->asset->id, 'status' => 'issued', 'issue_date' => '2026-08-03', 'due_date' => '2026-08-13',
        'subtotal' => 20000, 'vat_amount' => 0, 'total' => 20000, 'balance' => 20000,
    ]);
    InvoiceItem::create([
        'invoice_id' => $deposit->id, 'description' => 'Security deposit', 'type' => 'security_deposit',
        'amount' => 20000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 20000,
    ]);
    statementPayment($this, [$deposit->id => 20000], '2026-08-10');

    DepositTransaction::create([
        'lease_id' => $this->lease->id, 'type' => 'receipt', 'amount' => 5000,
        'transaction_date' => '2026-02-15', 'method' => 'bank', 'status' => 'recorded',
    ]);

    $data = $this->svc->data($this->tenant, null, '2026-08-01');

    expect($data['deposit']['held'])->toBe(25000.0)
        ->and($data['deposit']['opening'])->toBe(5000.0)
        ->and($data['deposit']['rows'])->toHaveCount(1)
        ->and($data['deposit']['rows']->first()['kind'])->toBe(__('admin.statement.deposit_kinds.billed'))
        ->and($data['deposit']['rows']->first()['in'])->toBe(20000.0)
        // opening + in − out = held, always.
        ->and($data['deposit']['opening'] + $data['deposit']['rows']->sum('in') - $data['deposit']['rows']->sum('out'))->toBe(25000.0);

    $html = View::make('tenants.statement', $data)->render();
    expect($html)->toContain(__('admin.statement.deposit_kinds.billed'))
        ->toContain('5,000.00')
        ->toContain('25,000.00');
});

it('says the per-invoice figures are today\'s when the statement is bounded in the past', function () {
    $invoice = statementInvoiceWithLines($this, '2026-03-01', 20000, 0);
    statementPayment($this, [$invoice->id => 5000], '2026-03-20');
    statementPayment($this, [$invoice->id => 6000], '2026-05-02');

    $data = $this->svc->data($this->tenant, null, '2026-01-01', '2026-03-31');
    $html = View::make('tenants.statement', $data)->render();

    // The ledger closes AS AT 31 March (15,000); the invoice table is struck today (9,000) — and
    // the page says so beside the figures that differ, rather than printing two balances on two
    // bases with nothing to tell them apart.
    expect($data['ledger']['closing'])->toBe(15000.0)
        ->and($data['summary']['outstanding'])->toBe(9000.0)
        ->and($data['figuresAsOfToday'])->toBeTrue()
        ->and($html)->toContain(__('admin.statement.figures_as_of', ['date' => now()->format('d/m/Y')]));

    // …and the unbounded statement, the ordinary download, carries no such note.
    $whole = $this->svc->data($this->tenant);
    expect($whole['figuresAsOfToday'])->toBeFalse()
        ->and(View::make('tenants.statement', $whole)->render())->not->toContain(__('admin.statement.figures_as_of', ['date' => now()->format('d/m/Y')]));
});

it('adds a residual row when an imported header disagrees with its lines, so the debit is the document', function () {
    // Every invoice the system raises has `total` = Σ items; an imported one need not. The ledger
    // must still charge the DOCUMENT's figure — it is what the tenant was asked for and what
    // `collectableBalance()` is computed from — so the difference is one more row under the
    // period rather than a closing balance short of the headline beside it.
    $invoice = makeInvoice($this->lease, [
        'asset_id' => $this->asset->id, 'status' => 'issued', 'issue_date' => '2026-08-01',
        'subtotal' => 12000, 'vat_amount' => 0, 'total' => 12000, 'balance' => 12000,
    ]);
    // `saveQuietly` on the item: the `saved` hook would re-derive the header from the lines and
    // erase the disagreement this test exists for.
    (new InvoiceItem([
        'invoice_id' => $invoice->id, 'description' => 'Base Rent', 'type' => 'base_rent',
        'amount' => 11999, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 11999,
    ]))->saveQuietly();

    $rows = TenantLedger::for($this->tenant);

    expect($rows->pluck('debit')->all())->toBe([11999.0, 1.0])
        ->and($rows->last()['description'])->toBe($invoice->periodLabel())
        ->and(TenantLedger::closingBalance($this->tenant))->toBe(12000.0)
        ->toBe($invoice->fresh()->collectableBalance());
});
