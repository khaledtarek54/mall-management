<?php

/*
|--------------------------------------------------------------------------
| A credit note with no lines credits nothing anyone can read (2026-08-17)
|--------------------------------------------------------------------------
| Both services that raise credit notes wrote header totals and no items at all. The document
| rendered — on screen and in the PDF — as a Description / Amount / VAT / Total table with an EMPTY
| BODY above a totals block. Only the admin form, whose repeater writes the relation, ever produced
| an itemised note.
|
| That matters more here than on most documents: a credit note is what a tenant uses to REVERSE
| input VAT they have already claimed, and this one asked them to do it against a blank table. It is
| the same document that was made identifiable five days earlier by adding the seller's registration
| particulars — a credit note nobody can read is not much better than one nobody can attribute.
|
| Spotted by an operator opening one and asking why the items section was empty.
|
| The sweep at the bottom is the point of the file: it discovers the creators rather than listing
| them, because "enumerate ALL peers" is a lesson this codebase has already paid for twice — the GL
| hardening that missed VendorBill for three weeks, and the seller particulars that missed this very
| document for five days.
*/

use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Models\Charge;
use App\Models\CreditNote;
use App\Services\CamReconciliationService;
use App\Services\LeaseTerminationService;
use Carbon\CarbonImmutable;
use Symfony\Component\Finder\Finder;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->tenant = makeTenant();
    $this->unit = makeUnit($this->asset, ['area_sqm' => 100]);
    CarbonImmutable::setTestNow('2026-08-17');
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('describes the unearned billing it credits on a termination', function () {
    $lease = makeLease($this->unit, $this->tenant, [
        'status' => 'active', 'commencement_date' => '2026-01-01', 'expiry_date' => '2028-12-31',
    ]);

    $invoice = makeInvoice($lease, [
        'asset_id' => $this->asset->id, 'status' => 'issued',
        'period_start' => '2026-07-01', 'period_end' => '2026-09-30',
        'subtotal' => 240300, 'vat_amount' => 0, 'total' => 240300, 'balance' => 240300,
    ]);
    // The line must point at a MONTHLY charge, because that is what makes it time-apportioned —
    // a one-off (a fine, a utility recharge) is earned in full and must never be clawed back.
    // A really-billed line always carries `charge_id`; a fixture without one credits nothing.
    $charge = Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'amount' => 80100, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0,
        'start_date' => '2026-01-01', 'is_active' => true,
    ]);

    $invoice->items()->create([
        'type' => 'base_rent', 'description' => 'Rent Jul–Sep', 'charge_id' => $charge->id,
        'amount' => 240300, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 240300,
    ]);

    app(LeaseTerminationService::class)->terminate($lease, [
        'termination_date' => '2026-08-31',
        'reason' => 'Tenant leaving',
        'cancel_open_invoices' => false,
        'credit_unearned' => true,
    ]);

    $note = CreditNote::latest('id')->firstOrFail();

    // The header always said the amount; nothing said what for.
    expect($note->items)->toHaveCount(1)
        ->and($note->items->first()->description)->toContain($invoice->number)
        ->and(round((float) $note->items->sum('total'), 2))->toBe(round((float) $note->total, 2));
});

it('splits the termination credit BY VAT RATE, never into one blended line', function () {
    $lease = makeLease($this->unit, $this->tenant, [
        'status' => 'active', 'commencement_date' => '2026-01-01', 'expiry_date' => '2028-12-31',
    ]);

    $invoice = makeInvoice($lease, [
        'asset_id' => $this->asset->id, 'status' => 'issued',
        'period_start' => '2026-07-01', 'period_end' => '2026-09-30',
        'subtotal' => 225000, 'vat_amount' => 6300, 'total' => 231300, 'balance' => 231300,
    ]);

    // The ordinary shape of a quarterly invoice: rent is EXEMPT, service charge is standard-rated.
    foreach ([['base_rent', 180000, 0.0, 0.0], ['service_charge', 45000, 14.0, 6300.0]] as [$type, $amount, $rate, $vat]) {
        $charge = Charge::create([
            'lease_id' => $lease->id, 'name' => $type, 'type' => $type,
            'amount' => $amount / 3, 'currency' => 'EGP', 'frequency' => 'monthly',
            'vat_applicable' => $rate > 0, 'vat_rate' => $rate,
            'start_date' => '2026-01-01', 'is_active' => true,
        ]);

        $invoice->items()->create([
            'type' => $type, 'description' => $type, 'charge_id' => $charge->id,
            'amount' => $amount, 'vat_rate' => $rate, 'vat_amount' => $vat,
            'total' => $amount + $vat,
        ]);
    }

    app(LeaseTerminationService::class)->terminate($lease, [
        'termination_date' => '2026-08-31', 'reason' => 'Tenant leaving',
        'cancel_open_invoices' => false, 'credit_unearned' => true,
    ]);

    $note = CreditNote::latest('id')->firstOrFail();
    $rates = $note->items->pluck('vat_rate')->map(fn ($r) => (float) $r)->sort()->values()->all();

    // One blended line would have read 2.69% — an average, not a tax anyone can reverse against.
    expect($note->items)->toHaveCount(2)
        ->and($rates)->toBe([0.0, 14.0])
        ->and(round((float) $note->items->sum('total'), 2))->toBe(round((float) $note->total, 2));
});

it('describes the over-recovery it returns on a CAM reconciliation', function () {
    $lease = makeLease($this->unit, $this->tenant, [
        'status' => 'active', 'commencement_date' => '2026-01-01', 'expiry_date' => '2028-12-31',
    ]);

    $pool = CamExpensePool::create([
        'asset_id' => $this->asset->id, 'period_year' => 2026, 'pool_code' => 'cam',
        // Collected more than was spent → a negative true-up → a credit note.
        'total_actual_expense' => 40000, 'total_estimated_collected' => 60000,
        'estimate_basis' => CamExpensePool::BASIS_STATED, 'status' => 'draft',
    ]);

    $svc = app(CamReconciliationService::class);
    $svc->generateAllocations($pool);
    $svc->bill(CamAllocation::where('cam_expense_pool_id', $pool->id)->sole());

    $note = CreditNote::latest('id')->firstOrFail();

    expect($note->items)->toHaveCount(1)
        ->and($note->items->first()->description)->toContain('2026')
        ->and(round((float) $note->items->sum('total'), 2))->toBe(round((float) $note->total, 2));
});

it('carries the VAT on the line, so the tenant knows how much input tax to give back', function () {
    $lease = makeLease($this->unit, $this->tenant, [
        'status' => 'active', 'commencement_date' => '2026-01-01', 'expiry_date' => '2028-12-31',
    ]);

    $pool = CamExpensePool::create([
        'asset_id' => $this->asset->id, 'period_year' => 2026, 'pool_code' => 'cam',
        'total_actual_expense' => 40000, 'total_estimated_collected' => 60000,
        'estimate_basis' => CamExpensePool::BASIS_STATED, 'recovery_vat_rate' => 14,
        'status' => 'draft',
    ]);

    $svc = app(CamReconciliationService::class);
    $svc->generateAllocations($pool);
    $svc->bill(CamAllocation::where('cam_expense_pool_id', $pool->id)->sole());

    $item = CreditNote::latest('id')->firstOrFail()->items->first();

    // Reversing input tax needs the rate AND the tax, not a gross figure to work backwards from.
    expect((float) $item->vat_rate)->toBe(14.0)
        ->and(round((float) $item->vat_amount, 2))->toBe(round((float) $item->amount * 0.14, 2));
});

it('leaves no service raising a credit note without describing it', function () {
    $offenders = [];

    foreach ((new Finder)->files()->in(app_path('Services'))->name('*.php') as $file) {
        $body = (string) file_get_contents($file->getRealPath());

        if (! str_contains($body, 'CreditNote::create')) {
            continue;
        }

        // A creator must ALSO state a line. `describeAs()` is the one way to do that, so this stays
        // honest as long as nobody invents a second one — and if they do, the two assertions above
        // are what fails, not this.
        if (! str_contains($body, 'describeAs(')) {
            $offenders[] = str_replace(base_path().'/', '', $file->getRealPath());
        }
    }

    expect($offenders)->toBe([], "These raise a credit note with no line saying what it credits:\n  "
        .implode("\n  ", $offenders));
});
