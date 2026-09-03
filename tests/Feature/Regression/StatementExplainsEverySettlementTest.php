<?php

/*
|--------------------------------------------------------------------------
| A statement must explain its own arithmetic (2026-08-17)
|--------------------------------------------------------------------------
| An invoice's balance falls through FOUR channels. The statement listed exactly one of them —
| payments — while its "Total Paid" figure counted all four. Produced from real data after a
| termination:
|
|     Total Billed     532,600
|     Total Settled    232,100
|     Total Received   152,000     ← the only settlements printed anywhere
|
| The missing 80,100 was an applied credit note that appeared on no line of the document. A tenant
| cannot query a number they cannot see, and this is the page they are sent when they ask what they
| owe.
|
| Two smaller defects on the same page, both found by reading the rendered PDF:
|   · the period column printed `period_start` alone, so a 240,300 April–June quarterly invoice read
|     "Apr 2026" — one month's rent at three times the rate
|   · the status column was 8% wide, breaking "Partially paid" across lines as "PARTIAL LY PAID"
|
| The draft rule is tested here rather than assumed: this same service renders the PORTAL and the
| mobile API statement, and `credit_notes.status` DEFAULTS to draft at the column.
|
| **The other two channels went unprinted for another nine days (AR-GL-03, 2026-08-26).** Fixing the
| credit note left the page listing TWO of the four, and the two still missing were applied
| on-account tenant credit and a netted security deposit. The deposit is the worse omission: on a
| final move-out statement it is usually the largest single settlement the tenant will ever see, and
| it is the one they are most likely to query. Both now render in one "Other settlements" section
| with a KIND column — one table rather than two, because both answer the same question and carry
| the same four facts.
*/

use App\Models\CreditNote;
use App\Models\DepositApplication;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\TenantCreditApplication;
use App\Services\TenantStatementPdfService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\View;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->tenant = makeTenant();
    $this->unit = makeUnit($this->asset);
    $this->lease = makeLease($this->unit, $this->tenant, ['status' => 'active']);
    $this->svc = app(TenantStatementPdfService::class);
    CarbonImmutable::setTestNow('2026-08-17');
});

afterEach(fn () => CarbonImmutable::setTestNow());

/** An issued invoice with an applied credit note against it. */
function statementInvoiceWithCredit($ctx, float $total, float $credited): Invoice
{
    $invoice = makeInvoice($ctx->lease, [
        'asset_id' => $ctx->asset->id,
        'status' => 'partially_paid',
        'issue_date' => '2026-07-01',
        'period_start' => '2026-07-01',
        'period_end' => '2026-09-30',
        'total' => $total,
        'credit_applied_amount' => $credited,
        'paid_amount' => $credited,
        'balance' => $total - $credited,
    ]);

    CreditNote::create([
        'tenant_id' => $ctx->tenant->id,
        'invoice_id' => $invoice->id,
        'asset_id' => $ctx->asset->id,
        'status' => 'applied',
        // INSIDE the statement's window. The clock here is frozen at 17 August, so the old 31 August
        // was a credit note dated a FORTNIGHT IN THE FUTURE — an arbitrary fixture value that only
        // ever passed because the statement applied no upper bound at all and listed rows after the
        // date it printed (SW-154). A document dated after the statement's end date does not belong
        // on it, so the date moved rather than the assertion.
        'issue_date' => '2026-08-05',
        'subtotal' => $credited,
        'total' => $credited,
        'applied_amount' => $credited,
        'balance' => 0,
        'reason' => 'adjustment',
    ]);

    return $invoice;
}

it('lists an applied credit note, so Total Settled can be reconciled from the page', function () {
    statementInvoiceWithCredit($this, 240300, 80100);

    $data = $this->svc->data($this->tenant);

    // The gap this closes: settled (80,100) with nothing received, and until now nothing printed.
    expect($data['summary']['total_paid'])->toBe(80100.0)
        ->and($data['payments'])->toHaveCount(0)
        ->and($data['credits'])->toHaveCount(1)
        ->and((float) $data['credits']->first()->applied_amount)->toBe(80100.0);

    // Settled must equal what the page itself accounts for — payments plus credits. That equality
    // IS the fix; asserting only that a credits key exists would pass on an empty collection.
    $accountedFor = (float) $data['payments']->sum('amount') + (float) $data['credits']->sum('applied_amount');
    expect($accountedFor)->toBe($data['summary']['total_paid']);
});

it('never shows a DRAFT credit note — the portal and the API render this same statement', function () {
    statementInvoiceWithCredit($this, 240300, 80100);

    CreditNote::create([
        'tenant_id' => $this->tenant->id,
        'asset_id' => $this->asset->id,
        // Not passed at all in the real path: the column DEFAULTS to draft, which is how this leaks.
        'issue_date' => '2026-08-15',
        'subtotal' => 50000,
        'total' => 50000,
        'applied_amount' => 0,
        'balance' => 50000,
        'reason' => 'dispute',
    ]);

    $numbers = $this->svc->data($this->tenant)['credits']->pluck('number');

    // Paired with the control above (the applied note IS listed) — a scope that hid everything would
    // satisfy this refusal on its own and read as a pass.
    expect($numbers)->toHaveCount(1)
        ->and(CreditNote::where('tenant_id', $this->tenant->id)->count())->toBe(2);
});

it('leaves a VOID credit note off — it settles nothing', function () {
    statementInvoiceWithCredit($this, 240300, 80100);

    CreditNote::create([
        'tenant_id' => $this->tenant->id,
        'asset_id' => $this->asset->id,
        'status' => 'void',
        'issue_date' => '2026-08-10',
        'subtotal' => 9000,
        'total' => 9000,
        'applied_amount' => 0,
        'balance' => 0,
        'reason' => 'other',
    ]);

    expect($this->svc->data($this->tenant)['credits'])->toHaveCount(1);
});

it('states the period a multi-month invoice covers, not the month it opens in', function () {
    $quarter = statementInvoiceWithCredit($this, 240300, 0);

    // "Apr 2026" against a quarter is how a tenant comes to believe one month costs 240,300.
    expect($quarter->periodLabel())->toBe('Jul – Sep 2026');

    $monthly = makeInvoice($this->lease, [
        'asset_id' => $this->asset->id,
        'period_start' => '2026-07-01', 'period_end' => '2026-07-31',
    ]);
    expect($monthly->periodLabel())->toBe('Jul 2026');

    // An annual cycle straddling December needs both years or it reads as a 2-month period.
    $annual = makeInvoice($this->lease, [
        'asset_id' => $this->asset->id,
        'period_start' => '2026-12-01', 'period_end' => '2027-11-30',
    ]);
    expect($annual->periodLabel())->toBe('Dec 2026 – Nov 2027');
});

it('renders the credits section into the document itself', function () {
    statementInvoiceWithCredit($this, 240300, 80100);

    // The service can hold the figures and the template still drop them — which is precisely how the
    // settlement went missing. Render the real view.
    $html = View::make('tenants.statement', $this->svc->data($this->tenant))->render();

    expect($html)->toContain(__('admin.statement.credits_applied'))
        ->and($html)->toContain('80,100.00')
        // The reason reads as WORDS, in the reader's language. `toContain` matched the TAIL of a
        // raw translation key for as long as this test existed: the fixture wrote free text into
        // `reason`, the template renders it through `admin.enums.credit_note_reason.<reason>`, and
        // `__()` returns the KEY when there is none — so the tenant's statement printed
        // "admin.enums.credit_note_reason.Unearned billing on termination" and this assertion was
        // satisfied by the last five words of it. The column is a registered value set since
        // 2026-09-02, so free text is now refused at the model and the verbatim branch survives
        // only for rows written before that; `Translate::orFallback()` is what still prints them.
        ->and($html)->toContain(__('admin.enums.credit_note_reason.adjustment'))
        ->and($html)->not->toContain('admin.enums')
        ->and($html)->toContain('Jul – Sep 2026');
});

/** An issued invoice settled by something that is neither a payment nor a credit note. */
function statementInvoiceSettledOffLedger($ctx, float $total, float $settled): Invoice
{
    return makeInvoice($ctx->lease, [
        'asset_id' => $ctx->asset->id,
        'status' => 'partially_paid',
        'issue_date' => '2026-07-01',
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
        'total' => $total,
        'paid_amount' => $settled,
        'balance' => $total - $settled,
    ]);
}

it('lists applied on-account credit — the third channel', function () {
    $invoice = statementInvoiceSettledOffLedger($this, 50000, 12000);

    TenantCreditApplication::create([
        'tenant_id' => $this->tenant->id,
        'invoice_id' => $invoice->id,
        'asset_id' => $this->asset->id,
        'amount' => 12000,
        'entry_date' => '2026-08-01',
        'notes' => 'Overpayment from June',
    ]);

    $data = $this->svc->data($this->tenant);

    expect($data['summary']['total_paid'])->toBe(12000.0)
        ->and($data['payments'])->toHaveCount(0)
        ->and($data['credits'])->toHaveCount(0)
        ->and($data['settlements'])->toHaveCount(1)
        ->and($data['settlements']->first()['amount'])->toBe(12000.0)
        ->and($data['settlements']->first()['invoice'])->toBe($invoice->number);
});

it('lists a netted security deposit — the fourth, and the one a move-out turns on', function () {
    $invoice = statementInvoiceSettledOffLedger($this, 90000, 90000);

    DepositApplication::create([
        'lease_id' => $this->lease->id,
        'tenant_id' => $this->tenant->id,
        'invoice_id' => $invoice->id,
        'asset_id' => $this->asset->id,
        'amount' => 90000,
        'entry_date' => '2026-08-10',
        'notes' => 'Deposit netted on move-out',
    ]);

    $data = $this->svc->data($this->tenant);

    expect($data['settlements'])->toHaveCount(1)
        ->and($data['settlements']->first()['amount'])->toBe(90000.0);

    // Everything the page accounts for must equal what it says was settled. That equality is the
    // whole point — a `settlements` key that existed but stayed empty would satisfy a weaker test.
    $accountedFor = (float) $data['payments']->sum('amount')
        + (float) $data['credits']->sum('applied_amount')
        + (float) $data['settlements']->sum('amount');

    expect($accountedFor)->toBe($data['summary']['total_paid']);
});

it('reconciles a statement settled through all FOUR channels at once', function () {
    // The case the original defect was found on: a termination where several channels ran together
    // and the printed lines did not add up to the printed total.
    $invoice = makeInvoice($this->lease, [
        'asset_id' => $this->asset->id,
        'status' => 'paid',
        'issue_date' => '2026-07-01',
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
        'total' => 100000,
        'credit_applied_amount' => 20000,
        'paid_amount' => 100000,
        'balance' => 0,
    ]);

    $payment = Payment::create([
        'tenant_id' => $this->tenant->id,
        'amount' => 40000,
        'method' => 'cash',
        'status' => 'captured',
        'payment_date' => '2026-08-02',
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 40000]);

    CreditNote::create([
        'tenant_id' => $this->tenant->id, 'invoice_id' => $invoice->id, 'asset_id' => $this->asset->id,
        'status' => 'applied', 'issue_date' => '2026-08-03',
        'subtotal' => 20000, 'total' => 20000, 'applied_amount' => 20000, 'balance' => 0,
        'reason' => 'dispute',
    ]);

    TenantCreditApplication::create([
        'tenant_id' => $this->tenant->id, 'invoice_id' => $invoice->id, 'asset_id' => $this->asset->id,
        'amount' => 15000, 'entry_date' => '2026-08-04',
    ]);

    DepositApplication::create([
        'lease_id' => $this->lease->id, 'tenant_id' => $this->tenant->id, 'invoice_id' => $invoice->id,
        'asset_id' => $this->asset->id, 'amount' => 25000, 'entry_date' => '2026-08-05',
    ]);

    $data = $this->svc->data($this->tenant);

    $accountedFor = (float) $data['payments']->sum('amount')
        + (float) $data['credits']->sum('applied_amount')
        + (float) $data['settlements']->sum('amount');

    expect($data['summary']['total_paid'])->toBe(100000.0)
        ->and($accountedFor)->toBe(100000.0)
        // …and each channel is separately visible, not merged into one unexplained figure.
        ->and($data['payments'])->toHaveCount(1)
        ->and($data['credits'])->toHaveCount(1)
        ->and($data['settlements'])->toHaveCount(2);
});

it('renders the other-settlements section into the document itself', function () {
    // The data being right is not the same as the page printing it — the credits half of this fix
    // needed exactly this assertion too.
    $invoice = statementInvoiceSettledOffLedger($this, 90000, 90000);

    DepositApplication::create([
        'lease_id' => $this->lease->id, 'tenant_id' => $this->tenant->id, 'invoice_id' => $invoice->id,
        'asset_id' => $this->asset->id, 'amount' => 90000, 'entry_date' => '2026-08-10',
    ]);

    $html = View::make('tenants.statement', $this->svc->data($this->tenant))->render();

    expect($html)->toContain(__('admin.statement.other_settlements'))
        ->and($html)->toContain(__('admin.statement.settlement_kinds.deposit'))
        ->and($html)->toContain('90,000.00');
});

it('leaves the section off a statement that needs no explaining', function () {
    // An empty "Other settlements" table on every ordinary statement is noise — the same rule the
    // credits table follows.
    statementInvoiceSettledOffLedger($this, 50000, 0);

    $html = View::make('tenants.statement', $this->svc->data($this->tenant))->render();

    expect($html)->not->toContain(__('admin.statement.other_settlements'));
});
