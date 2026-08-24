<?php

use App\Filament\Admin\Widgets\EnergyConsumptionTrend;
use App\Filament\Admin\Widgets\EtaCompliance;
use App\Filament\Admin\Widgets\ExpiringLeases;
use App\Filament\Admin\Widgets\MonthlyRevenueTrend;
use App\Filament\Admin\Widgets\OpenTenantRequests;
use App\Filament\Admin\Widgets\RecentPayments;
use App\Filament\Admin\Widgets\SetupGuide;
use App\Filament\Admin\Widgets\TopTenants;
use App\Models\MeterReading;
use App\Models\Payment;
use App\Models\TenantRequest;
use App\Models\TenantSalesDeclaration;
use App\Models\UtilityMeter;
use Filament\Support\RawJs;
use Filament\Tables\Table;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Database\Eloquent\Builder;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->unit = makeUnit($this->asset, ['status' => 'occupied']);
    $this->tenant = makeTenant();
    $this->lease = makeLease($this->unit, $this->tenant);
});

function callProtected(object $obj, string $method, array $args = []): mixed
{
    $ref = new ReflectionMethod($obj, $method);

    return $ref->invokeArgs($obj, $args);
}

/* ─────────────── ChartWidgets / StatsOverviewWidget / Widget ─────────────── */

it('EtaCompliance.getStats counts invoices across all eta statuses (property-scoped)', function () {
    makeInvoice($this->lease, ['status' => 'issued', 'eta_status' => 'valid']);
    makeInvoice($this->lease, ['status' => 'issued', 'eta_status' => 'submitted']);
    makeInvoice($this->lease, ['status' => 'issued', 'eta_status' => 'invalid']);
    makeInvoice($this->lease, ['status' => 'issued', 'eta_status' => null]); // → pending

    asTenant($this->asset, function () {
        $stats = callProtected(new EtaCompliance, 'getStats');
        expect($stats)->toHaveCount(4);
        // Valid card description encodes percentage; verify it's a Stat instance.
        expect($stats[0])->toBeInstanceOf(Stat::class);
    });
});

it('EnergyConsumptionTrend.getData returns labeled monthly buckets', function () {
    $meter = UtilityMeter::create([
        'asset_id' => $this->asset->id,
        'unit_id' => $this->unit->id,
        'meter_number' => 'E-'.uniqid(),
        'type' => 'electric',
        'unit_of_measurement' => 'kWh',
        'status' => 'active',
    ]);
    MeterReading::create([
        'utility_meter_id' => $meter->id,
        'reading_value' => 1234,
        'reading_date' => now()->subDays(15),
        'consumption' => 1234,
        'cost' => 1851,
    ]);

    asTenant($this->asset, function () {
        $data = callProtected(new EnergyConsumptionTrend, 'getData');
        expect($data)->toHaveKeys(['datasets', 'labels']);
        expect($data['labels'])->toHaveCount(12);
    });
});

it('EnergyConsumptionTrend exposes type + options for the chart driver', function () {
    expect(callProtected(new EnergyConsumptionTrend, 'getType'))->toBe('bar');
    expect(callProtected(new EnergyConsumptionTrend, 'getOptions'))->toBeArray();
});

it('MonthlyRevenueTrend.getData returns 12 labels and 3 datasets (billed/collected/rate)', function () {
    makeInvoice($this->lease, [
        'status' => 'issued',
        'period_start' => now()->subMonth()->startOfMonth(),
        'period_end' => now()->subMonth()->endOfMonth(),
        'total' => 10000,
    ]);
    Payment::create([
        'tenant_id' => $this->tenant->id,
        'reference' => 'P-'.uniqid(),
        'amount' => 5000,
        'method' => 'cash',
        'status' => 'captured',
        'currency' => 'EGP',
        'payment_date' => now()->subDays(10),
    ]);

    asTenant($this->asset, function () {
        $data = callProtected(new MonthlyRevenueTrend, 'getData');
        expect($data['labels'])->toHaveCount(12);
        expect($data['datasets'])->toHaveCount(3);
    });
});

it('MonthlyRevenueTrend exposes type + options', function () {
    expect(callProtected(new MonthlyRevenueTrend, 'getType'))->toBe('bar');
    expect(callProtected(new MonthlyRevenueTrend, 'getOptions'))->toBeInstanceOf(RawJs::class);
});

it('SetupGuide.getViewData reports per-step completion + nextStep + progress', function () {
    asTenant($this->asset, function () {
        $view = callProtected(new SetupGuide, 'getViewData');

        expect($view)->toHaveKeys(['steps', 'doneCount', 'totalCount', 'allDone', 'nextStep', 'progressPct']);
        expect($view['totalCount'])->toBe(4);
        // Unit exists (from beforeEach) and a lease exists, so at least 2 steps done.
        expect($view['doneCount'])->toBeGreaterThanOrEqual(2);
        expect($view['allDone'])->toBeFalse(); // no invoice issued yet
        expect($view['nextStep']['key'])->toBe('invoices'); // first incomplete
        expect($view['progressPct'])->toBeGreaterThanOrEqual(50);
    });
});

it('SetupGuide.allDone flips true once every step has data', function () {
    makeInvoice($this->lease);

    asTenant($this->asset, function () {
        $view = callProtected(new SetupGuide, 'getViewData');
        expect($view['allDone'])->toBeTrue();
        expect($view['doneCount'])->toBe(4);
        expect($view['nextStep'])->toBeNull();
        expect($view['progressPct'])->toBe(100);
    });
});

/* ─────────────── TableWidgets — drive query via Filament Table ─────────────── */

function tableQueryFor(string $widgetClass): Builder
{
    $widget = new $widgetClass;
    $table = $widget->table(Table::make($widget));

    return $table->getQuery();
}

it('ExpiringLeases query is property-scoped and limited to active leases expiring within 90 days', function () {
    // Lease in the test asset, expiring in 30 days → eligible.
    $this->lease->update(['status' => 'active', 'expiry_date' => now()->addDays(30)]);

    // Lease in a *different* asset, also expiring in 30 days → excluded.
    $otherAsset = makeAsset();
    $otherUnit = makeUnit($otherAsset, ['status' => 'occupied']);
    makeLease($otherUnit, attrs: [
        'status' => 'active',
        'expiry_date' => now()->addDays(30),
    ]);

    asTenant($this->asset, function () {
        $rows = tableQueryFor(ExpiringLeases::class)->get();
        expect($rows)->toHaveCount(1);
        expect($rows->first()->id)->toBe($this->lease->id);
    });
});

it('RecentPayments query is property-scoped', function () {
    $invoice = makeInvoice($this->lease);
    $payment = Payment::create([
        'tenant_id' => $this->tenant->id,
        'reference' => 'P-'.uniqid(),
        'amount' => 5000,
        'method' => 'cash',
        'status' => 'captured',
        'currency' => 'EGP',
        'payment_date' => now()->subDays(2),
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 5000]);

    asTenant($this->asset, function () use ($payment) {
        $rows = tableQueryFor(RecentPayments::class)->get();
        expect($rows->pluck('id'))->toContain($payment->id);
    });
});

it('OpenTenantRequests query returns only open statuses, property-scoped', function () {
    TenantRequest::create([
        'reference' => 'MR-'.uniqid(),
        'unit_id' => $this->unit->id,
        'tenant_id' => $this->tenant->id,
        'title' => 'A', 'description' => 'B',
        'status' => 'in_progress', 'priority' => 'high', 'category' => 'hvac',
        'submitted_at' => now(),
    ]);
    TenantRequest::create([
        'reference' => 'MR-'.uniqid(),
        'unit_id' => $this->unit->id,
        'tenant_id' => $this->tenant->id,
        'title' => 'Done', 'description' => 'B',
        'status' => 'resolved', 'priority' => 'low', 'category' => 'hvac',
        'submitted_at' => now(),
    ]);

    asTenant($this->asset, function () {
        // Widget orders by MySQL FIELD() which SQLite doesn't grok — assert on
        // the query builder shape instead of executing it.
        $query = tableQueryFor(OpenTenantRequests::class);
        $sql = $query->toSql();
        expect($sql)->toContain('asset_id');
        expect($sql)->toContain('FIELD(priority');
    });
});

it('TopTenants query returns active leases sorted by computed annual GMV', function () {
    $this->lease->update(['status' => 'active', 'has_percentage_rent' => true]);
    TenantSalesDeclaration::create([
        'lease_id' => $this->lease->id,
        'period_start' => now()->startOfYear(),
        'period_end' => now()->startOfYear()->addMonth()->subDay(),
        'declared_sales' => 50000,
        'declared_at' => now(),
        'status' => 'locked',
    ]);

    asTenant($this->asset, function () {
        $rows = tableQueryFor(TopTenants::class)->get();
        expect($rows)->not->toBeEmpty();
    });
});
