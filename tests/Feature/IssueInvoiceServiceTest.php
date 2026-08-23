<?php

use App\Contracts\BillableAgreement;
use App\Models\Invoice;
use App\Models\Lease;
use App\Services\IssueInvoiceService;

/**
 * The contract of the one seam an AR document is born through.
 *
 * Eight services used to hand-build the identical header, each re-deriving the totals from lines it
 * was about to write anyway and each hand-seeding `paid_amount`/`balance` — the two fields
 * `Invoice::recomputeTotals()` owns. This pins the rule they now share, and the last test pins that
 * they cannot quietly stop sharing it.
 */
beforeEach(function () {
    $this->lease = makeLease(makeUnit(makeAsset()), null, ['payment_terms_days' => 14]);
});

/** @param  array<int, array<string, mixed>>  $items */
function issueWith(
    Lease $lease,
    array $items,
    ?string $dueDate = null,
    ?int $tenantId = null,
    ?string $currency = null,
): Invoice {
    return app(IssueInvoiceService::class)->issue(
        agreement: $lease,
        items: $items,
        issueDate: '2026-03-01',
        periodStart: '2026-03-01',
        periodEnd: '2026-03-31',
        dueDate: $dueDate,
        tenantId: $tenantId,
        currency: $currency,
    );
}

/** @return array<string, mixed> */
function issueLine(float $amount, float $vat, string $type = 'base_rent'): array
{
    return [
        'description' => 'Line '.$type,
        'type' => $type,
        'amount' => $amount,
        'vat_rate' => $amount > 0 ? round($vat / $amount * 100, 2) : 0,
        'vat_amount' => $vat,
        'total' => round($amount + $vat, 2),
    ];
}

it('derives the header from its lines rather than from what the caller believes', function () {
    $invoice = issueWith($this->lease, [
        issueLine(1000.00, 140.00),
        issueLine(333.33, 46.67, 'service_charge'),
    ]);

    expect((float) $invoice->subtotal)->toBe(1333.33)
        ->and((float) $invoice->vat_amount)->toBe(186.67)
        ->and((float) $invoice->total)->toBe(1520.00)
        ->and($invoice->items()->count())->toBe(2);
});

it('seeds the AR fields the settlement channels own, and recomputeTotals agrees', function () {
    $invoice = issueWith($this->lease, [issueLine(500.00, 70.00)]);

    expect((float) $invoice->paid_amount)->toBe(0.0)
        ->and((float) $invoice->balance)->toBe(570.00);

    // Nothing has settled it, so the four-channel recompute must land on the same numbers. A seam
    // that seeded these differently from the model that owns them would only show up under payment.
    $invoice->recomputeTotals();

    expect((float) $invoice->fresh()->paid_amount)->toBe(0.0)
        ->and((float) $invoice->fresh()->balance)->toBe(570.00);
});

it('defaults the due date to the issue date plus the lease payment terms', function () {
    $invoice = issueWith($this->lease, [issueLine(100.00, 14.00)]);

    expect($invoice->due_date->toDateString())->toBe('2026-03-15');
});

it('honours an explicit due date, which the monthly run passes so a back-filled bill is not born overdue', function () {
    $invoice = issueWith($this->lease, [issueLine(100.00, 14.00)], dueDate: '2026-04-30');

    expect($invoice->due_date->toDateString())->toBe('2026-04-30');
});

it('bills the lease tenant by default and the stated debtor when a source document names one', function () {
    $other = makeTenant();

    $default = issueWith($this->lease, [issueLine(100.00, 14.00)]);
    $stated = issueWith($this->lease, [issueLine(100.00, 14.00)], tenantId: $other->id);

    expect($default->tenant_id)->toBe($this->lease->tenant_id)
        ->and($stated->tenant_id)->toBe($other->id);
});

it('takes the lease currency by default, and refuses one the column does not accept', function () {
    // The `currency` parameter exists so a fee invoice can carry the currency of the DEBT it
    // penalises rather than the lease's. It still does — but EG-07 narrowed every currency column
    // to EGP, so the only value it can carry today is EGP, and this case used to assert USD.
    //
    // Kept as two claims rather than deleted: the default still comes from the agreement, and a
    // currency outside the registered set is refused at the MODEL. That second half is the whole
    // of EG-07 — a picker was removed, and this is what makes the removal true against an import
    // or a crafted payload rather than only in the UI.
    $default = issueWith($this->lease, [issueLine(100.00, 14.00)]);
    $stated = issueWith($this->lease, [issueLine(100.00, 14.00)], currency: 'EGP');

    expect($default->currency)->toBe('EGP')
        ->and($stated->currency)->toBe('EGP');

    expect(fn () => issueWith($this->lease, [issueLine(100.00, 14.00)], currency: 'USD'))
        ->toThrow(DomainException::class);
});

it('refuses to raise an invoice with no lines', function () {
    expect(fn () => issueWith($this->lease, []))
        ->toThrow(InvalidArgumentException::class);

    // Paired with a control: the refusal must be about the empty line set, not about the fixture.
    expect(issueWith($this->lease, [issueLine(1.00, 0.14)])->exists)->toBeTrue();
});

it('lets the agreement stamp its own foreign key, so the service never asks what kind it is', function () {
    // The seam phase 2 stands on: a unit ownership returns `unit_ownership_id` from the same method
    // and nothing in this service, or downstream of it, changes.
    expect($this->lease)->toBeInstanceOf(BillableAgreement::class)
        ->and($this->lease->invoiceLinkAttributes())->toBe(['lease_id' => $this->lease->id])
        ->and($this->lease->billingTenantId())->toBe($this->lease->tenant_id)
        ->and($this->lease->billingCurrency())->toBe('EGP');

    $invoice = issueWith($this->lease, [issueLine(100.00, 14.00)]);

    expect($invoice->lease_id)->toBe($this->lease->id);
});

it('is the only place in app/ that hand-builds an invoice', function () {
    // The durable half of the refactor. Extracting the seam is worth little if the ninth caller
    // writes its own `Invoice::create([...])` — which is exactly how eight of them accumulated.
    //
    // Scope note: Filament's create page builds invoices through `handleRecordCreation` on the
    // resource (a form + relationship repeater, whose header is corrected by
    // `InvoiceItem::saved` → `Invoice::syncTotalsFromItems`), so it is not a hand-built header and
    // does not appear here.
    $hits = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        if (preg_match('/\bInvoice::create\s*\(|\bnew\s+Invoice\s*\(/', $contents) !== 1) {
            continue;
        }

        $hits[] = str_replace(app_path().'/', '', $file->getPathname());
    }

    // The sweep must have found something, or it proves nothing (a regex that matches nothing
    // passes just as happily as one that matches only the seam).
    expect($hits)->toBe(['Services/IssueInvoiceService.php']);
});
