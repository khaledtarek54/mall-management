<?php

use App\Filament\Admin\Pages\ArCollections;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Reports\ReportService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * The AR collections worklist (UX-03).
 *
 * The property that matters is agreement: the worklist, the aging summary and the bucket
 * drill-down must place the same invoice in the same bucket, always. The boundary arithmetic used
 * to be copied between the summary and the drill-down with a comment asking them to stay identical
 * — a promise a comment cannot keep — so it now lives once in `ReportService::agingBucketKey()`
 * and these tests hold all three to it, including on the exact day boundaries where an off-by-one
 * hides.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->asset = makeAsset();
    $this->asOf = CarbonImmutable::parse('2026-06-30')->endOfDay();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

function openInvoiceFor(\App\Models\Tenant $tenant, \App\Models\Asset $asset, string $dueDate, float $balance): Invoice
{
    $lease = makeLease(makeUnit($asset), $tenant);

    return Invoice::create([
        'lease_id' => $lease->id,
        'tenant_id' => $tenant->id,
        'status' => 'issued',
        'issue_date' => '2026-01-01',
        'due_date' => $dueDate,
        'period_start' => '2026-01-01',
        'period_end' => '2026-01-31',
        'subtotal' => $balance,
        'vat_amount' => 0,
        'total' => $balance,
        'paid_amount' => 0,
        'balance' => $balance,
    ]);
}

it('rolls a tenant up across every bucket at once', function () {
    $tenant = makeTenant();
    openInvoiceFor($tenant, $this->asset, '2026-07-15', 1000);  // not yet due  → current
    openInvoiceFor($tenant, $this->asset, '2026-06-20', 2000);  // 10 days      → 1–30
    openInvoiceFor($tenant, $this->asset, '2026-03-01', 4000);  // 121 days     → 90+

    $row = app(ReportService::class)->arCollectionsByTenant($this->asOf)->sole();

    expect($row['tenant_id'])->toBe($tenant->id)
        ->and($row['total'])->toBe(7000.0)
        ->and($row['invoice_count'])->toBe(3)
        ->and($row['buckets']['current'])->toBe(1000.0)
        ->and($row['buckets']['d_1_30'])->toBe(2000.0)
        ->and($row['buckets']['d_90_plus'])->toBe(4000.0)
        ->and($row['buckets']['d_31_60'])->toBe(0.0)
        ->and($row['oldest_days'])->toBe(121);
});

it('agrees with the aging summary and the bucket drill-down, invoice for invoice', function () {
    // One invoice per bucket, placed on the exact boundaries where an off-by-one hides.
    $tenant = makeTenant();
    openInvoiceFor($tenant, $this->asset, '2026-06-30', 100);   // due today   → current
    openInvoiceFor($tenant, $this->asset, '2026-05-31', 200);   // 30 days     → 1–30
    openInvoiceFor($tenant, $this->asset, '2026-05-01', 400);   // 60 days     → 31–60
    openInvoiceFor($tenant, $this->asset, '2026-04-01', 800);   // 90 days     → 61–90
    openInvoiceFor($tenant, $this->asset, '2026-03-31', 1600);  // 91 days     → 90+

    $reports = app(ReportService::class);
    $summary = $reports->arAgingBuckets($this->asOf);
    $worklist = $reports->arCollectionsByTenant($this->asOf)->sole();

    foreach (array_keys(ReportService::AGING_BUCKETS) as $bucket) {
        $drilldown = $reports->arAgingDrilldown($bucket, $this->asOf);

        expect($worklist['buckets'][$bucket])
            ->toBe($summary[$bucket]['total'], "worklist ≠ summary for [{$bucket}]")
            ->and(round((float) $drilldown->sum('balance'), 2))
            ->toBe($summary[$bucket]['total'], "drilldown ≠ summary for [{$bucket}]");
    }

    expect($worklist['total'])->toBe(3100.0);
});

it('sorts worst first — deepest bucket before biggest balance', function () {
    $deepButSmall = makeTenant(['name' => 'Deep']);
    $shallowButBig = makeTenant(['name' => 'Big']);

    openInvoiceFor($deepButSmall, $this->asset, '2026-01-01', 10000);   // ~180 days
    openInvoiceFor($shallowButBig, $this->asset, '2026-06-25', 100000); // 5 days

    $rows = app(ReportService::class)->arCollectionsByTenant($this->asOf);

    // The 180-day 10k tenant needs the call before the 5-day 100k one.
    expect($rows->first()['tenant']->name)->toBe('Deep')
        ->and($rows->last()['tenant']->name)->toBe('Big');
});

it('shows when the tenant last actually paid, and flags one who never has', function () {
    $paid = makeTenant();
    $never = makeTenant();
    openInvoiceFor($paid, $this->asset, '2026-05-01', 5000);
    openInvoiceFor($never, $this->asset, '2026-05-01', 5000);

    Payment::create([
        'tenant_id' => $paid->id,
        'amount' => 1,
        'method' => 'cash',
        'status' => 'captured',
        'payment_date' => '2026-04-20',
    ]);

    $rows = app(ReportService::class)->arCollectionsByTenant($this->asOf)->keyBy('tenant_id');

    expect($rows[$paid->id]['last_payment_at'])->not->toBeNull()
        ->and(CarbonImmutable::parse($rows[$paid->id]['last_payment_at'])->toDateString())->toBe('2026-04-20')
        ->and($rows[$never->id]['last_payment_at'])->toBeNull();
});

it('leaves settled and cancelled invoices out of the worklist entirely', function () {
    $tenant = makeTenant();
    $open = openInvoiceFor($tenant, $this->asset, '2026-05-01', 5000);
    $settled = openInvoiceFor($tenant, $this->asset, '2026-05-01', 3000);
    $cancelled = openInvoiceFor($tenant, $this->asset, '2026-05-01', 9000);

    $settled->update(['status' => 'paid', 'paid_amount' => 3000, 'balance' => 0]);
    $cancelled->update(['status' => 'cancelled', 'balance' => 0]);

    $row = app(ReportService::class)->arCollectionsByTenant($this->asOf)->sole();

    expect($row['total'])->toBe(5000.0)
        ->and($row['invoice_count'])->toBe(1);
});

it('is scoped to the selected property', function () {
    $otherMall = makeAsset();
    $here = makeTenant();
    $elsewhere = makeTenant();
    openInvoiceFor($here, $this->asset, '2026-05-01', 5000);
    openInvoiceFor($elsewhere, $otherMall, '2026-05-01', 7000);

    $this->actingAs(makeUser('accounting', [$this->asset->id, $otherMall->id]));
    Filament::setTenant($this->asset);

    $rows = app(ReportService::class)->arCollectionsByTenant($this->asOf);

    expect($rows)->toHaveCount(1)
        ->and($rows->first()['tenant_id'])->toBe($here->id);
});

it('renders the worklist for a user who may read reports', function () {
    $tenant = makeTenant();
    openInvoiceFor($tenant, $this->asset, '2026-05-01', 5000);

    $this->actingAs(makeUser('accounting', [$this->asset->id]));
    Filament::setTenant($this->asset);

    expect(ArCollections::canAccess())->toBeTrue();

    Livewire::test(ArCollections::class)
        ->set('asOf', '2026-06-30')
        ->assertOk()
        // A page that renders empty would pass assertOk() and tell collections there is nothing to do.
        ->assertSee($tenant->name);
});

it('hides the worklist from a user without report access', function () {
    $this->actingAs(makeUser('hr', [$this->asset->id]));
    Filament::setTenant($this->asset);

    expect(ArCollections::canAccess())->toBeFalse();
});
