<?php

use App\Models\CreditNote;
use App\Services\Reports\ReportService;
use App\Support\TenantScope;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;

/**
 * GAP ANALYSIS — a credit note with no lease appears in EVERY property's monthly close.
 *
 * `ReportService::scopedCreditNotes()` scopes by walking `lease → unit → asset`, with an explicit
 * `whereNull('lease_id')` branch that lets a lease-less note through unconditionally. Its comment
 * justifies that as "standalone notes stay portfolio-visible, per the resource".
 *
 * **That justification is stale.** `CreditNote` is `#[PropertyOwned]` on its own `asset_id` column,
 * so the resource hides a Mall A note from an operator scoped to Mall B — while this report shows it.
 * The list and the monthly close disagree about the same row.
 *
 * It is not a hypothetical row either. A note raised against an OWNER's assessment has no lease by
 * construction (module 37 bills ownerships, not leases), and `credit_notes.asset_id` has carried the
 * property since the denormalisation of 2026-08-15 — the same phase-2a change that moved `Invoice`
 * off the `lease.unit` chain. The invoice side was migrated; this read site was not.
 *
 * Failure scenario: Mall A issues a 5,000 credit note against an owner assessment. The operator
 * scoped to Mall B opens the monthly close and sees 5,000 of credits that belong to another mall —
 * inflating Mall B's credited total and understating its net.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->mallA = makeAsset(['code' => 'CNA']);
    $this->mallB = makeAsset(['code' => 'CNB']);
    $this->tenant = makeTenant();

    // A credit note that belongs to Mall A and has NO lease — exactly the shape an owner-assessment
    // credit takes, and the shape the `whereNull('lease_id')` branch waves through.
    $this->note = CreditNote::create([
        'number' => 'CN-TEST-0001',
        'asset_id' => $this->mallA->id,
        'lease_id' => null,
        'tenant_id' => $this->tenant->id,
        'issue_date' => CarbonImmutable::now()->startOfMonth()->addDays(3)->toDateString(),
        'status' => 'issued',
        'reason' => 'adjustment',
        'subtotal' => 5000,
        'vat_amount' => 0,
        'total' => 5000,
        'currency' => 'EGP',
    ]);

    // Both malls assigned, so the scope under test is the SELECTED property rather than the
    // operator's assignment — otherwise the refusal would pass for the wrong reason.
    $this->actingAs(makeUser('accounting', [$this->mallA->id, $this->mallB->id]));
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('counts the note in its own property — the control', function () {
    Filament::setTenant($this->mallA);
    expect(TenantScope::currentAssetId())->toBe($this->mallA->id);

    $close = app(ReportService::class)->monthlyClose(CarbonImmutable::now());

    expect($close['credit_notes']['count'])->toBe(1)
        ->and($close['credit_notes']['total_issued'])->toBe(5000.0);
});

it('keeps another property\'s lease-less credit note out of this property\'s monthly close', function () {
    Filament::setTenant($this->mallB);
    expect(TenantScope::currentAssetId())->toBe($this->mallB->id);

    $close = app(ReportService::class)->monthlyClose(CarbonImmutable::now());

    expect($close['credit_notes']['count'])->toBe(0)
        ->and($close['credit_notes']['total_issued'])->toBe(0.0);
});
