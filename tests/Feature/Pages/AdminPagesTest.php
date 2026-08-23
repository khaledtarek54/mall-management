<?php

use App\Actions\Api\Auth\LogoutTenantAction;
use App\Filament\Admin\Pages\ArAging;
use App\Filament\Admin\Pages\Reports;
use App\Filament\Admin\Widgets\MonthlyCloseStats;
use App\Settings\ModulesSettings;
use App\Support\Navigation;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Freeze "now" so CarbonImmutable::createFromFormat('Y-m', ...) on the Reports
// page is deterministic regardless of which day-of-month the suite runs on.
beforeEach(fn () => Carbon::setTestNow('2026-02-15 10:00:00'));
afterEach(fn () => Carbon::setTestNow());

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->unit = makeUnit($this->asset, ['status' => 'occupied']);
    $this->tenant = makeTenant();
    $this->lease = makeLease($this->unit, $this->tenant);
});

function callPageView(object $page, string $method = 'getViewData'): array
{
    $ref = new ReflectionMethod($page, $method);
    $ref->setAccessible(true);

    return $ref->invoke($page);
}

/* ─────────────── Reports page ─────────────── */

it('Reports page resolves the period it was given', function () {
    expect(Reports::parsePeriod('2026-02')->format('Y-m'))->toBe('2026-02');
});

it('Reports page falls back to current month when the period string is malformed', function () {
    // A hand-edited ?period= must not 500 the page.
    expect(Reports::parsePeriod('not-a-date')->format('Y-m'))->toBe(now()->format('Y-m'))
        ->and(Reports::parsePeriod(null)->format('Y-m'))->toBe(now()->format('Y-m'));
});

it('Reports page and its stats widget always describe the SAME month', function () {
    // The KPI cards live in a widget and the revenue table on the page; both
    // parse the same string, so they must not be able to drift apart.
    $widget = new MonthlyCloseStats;
    $widget->period = 'not-a-date';

    $resolve = new ReflectionMethod($widget, 'resolvePeriod');
    $resolve->setAccessible(true);

    expect($resolve->invoke($widget)->format('Y-m'))
        ->toBe(Reports::parsePeriod('not-a-date')->format('Y-m'));
});

it('Reports page lists revenue by type for the selected month', function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('manager', [$this->asset->id]));

    asTenant($this->asset, function () {
        $component = Livewire::test(Reports::class)->assertOk();

        // Renders (and the table compiles) for a month with no activity too.
        expect(collect($component->instance()->getTableRecords()))->toBeInstanceOf(Collection::class);
    });
});

it('Reports page downloadMonthlyClose returns a PDF streamed response', function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('accounting'));   // holds reports.download

    $page = new Reports;
    $page->period = '2026-02';

    $response = $page->downloadMonthlyClose();

    expect($response)->toBeInstanceOf(StreamedResponse::class);
    expect($response->headers->get('Content-Type'))->toBe('application/pdf');
});

it('Reports page gating + navigation reflect the reports module toggle', function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('manager', [$this->asset->id]));

    $settings = app(ModulesSettings::class);
    $settings->reports = false;
    $settings->save();

    expect(Reports::canAccess())->toBeFalse();
    expect(Reports::shouldRegisterNavigation())->toBeFalse();

    $settings->reports = true;
    $settings->save();

    expect(Reports::canAccess())->toBeTrue();
});

it('Reports page denies access to users that lack reports.view', function () {
    $this->seed(RolesPermissionsSeeder::class);
    $user = makeUser('manager', [$this->asset->id]);
    $user->syncPermissions([]);
    $user->syncRoles([]);
    $this->actingAs($user);

    expect(Reports::canAccess())->toBeFalse();
});

it('Reports page exposes title + nav labels (translations resolved)', function () {
    expect((new Reports)->getTitle())->toBeString()->not->toBeEmpty();
    expect(Reports::getNavigationLabel())->toBeString()->not->toBeEmpty();

    // The GROUP is no longer the page's to answer — App\Support\Navigation places every screen,
    // and the panel renders from that registry rather than from ninety-nine `getNavigationGroup()`
    // declarations. Asking the page returns null now, which is correct and says nothing; asking the
    // registry is the same question with a real answer.
    expect(Navigation::groupOf(Reports::class))->toBe('reports');
});

/* ─────────────── ArAging drilldown page ─────────────── */

it('ArAging lists the selected bucket\'s invoices and totals them', function () {
    // Overdue invoice — falls in the d_1_30 bucket.
    $invoice = makeInvoice($this->lease, [
        'status' => 'issued',
        'issue_date' => now()->subDays(15),
        'due_date' => now()->subDays(10),
        'balance' => 5000, 'paid_amount' => 0, 'total' => 5000,
    ]);

    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('manager', [$this->asset->id]));

    // The page is a native Filament table now, so assert the rows it returns
    // rather than a view-data array.
    asTenant($this->asset, function () use ($invoice) {
        $component = Livewire::test(ArAging::class)->set('bucket', 'd_1_30');

        expect(tableRows($component)->pluck('id')->all())
            ->toEqual([$invoice->id]);

        // The bucket total is stated on the page — it is what a collections
        // call is prioritised by.
        expect($component->instance()->getSubheading())->toContain('5,000.00');

        // A bucket the invoice does not belong in must come back empty.
        $other = Livewire::test(ArAging::class)->set('bucket', 'd_90_plus');
        expect(tableRows($other))->toBeEmpty();
    });

    expect(ArAging::buckets())->toHaveCount(5);
});

it('ArAging page exposes title + access gate + nav group', function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('manager', [$this->asset->id]));

    expect((new ArAging)->getTitle())->toBeString()->not->toBeEmpty();
    expect(ArAging::getNavigationLabel())->toBeString()->not->toBeEmpty();
    expect(ArAging::canAccess())->toBeTrue();

    // In the sidebar since 2026-08-23. It used to carry `$shouldRegisterNavigation = false` and be
    // reachable only from the reports hub, which is why it had no navigation label either and
    // Filament derived "Ar Aging" from the class name.
    expect(Navigation::groupOf(ArAging::class))->toBe('reports');
    expect(ArAging::shouldRegisterNavigation())->toBeTrue();
});

/* ─────────────── LogoutTenantAction ─────────────── */

it('LogoutTenantAction deletes the current Sanctum token', function () {
    $token = $this->tenant->createToken('api')->accessToken;

    expect(PersonalAccessToken::where('id', $token->id)->exists())->toBeTrue();

    app(LogoutTenantAction::class)->handle($this->tenant, $token);

    expect(PersonalAccessToken::where('id', $token->id)->exists())->toBeFalse();
});
