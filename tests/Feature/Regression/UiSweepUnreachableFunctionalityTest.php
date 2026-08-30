<?php

use App\Enums\PartyType;
use App\Enums\UnitOwnershipStatus;
use App\Filament\Admin\Pages\VendorScorecard;
use App\Filament\Admin\Resources\Assets\AssetResource;
use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Admin\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Admin\Resources\TenantRequests\TenantRequestResource;
use App\Filament\Admin\Resources\UnitOwnerships\Pages\EditUnitOwnership;
use App\Filament\Admin\Resources\UnitOwnerships\Pages\ListUnitOwnerships;
use App\Filament\Admin\Resources\UnitOwnerships\UnitOwnershipResource;
use App\Models\Charge;
use App\Models\Invoice;
use App\Models\UnitOwnership;
use App\Services\Accounting\FiscalCalendar;
use App\Services\AssetStatementPdfService;
use App\Services\TransferUnitOwnershipService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Livewire\Livewire;

/**
 * UI/UX sweep, 2026-08-18 — five capabilities that existed in the services layer and could not be
 * reached from any screen, schedule or job.
 *
 * The sweep enumerated every service in `app/Services` and traced it to an entry point (Filament
 * action, console command, queued job, HTTP route, model hook). Four services reached none, and one
 * permission was granted to a role and checked nowhere. Each `it()` below pins the wiring that was
 * missing — every one of them failed before the fix.
 *
 * Refusals are paired with controls throughout: a gate that refused everybody would satisfy the
 * refusal half on its own and read as a pass.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);

    $this->asset = makeAsset(['code' => 'SW']);
    $this->unit = makeUnit($this->asset);
});

/** A handed-over ownership with an active monthly assessment — the thing the run is supposed to bill. */
function sweepOwnership(): UnitOwnership
{
    $ownership = UnitOwnership::create([
        'asset_id' => test()->asset->id,
        'unit_id' => test()->unit->id,
        'tenant_id' => makeTenant(['party_type' => PartyType::UnitOwner->value])->id,
        'status' => UnitOwnershipStatus::HandedOver->value,
        'started_at' => '2026-01-01',
        'payment_terms_days' => 10,
    ]);

    Charge::create([
        'unit_ownership_id' => $ownership->id,
        'name' => 'Service charge',
        'type' => 'service_charge',
        'amount' => 3000,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'vat_applicable' => true,
        'is_active' => true,
        'start_date' => '2026-01-01',
    ]);

    return $ownership;
}

// ---------------------------------------------------------------------------
// F1 — the unit-owner assessment run had no caller at all in production.
// ---------------------------------------------------------------------------

it('schedules the owner-assessment run', function () {
    $commands = collect(app(Schedule::class)->events())
        ->map(fn ($e) => (string) ($e->command ?? ''));

    expect($commands->contains(fn (string $c) => str_contains($c, 'billing:run-assessments')))->toBeTrue();
});

it('bills a handed-over unit owner from the console command', function () {
    $ownership = sweepOwnership();

    $this->artisan('billing:run-assessments', ['--period' => '2026-03'])->assertSuccessful();

    expect(Invoice::query()->where('unit_ownership_id', $ownership->id)->exists())->toBeTrue();
});

it('offers the assessment run to accounting and withholds it from a viewer', function () {
    $this->actingAs(makeUser('accounting', [$this->asset->id]));
    expect(InvoiceResource::canRunBilling())->toBeTrue();

    // The control's mirror: a viewer holds invoices.view and must never trigger a property-wide run.
    $this->actingAs(makeUser('viewer', [$this->asset->id]));
    expect(InvoiceResource::canRunBilling())->toBeFalse();
});

// ---------------------------------------------------------------------------
// F2 — technician held requests.change_status; the action gated on requests.edit.
// ---------------------------------------------------------------------------

it('lets a technician move the request assigned to them', function () {
    $tech = makeUser('technician', [$this->asset->id]);
    expect($tech->can('requests.change_status'))->toBeTrue()
        // The whole point: the role is granted the status right and NOT the edit right.
        ->and($tech->can('requests.edit'))->toBeFalse();

    $request = makeTenantRequest([
        'unit_id' => $this->unit->id,
        'status' => 'in_progress',
        'assigned_to' => $tech->id,
    ]);

    $this->actingAs($tech);

    expect(TenantRequestResource::canChangeStatus($request))->toBeTrue()
        // Doing the job is not rewriting the record — edit stays withheld.
        ->and(TenantRequestResource::canEdit($request))->toBeFalse()
        // Nor is dispatching work: assigning is a coordinator's act.
        ->and(TenantRequestResource::canAssign($request))->toBeFalse();
});

it('still refuses a customer-service user, who holds neither right', function () {
    $request = makeTenantRequest(['unit_id' => $this->unit->id, 'status' => 'in_progress']);

    $this->actingAs(makeUser('customer_service', [$this->asset->id]));

    expect(TenantRequestResource::canChangeStatus($request))->toBeFalse()
        ->and(TenantRequestResource::canAssign($request))->toBeFalse();
});

it('lets a coordinator both move and assign, and refuses both on a terminal request', function () {
    $this->actingAs(makeUser('coordinator', [$this->asset->id]));

    $open = makeTenantRequest(['unit_id' => $this->unit->id, 'status' => 'in_progress']);
    expect(TenantRequestResource::canChangeStatus($open))->toBeTrue()
        ->and(TenantRequestResource::canAssign($open))->toBeTrue();

    // A closed request is immutable (FR REQ-3) — a property of the RECORD, not of the permission,
    // so it must survive the move off canEdit().
    $closed = makeTenantRequest(['unit_id' => $this->unit->id, 'status' => 'closed']);
    expect(TenantRequestResource::canChangeStatus($closed))->toBeFalse()
        ->and(TenantRequestResource::canAssign($closed))->toBeFalse();
});

// ---------------------------------------------------------------------------
// F3 — a unit could be resold and there was no way to record it.
// ---------------------------------------------------------------------------

it('lets leasing record a resale and refuses a viewer', function () {
    $ownership = sweepOwnership();

    $this->actingAs(makeUser('leasing', [$this->asset->id]));
    expect(UnitOwnershipResource::canEdit($ownership))->toBeTrue();

    $this->actingAs(makeUser('viewer', [$this->asset->id]));
    expect(UnitOwnershipResource::canView($ownership))->toBeTrue()   // control: they can read it
        ->and(UnitOwnershipResource::canEdit($ownership))->toBeFalse(); // but never move the unit
});

it('produces a resale certificate stating what is owed', function () {
    $ownership = sweepOwnership();
    $this->artisan('billing:run-assessments', ['--period' => '2026-03'])->assertSuccessful();

    $cert = app(TransferUnitOwnershipService::class)
        ->certificate($ownership, CarbonImmutable::parse('2026-03-31'));

    expect($cert['outstanding'])->toBeGreaterThan(0)
        ->and($cert['monthly_assessment'])->toBe(3000.0);
});

// ---------------------------------------------------------------------------
// F4 — the owner property statement lost its button with the /owner panel.
// ---------------------------------------------------------------------------

it('builds the property statement an owner asks for', function () {
    $pdf = app(AssetStatementPdfService::class)->build($this->asset);

    expect($pdf)->toStartWith('%PDF-');
});

it('offers the property statement to an owner and withholds it from a technician', function () {
    // `reports.download` is the right the action gates on — the one the seeder already uses for
    // owner-facing extracts.
    expect(makeUser('owner', [$this->asset->id])->can('reports.download'))->toBeTrue()
        ->and(makeUser('technician', [$this->asset->id])->can('reports.download'))->toBeFalse();

    // Control: the owner can reach the property register the action hangs off.
    $this->actingAs(makeUser('owner', [$this->asset->id]));
    expect(AssetResource::canViewAny())->toBeTrue();
});

// ---------------------------------------------------------------------------
// F5 — the vendor scorecard was built, tested, and had no screen.
// ---------------------------------------------------------------------------

it('opens the vendor scorecard for a role that may read vendors', function () {
    $this->actingAs(makeUser('operations', [$this->asset->id]));

    expect(VendorScorecard::canAccess())->toBeTrue();
});

it('refuses the vendor scorecard to an external contractor', function () {
    // The `vendor` role is an outside contractor: it holds facility rights and no vendor rights, so
    // it must never read a competitor's response times, penalties or lapsed documents.
    $this->actingAs($contractor = makeUser('vendor', [$this->asset->id]));
    expect($contractor->can('facility.view'))->toBeTrue()   // control: they DO hold facility rights
        ->and(VendorScorecard::canAccess())->toBeFalse();
});

// ---------------------------------------------------------------------------
// The closures. Every action added by this sweep builds its modal in fillForm()/schema()
// callbacks, which run ONLY when an operator opens it — a test that asserts an action exists
// passes while every one of them is broken (CLAUDE.md: "Build an action in a test and you have
// tested nothing; mount() is the seam Filament calls on open"). These mount them for real.
// ---------------------------------------------------------------------------

it('renders the vendor scorecard page', function () {
    $this->actingAs(makeUser('operations', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    Livewire::test(VendorScorecard::class)->assertOk();

    Filament::setTenant(null, isQuiet: true);
});

it('opens the resale certificate and the transfer modal', function () {
    $ownership = sweepOwnership();

    $this->actingAs(makeUser('leasing', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    // Both build their contents in closures that hit the service — an unimported class or a
    // closure missing a `use` would 500 here and nowhere else.
    // assertActionMounted(), not assertOk(): mountAction() on a HIDDEN action is a silent no-op
    // that leaves the page perfectly OK, so assertOk() alone would pass whether or not the modal
    // was ever built — the vacuous-pass shape this whole file exists to avoid.
    Livewire::test(ListUnitOwnerships::class)
        ->mountAction(TestAction::make('resaleCertificate')->table($ownership))
        ->assertActionMounted(TestAction::make('resaleCertificate')->table($ownership));

    // `transfer` moved to the ownership's own page on 2026-08-30 (the list FINDS, the record
    // ACTS); `resaleCertificate` above is a DOWNLOAD and stayed beside the row it copies.
    Livewire::test(EditUnitOwnership::class, ['record' => $ownership->getRouteKey()])
        ->mountAction(TestAction::make('transfer'))
        ->assertActionMounted(TestAction::make('transfer'));

    Filament::setTenant(null, isQuiet: true);
});

it('opens the owner-assessment run from the invoices header', function () {
    $this->actingAs(makeUser('accounting', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    Livewire::test(ListInvoices::class)
        ->mountAction(TestAction::make('runOwnerAssessments')->table())
        ->assertActionMounted(TestAction::make('runOwnerAssessments')->table());

    Filament::setTenant(null, isQuiet: true);
});
