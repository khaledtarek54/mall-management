<?php

use App\Models\Charge;
use App\Models\DocumentTemplate;
use App\Models\LeaseClause;
use App\Services\LeaseAgreementPdfService;

/**
 * The lease agreement is generated from the lease's own terms (gap O1).
 *
 * A lease held an UPLOADED pdf and nothing produced one, so terms just keyed into the system were
 * retyped into a Word file to make the contract — which is where the two copies drift: the signed
 * paper says one rent and the system bills another, with nothing to reconcile them.
 *
 * Asserted on `PdfDocument::html()` — the seam that exists because a test which inflated a PDF's
 * compressed streams to find out what it says will not be written, and the one written instead
 * asserts on the service's inputs and proves nothing.
 */
beforeEach(function () {
    $this->asset = makeAsset();
    $this->unit = makeUnit($this->asset, ['code' => 'A-101', 'area_sqm' => 250]);
    $this->lease = makeLease($this->unit, makeTenant(['name' => 'Cilantro']), [
        'status' => 'active',
        'base_rent_monthly' => 100000,
        'security_deposit' => 300000,
    ]);
});

/** The HTML this service really produces, in the given language. */
function agreementHtml($lease, ?string $locale = null): string
{
    return app(LeaseAgreementPdfService::class)->document($lease->fresh(), $locale)->html();
}

it('states the premises, the term and the parties', function () {
    $html = agreementHtml($this->lease, 'en');

    expect($html)->toContain('Lease Agreement')
        ->and($html)->toContain($this->lease->reference)
        ->and($html)->toContain('Cilantro')
        ->and($html)->toContain('A-101')
        // The area, which is what the rent is priced on.
        ->and($html)->toContain('250.00');
});

it('prints the CHARGE SCHEDULE, because that is what will actually be billed', function () {
    // The whole reason this document is worth generating. `MonthlyBillingService` reads `charges`;
    // `base_rent_monthly` is the negotiated headline and can differ — a relief window, an
    // amendment or a mid-term escalation all live in the schedule. A contract printed from the
    // headline would disagree with the invoices raised under it.
    Charge::create([
        'lease_id' => $this->lease->id,
        'name' => 'Base Rent',
        'type' => 'base_rent',
        'amount' => 92500,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'start_date' => now()->toDateString(),
        'is_active' => true,
    ]);

    $html = agreementHtml($this->lease, 'en');

    // The schedule's figure…
    expect($html)->toContain('92,500.00')
        // …AND the headline beside it: both are true and they answer different questions.
        ->and($html)->toContain('100,000.00');
});

it('says so when a lease will bill nothing at all', function () {
    // A lease with no schedule bills NOTHING — a fact about this agreement the parties should read
    // on it rather than discover at the first month-end.
    $html = agreementHtml($this->lease, 'en');

    expect($html)->toContain('No charge schedule');
});

it('prints the clauses the lease actually carries', function () {
    LeaseClause::create([
        'lease_id' => $this->lease->id,
        'type' => LeaseClause::TYPE_EXCLUSIVITY,
        'summary' => 'No second speciality coffee operator on level 2.',
    ]);

    expect(agreementHtml($this->lease, 'en'))
        ->toContain('No second speciality coffee operator on level 2.');
});

it('watermarks a draft, so an unsigned copy cannot pass for a contract', function () {
    // The reason the invoice carries a void stamp: an unexecuted agreement and a signed one differ
    // by a small chip, and at arm's length a printed draft reads as a contract.
    //
    // Asserted on the DECISION, not the markup. The watermark is applied to mpdf and never reaches
    // the HTML — a first version looked for the draft label in the rendered page and passed on the
    // status chip in the masthead, which prints the same words, so removing draft from the list
    // left it green. Measured, not assumed.
    $service = app(LeaseAgreementPdfService::class);

    $this->lease->update(['status' => 'draft']);
    expect($service->watermark($this->lease->fresh()))->toBe(__('admin.statuses.lease.draft'));

    // A SEPARATE lease for the terminal case: `Lease::updating` refuses to move a terminated
    // tenancy, which is right — and mutating one through that state to write a test would be
    // testing a path the application does not have.
    $ended = makeLease(makeUnit($this->asset), makeTenant(), ['status' => 'terminated']);
    expect($service->watermark($ended))->toBe(__('admin.statuses.lease.terminated'));

    // …and a lease IN FORCE carries none, or the stamp would mean nothing.
    $this->lease->update(['status' => 'active']);
    expect($service->watermark($this->lease->fresh()))->toBeNull();
});

it('carries the operator\'s standing terms, and prints no heading when there are none', function () {
    // No floor, deliberately: this system does not know what these parties agreed, and a heading
    // over a gap on a CONTRACT reads as a missing term.
    expect(agreementHtml($this->lease, 'en'))->not->toContain('Governing law: Egypt.');

    DocumentTemplate::create([
        'key' => 'lease.agreement_terms',
        'asset_id' => $this->asset->id,
        'body_en' => 'Governing law: Egypt.',
    ]);

    expect(agreementHtml($this->lease, 'en'))->toContain('Governing law: Egypt.');
});

it('is written in the reader\'s language', function () {
    // A contract is addressed to the party signing it. The operator can still pick on download —
    // `DocumentLocale::resolve()` puts their choice first.
    expect(agreementHtml($this->lease, 'ar'))->toContain('عقد إيجار')
        ->and(agreementHtml($this->lease, 'en'))->toContain('Lease Agreement');
});
