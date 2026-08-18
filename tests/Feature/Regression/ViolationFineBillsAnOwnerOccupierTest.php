<?php

use App\Enums\PartyType;
use App\Enums\UnitOwnershipStatus;
use App\Models\Invoice;
use App\Models\JournalLine;
use App\Models\UnitOwnership;
use App\Models\Violation;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerReportService;
use App\Services\BillViolationFineService;
use App\Services\Reconciliation\BooksReconciliationService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * GAP ANALYSIS, module 31 — an owner-occupier can be fined but never billed.
 *
 * `BillViolationFineService` resolves the debtor's **active lease** in the violation's property and
 * refuses when there is none. That was right when every occupier held a lease. Module 37 (August
 * 2026) added the other kind: a unit OWNER who bought the shop, trades from it himself, and has no
 * lease at all — `BillUnitOwnershipsService` bills his صيانة against the ownership instead.
 *
 * The violation register never learned the difference. Its tenant picker is an unfiltered
 * `EntitySelect` over `Tenant`, and a unit owner IS a `tenants` row (`party_type = unit_owner`, one
 * table for both parties by module 37's design) — so an operator can record a violation against an
 * owner-occupier, set a fine, see it in the register, and then find the fine unbillable.
 *
 * Nothing about the plumbing prevents it: `UnitOwnership implements BillableAgreement`, and
 * `IssueInvoiceService::issue()` takes a `BillableAgreement`, not a `Lease` — which is exactly how
 * the monthly assessment is already raised. Only this service's lease lookup is lease-shaped.
 *
 * Realistic case: an owner-occupier stores stock in the common corridor. The mall fines him. The
 * fine can be recorded and never collected.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);

    $this->svc = app(BillViolationFineService::class);
    $this->asset = makeAsset(['code' => 'VO']);
});

it('bills the fine of a tenant who holds a lease — the control', function () {
    $tenant = makeTenant();
    makeLease(makeUnit($this->asset), $tenant, ['status' => 'active']);

    $invoice = $this->svc->bill(Violation::create([
        'asset_id' => $this->asset->id,
        'tenant_id' => $tenant->id,
        'category' => 'safety',
        'description' => 'Blocked fire exit',
        'fine_amount' => 1000,
        'violation_date' => '2026-03-15',
        'status' => 'open',
    ]));

    expect($invoice)->toBeInstanceOf(Invoice::class)
        ->and((float) $invoice->total)->toBe(1000.0);
});

it('bills the fine of an owner-occupier, who has no lease', function () {
    $owner = makeTenant(['party_type' => PartyType::UnitOwner->value]);
    $unit = makeUnit($this->asset);

    UnitOwnership::create([
        'asset_id' => $this->asset->id,
        'unit_id' => $unit->id,
        'tenant_id' => $owner->id,
        'status' => UnitOwnershipStatus::HandedOver->value,
        'started_at' => '2026-01-01',
        'payment_terms_days' => 10,
    ]);

    $violation = Violation::create([
        'asset_id' => $this->asset->id,
        'tenant_id' => $owner->id,
        'category' => 'safety',
        'description' => 'Stock stored in the common corridor',
        'fine_amount' => 2500,
        'violation_date' => '2026-03-15',
        'status' => 'open',
    ]);

    $invoice = $this->svc->bill($violation);

    // A fine is a penalty, not consideration for a supply — VAT-exempt, exactly as for a lessee.
    expect((float) $invoice->total)->toBe(2500.0)
        ->and($invoice->tenant_id)->toBe($owner->id)
        ->and($invoice->asset_id)->toBe($this->asset->id)
        // Billed against the OWNERSHIP, since that is the agreement he actually holds.
        ->and($invoice->unit_ownership_id)->not->toBeNull()
        ->and($invoice->lease_id)->toBeNull()
        ->and($violation->fresh()->isBilled())->toBeTrue();
});

it('still refuses when the party holds neither a lease nor an ownership here', function () {
    // The refusal must survive the fix — a violation against a party with no agreement in this
    // property has nothing to bill against, and inventing one would be worse than refusing.
    $stranger = makeTenant();

    expect(fn () => $this->svc->bill(Violation::create([
        'asset_id' => $this->asset->id,
        'tenant_id' => $stranger->id,
        'category' => 'safety',
        'description' => 'Blocked exit',
        'fine_amount' => 500,
        'violation_date' => '2026-03-15',
        'status' => 'open',
    ])))->toThrow(DomainException::class);
});

it('posts the owner-occupier fine to misc_income and ties AR out through the real sweep', function () {
    // Driving the service is necessary but not sufficient — the sweep is what proves production
    // actually posts (CLAUDE.md: a GL test calling LedgerPoster directly proves only arithmetic).
    $owner = makeTenant(['party_type' => PartyType::UnitOwner->value]);
    UnitOwnership::create([
        'asset_id' => $this->asset->id,
        'unit_id' => makeUnit($this->asset)->id,
        'tenant_id' => $owner->id,
        'status' => UnitOwnershipStatus::HandedOver->value,
        'started_at' => '2026-01-01',
        'payment_terms_days' => 10,
    ]);

    $this->svc->bill(Violation::create([
        'asset_id' => $this->asset->id,
        'tenant_id' => $owner->id,
        'category' => 'safety',
        'description' => 'Stock in the corridor',
        'fine_amount' => 2500,
        'violation_date' => '2026-03-15',
        'status' => 'open',
    ]));

    test()->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    $credited = (float) JournalLine::whereHas('account', fn ($q) => $q->where('code', '42101001'))->sum('credit');

    expect($credited)->toBe(2500.0)                                                  // Miscellaneous Income
        ->and(app(LedgerReportService::class)->trialBalance()['balanced'])->toBeTrue()
        ->and(app(BooksReconciliationService::class)->glTieOut()['ar']['delta'])->toBe(0.0);
});
