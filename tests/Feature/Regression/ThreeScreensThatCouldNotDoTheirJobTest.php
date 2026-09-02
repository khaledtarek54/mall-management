<?php

use App\Filament\Admin\Actions\ServicePlanActions;
use App\Models\AccountingPeriod;
use App\Models\Custody;
use App\Models\Employee;
use App\Models\Lease;
use App\Services\Accounting\FiscalCalendar;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * Three screens that could not do their job, batched because each is one line of cause.
 *
 * - **Service Plans › Generate due** called `ServicePlanActions::report()`, which was `protected
 *   static` — a cross-class call PHP refuses. The generation RAN and then the page fatalled on
 *   reporting it, so the operator saw an error page after the work had already been done and could
 *   not tell whether it had.
 * - **The tenant portal listed DRAFT and PENDING_APPROVAL leases**, so a retailer read their rent,
 *   term and deposit off terms nobody had agreed — and would reasonably treat them as settled.
 *   Same rule `TenantVisibility` applies to invoices and credit notes, and the same reason: *whose
 *   row is this* and *has it been agreed* are two questions.
 * - **A custody's date could be back-dated into a CLOSED period from the Edit form.** `Custody`
 *   declares `#[PostingDateGuardedBy(GrantCustodyService::class)]` and that service asserts it, but
 *   the edit form reached the same column unguarded: the row saves, the operator reads "Saved", and
 *   the GL re-post is refused inside the best-effort sync that only logs.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->asset = makeAsset();
});

it('can report the preventive run it just performed', function () {
    // `protected static` on a method called from another class is a PHP Error, not a warning — the
    // generation completes and the page dies telling anyone about it.
    $method = new ReflectionMethod(ServicePlanActions::class, 'report');

    expect($method->isPublic())->toBeTrue()
        ->and($method->isStatic())->toBeTrue();
});

it('never shows a tenant a lease nobody has agreed', function () {
    $tenant = makeTenant();
    $unit = makeUnit($this->asset);

    $draft = makeLease($unit, $tenant, ['status' => 'draft']);
    $pending = makeLease(makeUnit($this->asset), $tenant, ['status' => 'pending_approval']);
    $live = makeLease(makeUnit($this->asset), $tenant, ['status' => 'active']);
    $ended = makeLease(makeUnit($this->asset), $tenant, ['status' => 'terminated']);

    $visible = Lease::query()
        ->where('tenant_id', $tenant->id)
        ->whereNotIn('status', ['draft', 'pending_approval'])
        ->pluck('id');

    expect($visible)->not->toContain($draft->id)
        ->and($visible)->not->toContain($pending->id)
        // …and the control: a lease that ENDED still explains a tenancy the tenant remembers, so
        // excluding rather than allowlisting is what keeps it in their history.
        ->and($visible)->toContain($live->id)
        ->and($visible)->toContain($ended->id);
});

it('lets a SETTLED custody keep being edited, and still refuses a back-date', function () {
    // Both halves, because the first attempt at this fix broke the first one. The guard was put on
    // `EditCustody::mutateFormDataBeforeSave()`, and `CustodyForm` DISABLES `custody_date` once
    // anything has been spent — a disabled field is not dehydrated, so `$data['custody_date']` was
    // absent, `?? null` handed null to `assertOpen()`, and it threw "a date is required" on a date
    // that had not moved. Editing only the purpose of a settled عهدة became impossible: exactly
    // what `Custody`'s own docblock promises stays editable. 28 custody tests were green over it.
    //
    // `GuardsPostingDate` is the prescribed seam and is dirty-only + `filled()`-guarded for those
    // two reasons — and on the model it covers the importer, the console and the API too.
    app(FiscalCalendar::class)->ensureYear((int) CarbonImmutable::now()->year);

    $employee = Employee::create([
        'asset_id' => $this->asset->id,
        'name' => 'Site engineer',
        'code' => 'EMP-'.substr(uniqid(), -6),
        'status' => 'active',
        'hire_date' => CarbonImmutable::now()->subYear()->toDateString(),
        'base_salary' => 12000,
        'payment_method' => 'bank',
    ]);

    $custody = Custody::create([
        'asset_id' => $this->asset->id,
        'employee_id' => $employee->id,
        'amount' => 5000,
        'custody_date' => CarbonImmutable::now()->toDateString(),
        'purpose' => 'Site consumables',
        // No `status` key: `custody` has no such column — whether an advance is still outstanding
        // is DERIVED from what has been settled against it. Eloquent dropped it silently, so the
        // fixture read as though it were stating something it never stated.
    ]);

    AccountingPeriod::forDate(CarbonImmutable::now())->update(['status' => 'closed']);

    // The date has NOT moved — editing anything else must still work, closed period or not.
    $custody->fresh()->update(['purpose' => 'Site consumables and PPE']);

    expect($custody->fresh()->purpose)->toBe('Site consumables and PPE');

    // Moving it WITHIN the closed period is what is refused. A day in the previous month would
    // land in a period that is still open and prove nothing — the arithmetic has to stay inside the
    // month that was closed.
    $elsewhereInTheClosedMonth = CarbonImmutable::now()->endOfMonth()->startOfDay();

    expect(AccountingPeriod::forDate($elsewhereInTheClosedMonth)->isOpen())->toBeFalse();

    expect(fn () => $custody->fresh()->update([
        'custody_date' => $elsewhereInTheClosedMonth->toDateString(),
    ]))->toThrow(DomainException::class);
});
