<?php

use App\Enums\PartyType;
use App\Enums\UnitOwnershipStatus;
use App\Filament\Admin\Pages\ArAging;
use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Exports\InvoiceExporter;
use App\Filament\Portal\Resources\Invoices\Pages\ViewInvoice;
use App\Models\Invoice;
use App\Models\UnitOwnership;
use App\Services\Reports\ReportCsvExporter;
use App\Services\Reports\ReportService;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * **An invoice names its unit through whichever agreement raised it — on every surface.**
 *
 * `Invoice::unitCode()` has answered this since 61bb1dc6 (2026-08-18), and that commit routed the
 * two invoice TABLES because they could take a `->state()` closure. Its own message said "two
 * surfaces ask it". FIVE did not, and every one of them names a COLUMN rather than running a
 * closure — so they went on walking `lease.unit.code`, which an owner's صيانة assessment does not
 * have (`invoices.lease_id` is NULL by construction; the unit hangs off the ownership).
 *
 * Measured on `mall_management_qa` 2026-09-04: 42 of 290 invoices carry a NULL `lease_id`. One
 * invoice in seven printed a dash where its shop belongs — including on the portal View page the
 * OWNER himself opens to read his own bill, and on the collections worklist, whose entire purpose
 * is answering "who do I call, and about what".
 *
 * The seam is `Invoice::getUnitCodeAttribute()`: `unitCode()` under a name a Filament column, an
 * infolist entry, an export column and a `data_get()` can all say. Every case below drives the real
 * renderer, because the model-level rule was already right and already tested
 * (`InvoiceKnowsItsUnitThroughEitherAgreementTest`) while five screens were wrong.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->asset = makeAsset(['code' => 'OAU']);

    // The CONTROL: an ordinary lease invoice, whose unit hangs off the lease.
    $this->shopTenant = makeTenant();
    $lease = makeLease(
        makeUnit($this->asset, ['code' => 'LEASED-101']),
        $this->shopTenant,
        ['status' => 'active'],
    );
    $this->leaseInvoice = makeInvoice($lease, [
        'status' => 'overdue',
        'issue_date' => '2026-01-01',
        'due_date' => '2026-01-10',
    ]);

    // The SUBJECT: a unit owner's صيانة assessment. No lease at all.
    $this->owner = makeTenant(['party_type' => PartyType::UnitOwner->value]);
    $ownership = UnitOwnership::create([
        'asset_id' => $this->asset->id,
        'unit_id' => makeUnit($this->asset, ['code' => 'OWNED-202'])->id,
        'tenant_id' => $this->owner->id,
        'status' => UnitOwnershipStatus::HandedOver->value,
        'started_at' => '2026-01-01',
        'payment_terms_days' => 10,
    ]);

    $this->assessment = Invoice::create([
        'number' => 'INV-OAU-'.uniqid(),
        'asset_id' => $this->asset->id,
        'lease_id' => null,
        'unit_ownership_id' => $ownership->id,
        'tenant_id' => $this->owner->id,
        'status' => 'overdue',
        'issue_date' => '2026-01-01',
        'due_date' => '2026-01-10',
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
        'subtotal' => 5000, 'vat_amount' => 0, 'total' => 5000,
        'paid_amount' => 0, 'balance' => 5000, 'currency' => 'EGP',
    ]);
});

afterEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

it('answers "which unit" under the name every renderer can say, for either agreement', function () {
    expect($this->leaseInvoice->unit_code)->toBe('LEASED-101')
        ->and($this->assessment->unit_code)->toBe('OWNED-202')
        // A second ROAD to the rule, never a second answer.
        ->and($this->assessment->unit_code)->toBe($this->assessment->unitCode());
});

it('names the unit on the portal View page the OWNER opens to read his own bill', function () {
    Filament::setCurrentPanel(Filament::getPanel('portal'));
    $this->actingAs(makeTenantUser($this->owner), 'portal');

    Livewire::test(ViewInvoice::class, ['record' => $this->assessment->getRouteKey()])
        ->assertSee('OWNED-202');
});

it('still names the unit on a LEASE invoice in the portal — the control', function () {
    // A fix that blanked every unit would satisfy the refusal above and read as a pass.
    Filament::setCurrentPanel(Filament::getPanel('portal'));
    $this->actingAs(makeTenantUser($this->shopTenant), 'portal');

    Livewire::test(ViewInvoice::class, ['record' => $this->leaseInvoice->getRouteKey()])
        ->assertSee('LEASED-101');
});

it('names the unit in the top-bar search result, and loads both agreements to do it', function () {
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));

    asTenant($this->asset, function () {
        expect(InvoiceResource::getGlobalSearchResultDetails($this->assessment))->toContain('OWNED-202')
            ->and(InvoiceResource::getGlobalSearchResultDetails($this->leaseInvoice))->toContain('LEASED-101');

        expect(array_keys(InvoiceResource::getGlobalSearchEloquentQuery()->getEagerLoads()))
            ->toContain('unitOwnership.unit');
    });
});

it('names the unit on the AR-ageing worklist and in the CSV that worklist exports', function () {
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));

    asTenant($this->asset, function () {
        // `set()`, not a mount argument: ArAging::mount() re-reads the bucket from the query string,
        // so a constructor argument is overwritten before the table is ever built.
        Livewire::test(ArAging::class)
            ->set('bucket', 'd_90_plus')
            ->assertSee('OWNED-202')
            ->assertSee('LEASED-101');

        $drilldown = app(ReportService::class)->arAgingDrilldown('d_90_plus');

        // Loaded, not lazy — arrears is the one dataset that never shrinks.
        expect($drilldown->firstWhere('id', $this->assessment->id)?->relationLoaded('unitOwnership'))
            ->toBeTrue();

        $cells = collect(app(ReportCsvExporter::class)->arAging($drilldown)['rows'])->flatten()->all();

        expect($cells)->toContain('OWNED-202')
            ->and($cells)->toContain('LEASED-101');
    });
});

it('carries the unit into the invoice EXPORT, through either agreement', function () {
    // The export renders ExportColumns, so a table column reaches none of it: this is the file an
    // operator reconciles from, and every owner assessment in it had an empty unit cell.
    $names = collect(InvoiceExporter::getColumns())->map(fn ($column) => $column->getName())->all();

    expect($names)->toContain('unit_code')
        ->and($names)->not->toContain('lease.unit.code');

    expect(array_keys(InvoiceExporter::modifyQuery(Invoice::query())->getEagerLoads()))
        ->toContain('unitOwnership.unit')
        ->toContain('lease.unit');
});
