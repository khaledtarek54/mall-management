<?php

use App\Filament\Admin\RelationManagers\LeaseSalesDeclarationsRelationManager;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Filament\Admin\Widgets\ActionRequired;
use App\Filament\Portal\Resources\TenantSalesDeclarations\Pages\CreateTenantSalesDeclaration;
use App\Models\Asset;
use App\Models\Lease;
use App\Models\TenantSalesDeclaration;
use App\Notifications\SalesDeclarationLockedNotification;
use App\Notifications\SalesDeclarationReminderNotification;
use App\Services\Accounting\MonthEndReadinessService;
use App\Services\Reports\ReportService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Regression — SW-254. "Who owes a sales declaration" is ONE definition, and it is the lease's own
 * `requires_sales_reporting`.
 *
 * The duty became a lease term of its own on 2026-08-30 (`Lease::requiresSalesReporting()`, with
 * `scopeOwingSalesDeclaration()` as its SQL twin) — and reached the lease list's filter and nothing
 * else. `Lease::missingSalesDeclarationsFor()`, which the chase, the estimate and the month-end
 * checklist read, kept its own `where('has_percentage_rent', true)`, and the dashboard card kept a
 * third copy with its own fit-out test. So a percentage-rent tenant the operator had EXCUSED was
 * still chased on the 10th and still estimated on the 17th, a disclosure-only tenant was never
 * chased at all, and the list filter beside them showed the set the operator had actually ruled on.
 *
 * The helper is composed from the scope now; the card reads the helper. Every refusal here is
 * paired with the control that the unset percentage-rent lease beside it is still chased, estimated
 * and counted — a "fix" that stopped chasing altogether would satisfy the refusals alone.
 *
 * The review of the first cut found the other half: chasing a disclosure-only tenant sent them to
 * THREE doors still keyed on the charge — the portal picker refused the lease at validation, the
 * API answered 422 "does not have percentage-rent terms", and the app's `canDeclareSales` hid the
 * screen — while the two reports the disclosure is collected FOR never showed them, and the LOCK
 * notification told them "percentage rent owed: EGP 0.00". `Lease::declaresSales()` (the duty OR
 * the charge) is the one predicate those doors read now.
 */
beforeEach(function () {
    $this->asset = makeAsset();
    CarbonImmutable::setTestNow('2026-07-17 07:30:00');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    Filament::setTenant(null, isQuiet: true);
});

/** An active lease with three locked months behind it, so an estimate has a defensible history. */
function sw254Lease(Asset $asset, ?bool $requires, bool $percentage, array $attrs = []): Lease
{
    $lease = makeLease(makeUnit($asset), null, array_merge([
        'status' => 'active', 'commencement_date' => '2025-01-01', 'expiry_date' => '2028-12-31',
        'base_rent_monthly' => 100000,
        'has_percentage_rent' => $percentage,
        'percentage_rent_calculation_type' => $percentage ? 'artificial' : null,
        'percentage_rent_threshold' => $percentage ? 500000 : null,
        'percentage_rent_rate' => $percentage ? 5 : null,
        'requires_sales_reporting' => $requires,
    ], $attrs));

    foreach (['2026-03-01', '2026-04-01', '2026-05-01'] as $i => $start) {
        $month = CarbonImmutable::parse($start);
        TenantSalesDeclaration::create([
            'lease_id' => $lease->id,
            'period_start' => $month->toDateString(),
            'period_end' => $month->endOfMonth()->toDateString(),
            'declared_sales' => 800000 + $i * 100000,
            'declared_at' => $month->endOfMonth(),
            'status' => 'locked',
        ]);
    }

    return $lease->fresh();
}

/** The reminder as the chase records it — the company's own bell row — dated `$on`. */
function sw254RemindedOn(Lease $lease, string $on): void
{
    $was = CarbonImmutable::getTestNow();
    CarbonImmutable::setTestNow($on);
    $lease->tenant->notify(new SalesDeclarationReminderNotification($lease, 'June 2026', '2026-06'));
    CarbonImmutable::setTestNow($was);
}

function sw254Estimates(Lease $lease): int
{
    return TenantSalesDeclaration::where('lease_id', $lease->id)->whereDate('period_start', '2026-06-01')->where('is_estimate', true)->count();
}

it('does not chase a percentage-rent tenant the operator excused — and still chases the one beside it', function () {
    $excused = sw254Lease($this->asset, false, true);
    $control = sw254Lease($this->asset, null, true);

    $this->artisan('sales:scan-missing-declarations', ['--period' => '2026-06-01'])
        ->expectsOutputToContain('Reminded 1')
        ->assertSuccessful();

    expect($excused->salesDeclarationRemindedAt('2026-06'))->toBeNull()
        ->and($control->salesDeclarationRemindedAt('2026-06'))->not->toBeNull();
});

it('does not estimate an excused tenant even with a reminder a week old — and estimates the control', function () {
    // The reminder on the excused lease is the shape SW-253 guards for: it exists (an operator
    // could have sent one by hand before ruling the tenant excused), so only the DUTY can stop the
    // estimate here — the reminder gate would let it through.
    $excused = sw254Lease($this->asset, false, true);
    $control = sw254Lease($this->asset, null, true);
    sw254RemindedOn($excused, '2026-07-10 08:00:00');
    sw254RemindedOn($control, '2026-07-10 08:00:00');

    $this->artisan('sales:estimate-missing', ['--period' => '2026-06-01'])
        ->expectsOutputToContain('Raised 1 estimated declaration(s)')
        ->assertSuccessful();

    expect(sw254Estimates($excused))->toBe(0)
        ->and(sw254Estimates($control))->toBe(1);
});

it('chases a disclosure-only tenant, in the words of the clause they actually have', function () {
    Notification::fake();
    $disclosure = sw254Lease($this->asset, true, false);

    $this->artisan('sales:scan-missing-declarations', ['--period' => '2026-06-01'])
        ->expectsOutputToContain('Reminded 1')
        ->assertSuccessful();

    Notification::assertSentTo($disclosure->tenant, SalesDeclarationReminderNotification::class, function (SalesDeclarationReminderNotification $n) use ($disclosure) {
        $row = $n->toDatabase($disclosure->tenant);
        $mail = $n->toMail($disclosure->tenant);

        // The whole element: "percentage rent" must not appear in a sentence to a tenant whose
        // lease charges none, in the bell row AND the mail.
        return $row['body'] === __('admin.notifications.sales_reminder_body_disclosure', ['period' => 'June 2026'])
            && ! str_contains($row['body'], 'percentage rent')
            && collect($mail->introLines)->contains(fn ($l) => str_contains($l, 'monthly sales reporting'))
            && ! collect($mail->introLines)->contains(fn ($l) => str_contains($l, 'percentage rent'));
    });
});

it('still names the charge to a tenant who pays on the figure — the wording control', function () {
    $charging = sw254Lease($this->asset, null, true);

    $row = (new SalesDeclarationReminderNotification($charging, 'June 2026', '2026-06'))->toDatabase($charging->tenant);

    expect($row['body'])->toBe(__('admin.notifications.sales_reminder_body', ['period' => 'June 2026']))
        ->and($row['body'])->toContain('percentage rent');
});

it('says the disclosure-only sentence in Arabic too, and neither key is missing there', function () {
    foreach (['sales_reminder_body', 'sales_reminder_body_disclosure'] as $key) {
        expect(Lang::has('admin.notifications.'.$key, 'ar', fallback: false))->toBeTrue($key)
            ->and(__('admin.notifications.'.$key, ['period' => 'x'], 'ar'))->toMatch('/\p{Arabic}/u');
    }
});

it('never estimates a disclosure-only tenant — an estimate is a billing instrument — and records why', function () {
    $disclosure = sw254Lease($this->asset, true, false);
    sw254RemindedOn($disclosure, '2026-07-10 08:00:00');

    $ops = captureOpsLog(fn () => $this->artisan('sales:estimate-missing', ['--period' => '2026-06-01'])
        ->expectsOutputToContain('1 disclosure-only lease-month(s) left to the chase')
        ->assertSuccessful());

    $run = collect($ops)->firstWhere('message', 'sales.estimate_run');

    expect(sw254Estimates($disclosure))->toBe(0)
        ->and($run['context'])->toMatchArray(['raised' => 0, 'reporting_only' => [$disclosure->reference.' · 2026-06'], 'unchased' => 0]);
});

it('answers "declares sales" as the duty OR the charge, in the predicate and in SQL alike', function (?bool $requires, bool $percentage, bool $expected) {
    $lease = sw254Lease($this->asset, $requires, $percentage);

    expect($lease->declaresSales())->toBe($expected)
        ->and(Lease::declaringSales()->whereKey($lease->getKey())->exists())->toBe($expected);
})->with([
    'unset on a fixed-rent lease — nothing to declare' => [null, false, false],
    'unset on a percentage lease' => [null, true, true],
    'disclosure-only' => [true, false, true],
    'excused — still may file, the charge needs it' => [false, true, true],
]);

it('lets a disclosure-only tenant file through the API it was chased to', function () {
    Storage::fake('local');
    $disclosure = sw254Lease($this->asset, true, false);

    $this->postJson('/api/v1/me/sales-declarations', [
        'lease_id' => $disclosure->id, 'period_start' => '2026-06-01', 'period_end' => '2026-06-30',
        'attachments' => [UploadedFile::fake()->create('june.pdf', 100, 'application/pdf')],
    ], apiHeaders($disclosure->tenant))->assertCreated();
});

it('still refuses the API door to a lease with neither clause — the control, in its own case', function () {
    // One request per case: Sanctum's guard memoises the first user of a test.
    Storage::fake('local');
    $neither = sw254Lease($this->asset, null, false);

    $this->postJson('/api/v1/me/sales-declarations', [
        'lease_id' => $neither->id, 'period_start' => '2026-06-01', 'period_end' => '2026-06-30',
        'attachments' => [UploadedFile::fake()->create('june.pdf', 100, 'application/pdf')],
    ], apiHeaders($neither->tenant))->assertStatus(422)->assertJsonValidationErrors(['leaseId']);
});

it('tells the app a disclosure-only tenant can declare, and a fixed-rent one cannot', function (?bool $requires, bool $percentage, bool $expected) {
    $lease = sw254Lease($this->asset, $requires, $percentage);

    $this->getJson('/api/v1/me/summary', apiHeaders($lease->tenant))->assertOk()
        ->assertJsonPath('data.canDeclareSales', $expected);
})->with([
    'disclosure-only' => [true, false, true],
    'neither' => [null, false, false],
]);

it('offers a disclosure-only lease on the portal picker and not one with neither clause', function () {
    $disclosure = sw254Lease($this->asset, true, false);
    $neither = sw254Lease($this->asset, null, false, ['tenant_id' => $disclosure->tenant_id]);

    $this->actingAs(makeTenantUser($disclosure->tenant), 'portal');
    Filament::setCurrentPanel(Filament::getPanel('portal'));

    $offered = Livewire::test(CreateTenantSalesDeclaration::class)
        ->instance()->form->getComponent('lease_id', withHidden: true)
        ->getOptions();

    expect(array_keys($offered))->toContain($disclosure->id)->not->toContain($neither->id);
});

it('keeps the declarations tab on an excused percentage-rent lease — the months they did file live there', function () {
    $excused = sw254Lease($this->asset, false, true);
    $neither = sw254Lease($this->asset, null, false);

    expect(LeaseSalesDeclarationsRelationManager::canViewForRecord($excused, EditLease::class))->toBeTrue()
        ->and(LeaseSalesDeclarationsRelationManager::canViewForRecord($neither, EditLease::class))->toBeFalse();
});

it('shows a disclosure-only tenant on the two reports the disclosure is collected for', function () {
    $disclosure = sw254Lease($this->asset, true, false);
    $neither = sw254Lease($this->asset, null, false);
    $svc = app(ReportService::class);

    $occupancy = $svc->occupancyCost(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-06-30'), $this->asset->id)->pluck('lease_id');
    $analytics = collect($svc->salesAnalytics(CarbonImmutable::parse('2026-07-17'), $this->asset->id)['rows'])->pluck('lease_id');

    expect($occupancy)->toContain($disclosure->id)->not->toContain($neither->id)
        ->and($analytics)->toContain($disclosure->id)->not->toContain($neither->id);
});

it('locks a disclosure-only declaration without naming a percentage rent the tenant does not owe', function () {
    $disclosure = sw254Lease($this->asset, true, false);
    $charging = sw254Lease($this->asset, null, true);
    $declaration = fn (Lease $l) => TenantSalesDeclaration::create([
        'lease_id' => $l->id, 'period_start' => '2026-06-01', 'period_end' => '2026-06-30',
        'declared_sales' => 900000, 'declared_at' => '2026-07-03', 'status' => 'locked', 'calculated_percentage_rent' => 0,
    ]);

    $quiet = new SalesDeclarationLockedNotification($declaration($disclosure));
    $loud = new SalesDeclarationLockedNotification($declaration($charging));

    expect($quiet->toDatabase($disclosure->tenant)['body'])->toBe(__('admin.notifications.sales_locked_short_disclosure', ['period' => 'Jun 2026']))
        ->and(collect($quiet->toMail($disclosure->tenant)->introLines)->implode(' '))->not->toContain('ercentage rent')
        // The control: a charging lease still reads the amount, even when it is zero.
        ->and($loud->toDatabase($charging->tenant)['body'])->toContain('percentage rent EGP 0.00');

    foreach (['sales_locked_body_disclosure', 'sales_locked_short_disclosure'] as $key) {
        expect(Lang::has('admin.notifications.'.$key, 'ar', fallback: false))->toBeTrue($key);
    }
});

it('counts the same set on the dashboard card, the month-end checklist and the helper', function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    // The card counts LAST month, so this case runs on 17 July for June. TWO disclosure-only
    // leases against ONE excused, so the old definition (excused + unset = 2) cannot land on the
    // same number as the right one (unset + two disclosure = 3) — the first cut had one of each
    // and stayed green with the defect restored.
    sw254Lease($this->asset, false, true);                       // excused — not counted
    sw254Lease($this->asset, null, true);                        // percentage rent, unset — counted
    sw254Lease($this->asset, true, false);                       // disclosure-only — counted
    sw254Lease($this->asset, true, false);                       // disclosure-only — counted
    sw254Lease($this->asset, null, true, [                       // still in fit-out — not counted
        'commencement_date' => '2026-05-01', 'rent_commencement_date' => '2026-12-01', 'fit_out_scope' => Lease::FIT_OUT_GROSS,
    ]);
    sw254Lease(makeAsset(), null, true);                         // another mall — not counted here

    $june = CarbonImmutable::parse('2026-06-01');
    $helper = Lease::missingSalesDeclarationsFor($june, $this->asset->id)->count();

    $this->actingAs(makeUser('manager', [$this->asset->id]));
    Filament::setTenant($this->asset);
    $card = collect((new ActionRequired)->getViewData()['items'])->firstWhere('key', 'missing_sales');

    $checklist = collect(app(MonthEndReadinessService::class)->for($june, $this->asset->id)['steps'])
        ->firstWhere('key', 'sales_declared')['count'];

    expect($helper)->toBe(3)
        ->and($card)->not->toBeNull()
        ->and($card['title'])->toBe(trans_choice('admin.widgets.action_required.missing_sales', 3, ['count' => 3]))
        ->and($checklist)->toBe(3);
});

it('scopes to NOTHING when handed no properties, and to everything when handed null', function () {
    // The wrong direction for a scope handed an empty set is the whole portfolio — `->when([])`
    // is falsy and would have skipped the clause.
    sw254Lease($this->asset, null, true);
    sw254Lease(makeAsset(), null, true);
    $june = CarbonImmutable::parse('2026-06-01');

    expect(Lease::missingSalesDeclarationsFor($june, [])->count())->toBe(0)
        ->and(Lease::missingSalesDeclarationsFor($june, [$this->asset->id])->count())->toBe(1)
        ->and(Lease::missingSalesDeclarationsFor($june, $this->asset->id)->count())->toBe(1)
        ->and(Lease::missingSalesDeclarationsFor($june)->count())->toBe(2);
});
