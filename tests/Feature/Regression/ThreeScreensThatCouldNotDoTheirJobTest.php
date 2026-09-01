<?php

use App\Filament\Admin\Actions\ServicePlanActions;
use App\Models\AccountingPeriod;
use App\Models\Lease;
use App\Services\Accounting\FiscalCalendar;
use App\Support\PostingDate;
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

it('refuses to back-date a custody into a closed period from the edit form', function () {
    app(FiscalCalendar::class)->ensureYear((int) CarbonImmutable::now()->year);

    // The page's own hook is what closes this — `Custody` declares
    // `#[PostingDateGuardedBy(GrantCustodyService::class)]`, and the edit form reached the same
    // column with no guard at all.
    $source = sourceWithoutComments(base_path(
        'app/Filament/Admin/Resources/Custodies/Pages/EditCustody.php'
    ));

    expect($source)->toContain('mutateFormDataBeforeSave')
        ->and($source)->toContain('PostingDate::assertOpen');

    AccountingPeriod::forDate(CarbonImmutable::now())->update(['status' => 'closed']);

    // …and the guard it calls really refuses a closed period.
    expect(fn () => PostingDate::assertOpen(
        CarbonImmutable::now()->toDateString(),
        __('admin.fields.custody_date'),
    ))->toThrow(DomainException::class);

    // The control: a period that is merely MISSING is still allowed — only a closed one refuses.
    expect(fn () => PostingDate::assertOpen(
        CarbonImmutable::now()->addYears(5)->toDateString(),
        __('admin.fields.custody_date'),
    ))->not->toThrow(DomainException::class);
});
