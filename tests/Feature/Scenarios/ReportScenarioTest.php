<?php

/*
|--------------------------------------------------------------------------
| Reports scenarios — App\Services\Reports\ReportService (+ PDF gating)
|--------------------------------------------------------------------------
| NET-NEW vs tests/Feature/ReportServiceTest.php (which covers the basic
| monthly-close aggregation, a 4-row aging spread, revenue_by_type, and one
| drilldown) and tests/Feature/Pages/AdminPagesTest.php (page view-data +
| reports.view gate). Here we lock down:
|
|   - MONTHLY CLOSE figures: revenue (billed) vs collected vs outstanding,
|     captured-only payments, status breakdown, cancelled/draft excluded from
|     revenue_by_type, collections-rate zero-guard, month-window boundaries.
|   - AR AGING: an invoice sitting exactly ON each cutoff (0 / 30 / 31 / 60 /
|     61 / 90 / 91 days) lands in the correct bucket, plus paid/zero-balance
|     invoices are excluded, and bucket totals sum to outstanding_total.
|   - SCOPING: both monthlyClose() and arAgingBuckets() honour the active
|     Filament property — another property's invoices/payments never leak.
|   - RBAC: the reports.download permission set (which roles hold it).
|
| ReportService scopes via TenantScope::applyTo(), which reads the active
| Filament tenant, so the scoping tests run inside asTenant().
*/

use App\Filament\Admin\Pages\Reports;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Lease;
use App\Models\Payment;
use App\Services\Reports\ReportService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/**
 * A fully-wired lease on a fresh property. Each scenario seeds the exact
 * invoices/payments it asserts on, so we never depend on shared state.
 */
function reportLease(array $assetAttrs = []): Lease
{
    $asset = makeAsset($assetAttrs);
    $unit = makeUnit($asset, ['status' => 'occupied']);

    return makeLease($unit, null, [
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2027-12-31',
    ]);
}

/** Attach a captured (or otherwise) payment to a tenant + optionally an invoice. */
function reportPayment(Invoice $invoice, array $attrs = []): Payment
{
    $payment = Payment::create(array_merge([
        'reference' => 'P-'.uniqid(),
        'tenant_id' => $invoice->tenant_id,
        'amount' => 1000,
        'currency' => 'EGP',
        'method' => 'card',
        'status' => 'captured',
        'payment_date' => '2026-02-10',
    ], $attrs));

    // Link to the invoice so property-scoping (invoices.lease.unit) can reach it.
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => $payment->amount]);

    return $payment;
}

function reportSvc(): ReportService
{
    return app(ReportService::class);
}

/* =========================================================================
 | MONTHLY CLOSE — billed vs collected vs outstanding
 ========================================================================= */

describe('monthly close figures', function () {
    it('computes billed, collected and outstanding from seeded invoices + payments', function () {
        $lease = reportLease();

        // Two invoices issued in Feb 2026.
        $paid = makeInvoice($lease, [
            'issue_date' => '2026-02-05', 'due_date' => '2026-02-12', 'status' => 'paid',
            'subtotal' => 10000, 'vat_amount' => 1400, 'total' => 11400,
            'paid_amount' => 11400, 'balance' => 0,
        ]);
        $open = makeInvoice($lease, [
            'issue_date' => '2026-02-20', 'due_date' => '2026-02-27', 'status' => 'issued',
            'subtotal' => 5000, 'vat_amount' => 700, 'total' => 5700,
            'paid_amount' => 0, 'balance' => 5700,
        ]);

        // One captured payment in Feb 2026 settles the paid invoice.
        reportPayment($paid, ['amount' => 11400, 'payment_date' => '2026-02-06']);

        $report = reportSvc()->monthlyClose(CarbonImmutable::parse('2026-02-01'));

        // BILLED — both invoices, totals + VAT summed exactly.
        expect($report['invoices']['count'])->toBe(2)
            ->and((float) $report['invoices']['total'])->toBe(17100.0)   // 11400 + 5700
            ->and((float) $report['invoices']['vat'])->toBe(2100.0);     // 1400 + 700

        // COLLECTED — only the captured payment.
        expect($report['payments']['count'])->toBe(1)
            ->and((float) $report['payments']['total'])->toBe(11400.0);

        // OUTSTANDING — equals the still-open invoice's balance (the open AR).
        expect((float) $report['outstanding_total'])->toBe(5700.0);

        // The outstanding total is exactly the sum of the aging buckets.
        $bucketSum = array_sum(array_column($report['ar_aging'], 'total'));
        expect(round($bucketSum, 2))->toBe((float) $report['outstanding_total']);

        // COLLECTIONS RATE — 11400 / 17100 = 66.7%.
        expect($report['collections_rate'])->toBe(66.7);
    });

    it('counts only CAPTURED payments toward collected — initiated/failed are ignored', function () {
        $lease = reportLease();
        $invoice = makeInvoice($lease, [
            'issue_date' => '2026-02-05', 'total' => 10000, 'balance' => 0,
            'paid_amount' => 10000, 'status' => 'paid',
        ]);

        reportPayment($invoice, ['amount' => 7000, 'status' => 'captured',  'payment_date' => '2026-02-08']);
        reportPayment($invoice, ['amount' => 3000, 'status' => 'initiated', 'payment_date' => '2026-02-09']);
        reportPayment($invoice, ['amount' => 4000, 'status' => 'failed',    'payment_date' => '2026-02-09']);

        $report = reportSvc()->monthlyClose(CarbonImmutable::parse('2026-02-01'));

        // Only the captured 7000 is collected; initiated + failed never count.
        expect($report['payments']['count'])->toBe(1)
            ->and((float) $report['payments']['total'])->toBe(7000.0);
    });

    it('breaks invoices down by status with per-status counts and totals', function () {
        $lease = reportLease();
        makeInvoice($lease, ['issue_date' => '2026-02-03', 'status' => 'paid',   'total' => 1000, 'balance' => 0]);
        makeInvoice($lease, ['issue_date' => '2026-02-04', 'status' => 'paid',   'total' => 2000, 'balance' => 0]);
        makeInvoice($lease, ['issue_date' => '2026-02-05', 'status' => 'issued', 'total' => 3000, 'balance' => 3000]);

        $report = reportSvc()->monthlyClose(CarbonImmutable::parse('2026-02-01'));
        $byStatus = $report['invoices']['by_status'];

        expect($byStatus['paid']['count'])->toBe(2)
            ->and((float) $byStatus['paid']['total'])->toBe(3000.0)
            ->and($byStatus['issued']['count'])->toBe(1)
            ->and((float) $byStatus['issued']['total'])->toBe(3000.0);
    });

    it('groups captured payments by method', function () {
        $lease = reportLease();
        $invoice = makeInvoice($lease, ['issue_date' => '2026-02-05', 'total' => 9000, 'balance' => 0, 'status' => 'paid']);

        reportPayment($invoice, ['amount' => 5000, 'method' => 'card', 'payment_date' => '2026-02-06']);
        reportPayment($invoice, ['amount' => 2000, 'method' => 'card', 'payment_date' => '2026-02-07']);
        reportPayment($invoice, ['amount' => 2000, 'method' => 'cash', 'payment_date' => '2026-02-08']);

        $report = reportSvc()->monthlyClose(CarbonImmutable::parse('2026-02-01'));

        expect((float) $report['payments']['by_method']['card'])->toBe(7000.0)
            ->and((float) $report['payments']['by_method']['cash'])->toBe(2000.0);
    });

    it('returns a zero collections_rate (no division-by-zero) when nothing was billed', function () {
        // March has no invoices at all.
        reportLease();

        $report = reportSvc()->monthlyClose(CarbonImmutable::parse('2026-03-01'));

        expect($report['invoices']['count'])->toBe(0)
            ->and((float) $report['invoices']['total'])->toBe(0.0)
            ->and($report['collections_rate'])->toBe(0.0);
    });

    it('excludes cancelled + draft invoices from revenue_by_type but still counts them as billed', function () {
        $lease = reportLease();

        $live = makeInvoice($lease, ['issue_date' => '2026-02-05', 'status' => 'issued', 'total' => 8000, 'balance' => 8000]);
        InvoiceItem::create([
            'invoice_id' => $live->id, 'description' => 'Rent', 'type' => 'base_rent',
            'amount' => 8000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 8000,
        ]);

        $cancelled = makeInvoice($lease, ['issue_date' => '2026-02-06', 'status' => 'cancelled', 'total' => 9999, 'balance' => 0]);
        InvoiceItem::create([
            'invoice_id' => $cancelled->id, 'description' => 'Rent', 'type' => 'base_rent',
            'amount' => 9999, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 9999,
        ]);

        $report = reportSvc()->monthlyClose(CarbonImmutable::parse('2026-02-01'));

        // revenue_by_type omits the cancelled invoice's item — only the live 8000.
        expect((float) $report['revenue_by_type']['base_rent'])->toBe(8000.0);
    });
});

/* =========================================================================
 | MONTHLY CLOSE — month window boundaries
 ========================================================================= */

describe('monthly close month window', function () {
    it('includes an invoice issued on the LAST day of the month and excludes the next month', function () {
        $lease = reportLease();

        $lastDay = makeInvoice($lease, ['issue_date' => '2026-02-28', 'status' => 'issued', 'total' => 1500, 'balance' => 1500]);
        $nextMonth = makeInvoice($lease, ['issue_date' => '2026-03-01', 'status' => 'issued', 'total' => 9000, 'balance' => 9000]);

        $report = reportSvc()->monthlyClose(CarbonImmutable::parse('2026-02-01'));

        expect($report['invoices']['count'])->toBe(1)
            ->and((float) $report['invoices']['total'])->toBe(1500.0);
    });

    it('includes a captured payment on the FIRST day of the month and excludes the previous month', function () {
        $lease = reportLease();
        $invoice = makeInvoice($lease, ['issue_date' => '2026-02-05', 'total' => 5000, 'balance' => 0, 'status' => 'paid']);

        reportPayment($invoice, ['amount' => 5000, 'payment_date' => '2026-02-01']); // first day → in
        reportPayment($invoice, ['amount' => 4000, 'payment_date' => '2026-01-31']); // prev month → out

        $report = reportSvc()->monthlyClose(CarbonImmutable::parse('2026-02-01'));

        expect($report['payments']['count'])->toBe(1)
            ->and((float) $report['payments']['total'])->toBe(5000.0);
    });
});

/* =========================================================================
 | AR AGING — boundary at every cutoff
 ========================================================================= */

describe('AR aging bucket boundaries', function () {
    /**
     * Build one open invoice whose due_date is exactly $daysBefore days before
     * the asOf date, carrying a distinctive balance for bucket identification.
     */
    function agingInvoice(Lease $lease, CarbonImmutable $asOf, int $daysBefore, float $balance): Invoice
    {
        $due = $asOf->subDays($daysBefore);

        return makeInvoice($lease, [
            'issue_date' => $due->subDays(5)->toDateString(),
            'due_date' => $due->toDateString(),
            'status' => 'issued',
            'total' => $balance,
            'paid_amount' => 0,
            'balance' => $balance,
        ]);
    }

    it('lands a boundary invoice in the correct bucket at each cutoff (0/30/31/60/61/90/91)', function () {
        $lease = reportLease();
        $asOf = CarbonImmutable::parse('2026-06-15');

        // Exactly on / around each cutoff — distinct balances tag each one.
        agingInvoice($lease, $asOf, 0, 100);   // due today          → current
        agingInvoice($lease, $asOf, 30, 200);   // 30 days overdue    → d_1_30  (<= 30)
        agingInvoice($lease, $asOf, 31, 400);   // 31 days overdue    → d_31_60 (> 30)
        agingInvoice($lease, $asOf, 60, 800);   // 60 days overdue    → d_31_60 (<= 60)
        agingInvoice($lease, $asOf, 61, 1600);  // 61 days overdue    → d_61_90 (> 60)
        agingInvoice($lease, $asOf, 90, 3200);  // 90 days overdue    → d_61_90 (<= 90)
        agingInvoice($lease, $asOf, 91, 6400);  // 91 days overdue    → d_90_plus (> 90)

        $b = reportSvc()->arAgingBuckets($asOf);

        // current: the due-today invoice (diff == 0 → current).
        expect($b['current']['count'])->toBe(1)
            ->and((float) $b['current']['total'])->toBe(100.0);

        // d_1_30: only the 30-day invoice (the 31-day one rolls to the next bucket).
        expect($b['d_1_30']['count'])->toBe(1)
            ->and((float) $b['d_1_30']['total'])->toBe(200.0);

        // d_31_60: the 31 + 60 day invoices.
        expect($b['d_31_60']['count'])->toBe(2)
            ->and((float) $b['d_31_60']['total'])->toBe(1200.0); // 400 + 800

        // d_61_90: the 61 + 90 day invoices.
        expect($b['d_61_90']['count'])->toBe(2)
            ->and((float) $b['d_61_90']['total'])->toBe(4800.0); // 1600 + 3200

        // d_90_plus: only the 91-day invoice.
        expect($b['d_90_plus']['count'])->toBe(1)
            ->and((float) $b['d_90_plus']['total'])->toBe(6400.0);
    });

    it('classifies a future-dated (not-yet-due) invoice as current', function () {
        $lease = reportLease();
        $asOf = CarbonImmutable::parse('2026-06-15');

        // Due 10 days in the FUTURE — diff is negative → current.
        makeInvoice($lease, [
            'issue_date' => '2026-06-14', 'due_date' => $asOf->addDays(10)->toDateString(),
            'status' => 'issued', 'total' => 999, 'balance' => 999, 'paid_amount' => 0,
        ]);

        $b = reportSvc()->arAgingBuckets($asOf);

        expect($b['current']['count'])->toBe(1)
            ->and((float) $b['current']['total'])->toBe(999.0)
            ->and($b['d_1_30']['count'])->toBe(0);
    });

    it('excludes paid + zero-balance invoices from every bucket', function () {
        $lease = reportLease();
        $asOf = CarbonImmutable::parse('2026-06-15');

        // Overdue but fully PAID — zero balance, excluded.
        makeInvoice($lease, [
            'issue_date' => '2026-04-01', 'due_date' => '2026-04-20',
            'status' => 'paid', 'total' => 5000, 'paid_amount' => 5000, 'balance' => 0,
        ]);
        // Overdue + cancelled — not an open status, excluded even with a balance.
        makeInvoice($lease, [
            'issue_date' => '2026-04-01', 'due_date' => '2026-04-20',
            'status' => 'cancelled', 'total' => 5000, 'paid_amount' => 0, 'balance' => 5000,
        ]);
        // The only one that should count: open + 31-60 bucket.
        makeInvoice($lease, [
            'issue_date' => '2026-04-01', 'due_date' => '2026-04-20',
            'status' => 'overdue', 'total' => 3000, 'paid_amount' => 0, 'balance' => 3000,
        ]);

        $b = reportSvc()->arAgingBuckets($asOf);
        $totalRows = array_sum(array_column($b, 'count'));

        expect($totalRows)->toBe(1)
            ->and((float) $b['d_31_60']['total'])->toBe(3000.0);
    });

    it('counts only the open balance of a partially-paid invoice in its bucket', function () {
        $lease = reportLease();
        $asOf = CarbonImmutable::parse('2026-06-15');

        // Total 10000, 6000 paid → 4000 open, 1-30 bucket.
        makeInvoice($lease, [
            'issue_date' => '2026-05-20', 'due_date' => $asOf->subDays(10)->toDateString(),
            'status' => 'partially_paid', 'total' => 10000, 'paid_amount' => 6000, 'balance' => 4000,
        ]);

        $b = reportSvc()->arAgingBuckets($asOf);

        expect($b['d_1_30']['count'])->toBe(1)
            ->and((float) $b['d_1_30']['total'])->toBe(4000.0); // the balance, not the total
    });
});

/* =========================================================================
 | SCOPING — report data is pinned to the active property
 ========================================================================= */

describe('property scoping', function () {
    it('scopes monthlyClose invoices + payments to the active property', function () {
        ensureAllPropertiesAsset();

        // Property A: one invoice + a captured payment in Feb.
        $aLease = reportLease(['code' => 'AAA', 'name' => 'Alpha']);
        $aInvoice = makeInvoice($aLease, ['issue_date' => '2026-02-05', 'total' => 6000, 'balance' => 0, 'status' => 'paid']);
        reportPayment($aInvoice, ['amount' => 6000, 'payment_date' => '2026-02-06']);

        // Property B: a bigger invoice + payment — must NOT bleed into A's close.
        $bLease = reportLease(['code' => 'BBB', 'name' => 'Beta']);
        $bInvoice = makeInvoice($bLease, ['issue_date' => '2026-02-05', 'total' => 99000, 'balance' => 0, 'status' => 'paid']);
        reportPayment($bInvoice, ['amount' => 99000, 'payment_date' => '2026-02-06']);

        $aAsset = $aLease->unit->asset;

        asTenant($aAsset, function () {
            $report = reportSvc()->monthlyClose(CarbonImmutable::parse('2026-02-01'));

            // Only A's figures — B's 99000 never appears.
            expect($report['invoices']['count'])->toBe(1)
                ->and((float) $report['invoices']['total'])->toBe(6000.0)
                ->and($report['payments']['count'])->toBe(1)
                ->and((float) $report['payments']['total'])->toBe(6000.0);
        });
    });

    it('scopes arAgingBuckets to the active property', function () {
        ensureAllPropertiesAsset();
        $asOf = CarbonImmutable::parse('2026-06-15');

        // A: a 1-30 overdue invoice.
        $aLease = reportLease(['code' => 'AAA', 'name' => 'Alpha']);
        makeInvoice($aLease, [
            'issue_date' => '2026-05-20', 'due_date' => $asOf->subDays(10)->toDateString(),
            'status' => 'issued', 'total' => 2500, 'balance' => 2500,
        ]);

        // B: a 90+ overdue invoice that must not show up under A.
        $bLease = reportLease(['code' => 'BBB', 'name' => 'Beta']);
        makeInvoice($bLease, [
            'issue_date' => '2026-01-01', 'due_date' => '2026-01-20',
            'status' => 'overdue', 'total' => 80000, 'balance' => 80000,
        ]);

        asTenant($aLease->unit->asset, function () use ($asOf) {
            $b = reportSvc()->arAgingBuckets($asOf);

            expect($b['d_1_30']['count'])->toBe(1)
                ->and((float) $b['d_1_30']['total'])->toBe(2500.0)
                ->and($b['d_90_plus']['count'])->toBe(0)
                ->and((float) $b['d_90_plus']['total'])->toBe(0.0);
        });
    });

    it('sees BOTH properties when no tenant is pinned (All Properties / unscoped)', function () {
        $aLease = reportLease(['code' => 'AAA']);
        makeInvoice($aLease, ['issue_date' => '2026-02-05', 'total' => 1000, 'balance' => 1000, 'status' => 'issued']);
        $bLease = reportLease(['code' => 'BBB']);
        makeInvoice($bLease, ['issue_date' => '2026-02-06', 'total' => 2000, 'balance' => 2000, 'status' => 'issued']);

        // No Filament tenant set → applyTo() is a no-op → both properties counted.
        $report = reportSvc()->monthlyClose(CarbonImmutable::parse('2026-02-01'));

        expect($report['invoices']['count'])->toBe(2)
            ->and((float) $report['invoices']['total'])->toBe(3000.0);
    });
});

/* =========================================================================
 | RBAC — the reports.download permission
 ========================================================================= */

describe('reports.download permission', function () {
    beforeEach(fn () => $this->seed(RolesPermissionsSeeder::class));

    it('grants reports.download to viewer, accounting, manager, owner and super_admin', function () {
        foreach (['viewer', 'accounting', 'manager', 'owner', 'super_admin'] as $role) {
            expect(makeUser($role)->can('reports.download'))
                ->toBeTrue("{$role} should hold reports.download");
        }
    });

    it('withholds reports.download from leasing, operations, marketing and hr', function () {
        foreach (['leasing', 'operations', 'marketing', 'hr'] as $role) {
            expect(makeUser($role)->can('reports.download'))
                ->toBeFalse("{$role} must NOT hold reports.download");
        }
    });

    it('grants reports.view to accounting + viewer but not to marketing/operations/hr', function () {
        expect(makeUser('accounting')->can('reports.view'))->toBeTrue()
            ->and(makeUser('viewer')->can('reports.view'))->toBeTrue()
            ->and(makeUser('marketing')->can('reports.view'))->toBeFalse()
            ->and(makeUser('operations')->can('reports.view'))->toBeFalse()
            ->and(makeUser('hr')->can('reports.view'))->toBeFalse();
    });

    it('gates the Reports PDF download on reports.download, not just reports.view', function () {
        // A user with reports.view but WITHOUT reports.download may see the
        // on-screen report but must be refused the PDF export.
        $user = makeUser('accounting');
        // reports.download is granted via the accounting ROLE, so strip it there
        // (a user-level revoke wouldn't override the role grant).
        Role::findByName('accounting', 'web')->revokePermissionTo('reports.download');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($user);

        expect($user->can('reports.view'))->toBeTrue()
            ->and($user->can('reports.download'))->toBeFalse();

        $page = new Reports;
        $page->period = '2026-02';

        // With reports.download revoked the export is refused (403).
        expect(fn () => $page->downloadMonthlyClose())
            ->toThrow(HttpException::class);
    });
});
