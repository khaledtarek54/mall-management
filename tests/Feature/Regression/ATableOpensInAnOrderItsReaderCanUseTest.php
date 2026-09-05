<?php

use App\Filament\Admin\RelationManagers\EmployeePayslipsRelationManager;
use App\Filament\Admin\RelationManagers\PayrollLinesRelationManager;
use App\Filament\Admin\Resources\Employees\Pages\EditEmployee;
use App\Filament\Admin\Resources\OwnerStatementRuns\Pages\ListOwnerStatementRuns;
use App\Filament\Admin\Resources\Payrolls\Pages\EditPayroll;
use App\Filament\Admin\Resources\SlaPolicies\Pages\ListSlaPolicies;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Portal\Resources\CamAllocations\Pages\ListCamAllocations as PortalListCamAllocations;
use App\Filament\Portal\Resources\Leases\Pages\ListLeases as PortalListLeases;
use App\Filament\Portal\Resources\MarketingPosts\Pages\ListMarketingPosts as PortalListMarketingPosts;
use App\Models\AccountingPeriod;
use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Models\Employee;
use App\Models\FiscalYear;
use App\Models\MarketingPost;
use App\Models\OwnerStatementRun;
use App\Models\Payroll;
use App\Models\PayrollLine;
use App\Models\SlaPolicy;
use App\Support\TableSortPolicy;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * **A table opens in an order its reader can use.**
 *
 * Eight lists opened in an order that meant nothing — found by reading all 144 side by side, and
 * invisible in any one file. Two sorted by a raw foreign key (`order by sla_policies.asset_id`),
 * four by a surrogate primary key where the record's own name or date existed, and two put the
 * portal's copy of a list in a different order from the operator's copy of the same records.
 *
 * The rule is now written down in {@see TableSortPolicy} and gated. This is the other
 * half: the gate reads SOURCE, so it proves a table *declares* the right order and can say nothing
 * about whether that order EXECUTES. Four of the eight fixes sort through a relation
 * (`asset.name`, `pool.period_year`, `payroll.period_month`, `employee.name`), and Filament only
 * builds the join when the column is `->sortable()` — without it the sort falls through to a bare
 * `order by employee.name` with nothing joined, which is a 500 on the page, not a wrong order.
 * `PayrollLinesRelationManager` was exactly that case.
 *
 * So every assertion here drives the real page and reads the rows back.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
});

it('orders SLA policies by the kind of request they govern, not by a raw id', function () {
    // One mall — which is the whole point. `SlaPolicy` is #[PropertyOwned] with no portfolio
    // tier, so the list only ever shows one property's rows and the old `asset_id` sort was
    // ordering a column whose every value was identical.
    foreach (['maintenance', 'access', 'complaint'] as $type) {
        SlaPolicy::create([
            'asset_id' => $this->asset->id, 'request_type' => $type, 'priority' => 'high',
            'resolve_hours' => 24, 'respond_hours' => 4, 'is_active' => true,
        ]);
    }

    $this->actingAs(makeUser('super_admin', [$this->asset->id]));

    $types = asTenant($this->asset, fn () => tableRows(Livewire::test(ListSlaPolicies::class))
        ->pluck('request_type')->all());

    expect($types)->toBe(['access', 'complaint', 'maintenance']);
});

it('opens the user register A→Z rather than in signup order', function () {
    // `makeUser()` names everyone after their role, so the names are set explicitly here —
    // and keyed in reverse alphabetical order, so insertion order cannot explain a pass.
    makeUser('manager', [$this->asset->id])->update(['name' => 'Zeinab Fouad']);
    makeUser('viewer', [$this->asset->id])->update(['name' => 'Mahmoud Kamel']);
    makeUser('leasing', [$this->asset->id])->update(['name' => 'Amir Hassan']);

    $this->actingAs(makeUser('super_admin', [$this->asset->id]));

    $names = asTenant($this->asset, fn () => tableRows(Livewire::test(ListUsers::class))->pluck('name')->all());

    expect($names)->toContain('Amir Hassan', 'Mahmoud Kamel', 'Zeinab Fouad')
        ->and($names)->toBe(collect($names)->sort(SORT_NATURAL)->values()->all());
});

it('opens owner statement runs on the latest PERIOD, not the latest keyed', function () {
    $year = FiscalYear::create([
        'year' => 2026, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open',
    ]);
    // One period each: a run is UNIQUE per (property, period, version).
    $january = AccountingPeriod::create([
        'fiscal_year_id' => $year->id, 'period_no' => 1,
        'starts_on' => '2026-01-01', 'ends_on' => '2026-01-31', 'status' => 'open',
    ]);
    $june = AccountingPeriod::create([
        'fiscal_year_id' => $year->id, 'period_no' => 6,
        'starts_on' => '2026-06-01', 'ends_on' => '2026-06-30', 'status' => 'open',
    ]);

    // The revision case: a run for an earlier period raised later. Sorted by id it would lead.
    $recent = OwnerStatementRun::create([
        'accounting_period_id' => $june->id, 'posting_date' => '2026-06-30',
        'reference' => 'OSR-2', 'asset_id' => $this->asset->id, 'basis' => 'accrual',
        'period_start' => '2026-06-01', 'period_end' => '2026-06-30', 'status' => 'draft',
    ]);
    $backfilled = OwnerStatementRun::create([
        'accounting_period_id' => $january->id, 'posting_date' => '2026-01-31',
        'reference' => 'OSR-1', 'asset_id' => $this->asset->id, 'basis' => 'accrual',
        'period_start' => '2026-01-01', 'period_end' => '2026-01-31', 'status' => 'draft',
    ]);

    expect($backfilled->id)->toBeGreaterThan($recent->id);

    $this->actingAs(makeUser('super_admin', [$this->asset->id]));

    $refs = asTenant($this->asset, fn () => tableRows(Livewire::test(ListOwnerStatementRuns::class))
        ->pluck('reference')->all());

    expect($refs)->toBe(['OSR-2', 'OSR-1']);
});

it('opens a payslip history on the latest pay PERIOD, not the latest keyed', function () {
    // A March run generated after a May run — the exact reason insertion order is not a
    // chronology here, and the row an employee opens this tab to find.
    $may = Payroll::create([
        'asset_id' => $this->asset->id, 'period_month' => '2026-05-01', 'gross_salaries' => 0,
        'salary_tax' => 0, 'social_insurance' => 0, 'paid_from' => 'bank', 'status' => 'draft',
    ]);
    $march = Payroll::create([
        'asset_id' => $this->asset->id, 'period_month' => '2026-03-01', 'gross_salaries' => 0,
        'salary_tax' => 0, 'social_insurance' => 0, 'paid_from' => 'bank', 'status' => 'draft',
    ]);

    expect($march->id)->toBeGreaterThan($may->id);

    $employee = Employee::create([
        'asset_id' => $this->asset->id, 'code' => 'E-1', 'name' => 'Mona Adel',
        'hire_date' => '2026-01-01', 'base_salary' => 8000, 'payment_method' => 'bank',
    ]);

    foreach ([$may, $march] as $run) {
        PayrollLine::create([
            'payroll_id' => $run->id, 'employee_id' => $employee->id,
            'gross' => 8000, 'salary_tax' => 0, 'social_insurance' => 0,
        ]);
        // Approved only AFTER its line exists — an approved run refuses new payslips, which is
        // the money guard doing its job, not something to work around.
        $run->update(['status' => 'approved']);
    }

    $this->actingAs(makeUser('super_admin', [$this->asset->id]));

    $periods = asTenant($this->asset, fn () => tableRows(Livewire::test(EmployeePayslipsRelationManager::class, [
        'ownerRecord' => $employee,
        'pageClass' => EditEmployee::class,
    ]))->map(fn ($line) => $line->payroll->period_month->format('Y-m'))->all());

    expect($periods)->toBe(['2026-05', '2026-03']);
});

it('opens a payroll run A→Z by employee, through a real join', function () {
    $run = Payroll::create([
        'asset_id' => $this->asset->id, 'period_month' => '2026-05-01', 'gross_salaries' => 0,
        'salary_tax' => 0, 'social_insurance' => 0, 'paid_from' => 'bank', 'status' => 'draft',
    ]);

    foreach ([['Z-1', 'Zeinab Fouad'], ['A-1', 'Amir Hassan']] as [$code, $name]) {
        $employee = Employee::create([
            'asset_id' => $this->asset->id, 'code' => $code, 'name' => $name,
            'hire_date' => '2026-01-01', 'base_salary' => 8000, 'payment_method' => 'bank',
        ]);
        PayrollLine::create([
            'payroll_id' => $run->id, 'employee_id' => $employee->id,
            'gross' => 8000, 'salary_tax' => 0, 'social_insurance' => 0,
        ]);
    }

    $this->actingAs(makeUser('super_admin', [$this->asset->id]));

    $names = asTenant($this->asset, fn () => tableRows(Livewire::test(PayrollLinesRelationManager::class, [
        'ownerRecord' => $run,
        'pageClass' => EditPayroll::class,
    ]))->map(fn ($line) => $line->employee->name)->all());

    expect($names)->toBe(['Amir Hassan', 'Zeinab Fouad']);
});

describe('the tenant portal', function () {
    beforeEach(fn () => Filament::setCurrentPanel(Filament::getPanel('portal')));
    afterEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

    it('opens CAM allocations on the most recent service-charge year', function () {
        $tenant = makeTenant(['name' => 'Cafe Crema']);
        $unit = makeUnit($this->asset);
        $lease = makeLease($unit, $tenant, ['status' => 'active']);

        // 2026 keyed FIRST, so id order and year order disagree.
        foreach ([2026, 2024] as $year) {
            $pool = CamExpensePool::create([
                'asset_id' => $this->asset->id, 'period_year' => $year,
                'total_actual_expense' => 100000, 'total_estimated_collected' => 90000, 'status' => 'reconciled',
            ]);
            CamAllocation::create([
                'cam_expense_pool_id' => $pool->id, 'lease_id' => $lease->id,
                'pro_rata_share_pct' => 100, 'allocated_amount' => 100000,
                'estimated_paid' => 90000, 'true_up_amount' => 10000, 'status' => 'billed',
            ]);
        }

        $this->actingAs(makeTenantUser($tenant), 'portal');

        $years = tableRows(Livewire::test(PortalListCamAllocations::class))
            ->map(fn ($a) => $a->pool->period_year)->all();

        expect($years)->toBe([2026, 2024]);
    });

    it('opens leases most-recently-commenced first, the same order the operator sees', function () {
        $tenant = makeTenant(['name' => 'Cafe Crema']);

        // The RECENT tenancy keyed FIRST, so insertion order and commencement order disagree.
        // Keyed the other way round both sorts give the same answer and the test proves nothing —
        // which is what the first version of this fixture did.
        $newer = makeLease(makeUnit($this->asset), $tenant, [
            'status' => 'active', 'commencement_date' => '2026-01-01', 'reference' => 'L-NEW',
        ]);
        $older = makeLease(makeUnit($this->asset), $tenant, [
            'status' => 'active', 'commencement_date' => '2024-01-01', 'reference' => 'L-OLD',
        ]);

        expect($older->id)->toBeGreaterThan($newer->id);

        $this->actingAs(makeTenantUser($tenant), 'portal');

        $refs = tableRows(Livewire::test(PortalListLeases::class))->pluck('reference')->all();

        expect($refs)->toBe(['L-NEW', 'L-OLD']);
    });

    it('opens the marketing feed featured-first, the same order the shopper sees', function () {
        $tenant = makeTenant(['name' => 'Cafe Crema']);
        makeLease(makeUnit($this->asset), $tenant, ['status' => 'active']);

        // The featured post keyed FIRST, so insertion order would bury it.
        $featured = MarketingPost::create([
            'asset_id' => $this->asset->id, 'tenant_id' => $tenant->id, 'type' => 'offer',
            'status' => 'published', 'audience' => MarketingPost::AUDIENCE_VISITORS, 'title' => 'Featured',
            'is_featured' => true, 'published_at' => now()->subDay(),
        ]);
        MarketingPost::create([
            'asset_id' => $this->asset->id, 'tenant_id' => $tenant->id, 'type' => 'offer',
            'status' => 'published', 'audience' => MarketingPost::AUDIENCE_VISITORS, 'title' => 'Ordinary',
            'is_featured' => false, 'published_at' => now(),
        ]);

        $this->actingAs(makeTenantUser($tenant), 'portal');

        $titles = tableRows(Livewire::test(PortalListMarketingPosts::class))->pluck('title')->all();

        expect($titles[0])->toBe('Featured')
            ->and($featured->id)->toBeLessThan(MarketingPost::where('title', 'Ordinary')->value('id'));
    });
});
