<?php

use App\Enums\PartyType;
use App\Enums\UnitOwnershipStatus;
use App\Models\Charge;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\UnitOwnership;
use App\Services\Accounting\FiscalCalendar;
use App\Services\BillUnitOwnershipsService;
use App\Support\TenantScope;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * A unit owner is billed the service charge — the point of the whole module.
 *
 * The scenario is the owner-occupier of plan 08 §6: he bought the shop, he trades from it himself,
 * he has NO LEASE, and he owes صيانة every month. Until phase 2 there was no shape in the system
 * that could carry that debt.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);

    $this->asset = makeAsset(['code' => 'OW']);
    $this->unit = makeUnit($this->asset);
    $this->buyer = makeTenant(['party_type' => PartyType::UnitOwner->value]);

    $this->ownership = UnitOwnership::create([
        'asset_id' => $this->asset->id,
        'unit_id' => $this->unit->id,
        'tenant_id' => $this->buyer->id,
        'status' => UnitOwnershipStatus::HandedOver->value,
        'started_at' => '2026-01-01',
        'payment_terms_days' => 10,
    ]);
});

function assessment(array $attrs = []): Charge
{
    return Charge::create(array_merge([
        'unit_ownership_id' => test()->ownership->id,
        'name' => 'Service charge',
        'type' => 'service_charge',
        'amount' => 3000,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'vat_applicable' => true,
        'is_active' => true,
        'start_date' => '2026-01-01',
    ], $attrs));
}

function runAssessments(string $month = '2026-03'): array
{
    return app(BillUnitOwnershipsService::class)->runForPeriod(CarbonImmutable::parse($month.'-01'));
}

it('bills an owner-occupier who has no lease at all', function () {
    assessment();

    $stats = runAssessments();

    $invoice = Invoice::query()->where('unit_ownership_id', $this->ownership->id)->firstOrFail();

    expect($stats['created'])->toBe(1)
        // The whole design decision, visible in one row: no lease, a real receivable, and the party
        // is an ordinary `tenants` record so every AR surface already serves them.
        ->and($invoice->lease_id)->toBeNull()
        ->and($invoice->tenant_id)->toBe($this->buyer->id)
        ->and($invoice->asset_id)->toBe($this->asset->id)
        ->and((float) $invoice->subtotal)->toBe(3000.00)
        ->and((float) $invoice->balance)->toBeGreaterThan(3000.00)   // + VAT
        ->and($invoice->number)->toStartWith('INV-OW-')
        ->and($invoice->due_date->toDateString())->not->toBeEmpty();
});

it('posts the assessment to the ledger against the right property', function () {
    assessment();
    runAssessments();

    $invoice = Invoice::query()->where('unit_ownership_id', $this->ownership->id)->firstOrFail();

    // Driven through the real sweep, not LedgerPoster::post() — the house rule for a GL source.
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    $entry = JournalEntry::query()
        // `getMorphClass()`, never `::class` — with a morph map registered the column stores an
        // ALIAS, so a test comparing the FQCN silently finds nothing and reads as "no entry
        // was posted". This asks the model how it identifies itself, which is right either way.
        ->where('source_type', $invoice->getMorphClass())->where('source_id', $invoice->id)
        ->firstOrFail();

    expect($entry->asset_id)->toBe($this->asset->id)
        ->and(round((float) $entry->lines()->sum('debit'), 2))
        ->toBe(round((float) $entry->lines()->sum('credit'), 2));
});

it('is idempotent — a second run bills nothing more', function () {
    assessment();

    expect(runAssessments()['created'])->toBe(1)
        ->and(runAssessments()['created'])->toBe(0)
        ->and(Invoice::query()->where('unit_ownership_id', $this->ownership->id)->count())->toBe(1);
});

it('does not bill before handover', function () {
    assessment();
    $this->ownership->update(['status' => UnitOwnershipStatus::Contracted->value]);

    // The operator still carries the unit's cost until the keys change hands.
    expect(runAssessments()['created'])->toBe(0);

    // Control — the same ownership bills the moment it IS handed over, so the refusal is about the
    // status and not about the fixture.
    $this->ownership->update(['status' => UnitOwnershipStatus::HandedOver->value]);
    expect(runAssessments()['created'])->toBe(1);
});

it('prorates a mid-month handover and splits a resale between both owners', function () {
    // The seller holds it to the 10th of March; the buyer from the 11th.
    $this->ownership->update(['ended_at' => '2026-03-10']);
    assessment();

    $second = UnitOwnership::create([
        'asset_id' => $this->asset->id,
        'unit_id' => $this->unit->id,
        'tenant_id' => makeTenant(['party_type' => PartyType::UnitOwner->value])->id,
        'status' => UnitOwnershipStatus::HandedOver->value,
        'started_at' => '2026-03-11',
    ]);

    Charge::create([
        'unit_ownership_id' => $second->id, 'name' => 'Service charge', 'type' => 'service_charge',
        'amount' => 3000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => true, 'is_active' => true, 'start_date' => '2026-03-11',
    ]);

    runAssessments();

    $sellerPaid = (float) Invoice::where('unit_ownership_id', $this->ownership->id)->value('subtotal');
    $buyerPaid = (float) Invoice::where('unit_ownership_id', $second->id)->value('subtotal');

    // 10/31 and 21/31 of the month. Between them they pay for the month exactly once — neither
    // owner subsidises the other, and the mall is not short.
    expect($sellerPaid)->toBe(round(3000 * 10 / 31, 2))
        ->and($buyerPaid)->toBe(round(3000 * 21 / 31, 2))
        ->and(round($sellerPaid + $buyerPaid, 2))->toBe(3000.00);
});

it('bills a co-owner only their share', function () {
    $this->ownership->update(['ownership_share_pct' => 40]);
    assessment();

    runAssessments();

    expect((float) Invoice::where('unit_ownership_id', $this->ownership->id)->value('subtotal'))
        ->toBe(1200.00);
});

it('refuses a charge or an invoice that belongs to both or to neither', function () {
    $lease = makeLease(makeUnit($this->asset));

    expect(fn () => Charge::create([
        'lease_id' => $lease->id, 'unit_ownership_id' => $this->ownership->id,
        'name' => 'Both', 'type' => 'service_charge', 'amount' => 1, 'currency' => 'EGP',
        'frequency' => 'monthly', 'is_active' => true,
    ]))->toThrow(DomainException::class);

    expect(fn () => Charge::create([
        'name' => 'Neither', 'type' => 'service_charge', 'amount' => 1, 'currency' => 'EGP',
        'frequency' => 'monthly', 'is_active' => true,
    ]))->toThrow(DomainException::class);

    // Control — exactly one is accepted, so the refusals are about the pairing.
    expect(assessment(['name' => 'Just the ownership'])->exists)->toBeTrue();
});

it('shows the owner invoice on the property it belongs to', function () {
    assessment();
    runAssessments();

    $this->actingAs(makeUser('super_admin', [$this->asset->id]));

    // The reason phase 2a came first: scoped through `lease.unit` this invoice would have been
    // invisible on every property-scoped screen, because it has no lease to walk.
    asTenant($this->asset, function () {
        expect(TenantScope::applyTo(Invoice::query())->whereNotNull('unit_ownership_id')->count())->toBe(1);
    });
});
