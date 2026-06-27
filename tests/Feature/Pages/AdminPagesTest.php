<?php

use App\Actions\Api\Auth\LogoutTenantAction;
use App\Filament\Admin\Pages\ArAging;
use App\Filament\Admin\Pages\Reports;
use App\Settings\ModulesSettings;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

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

it('Reports page resolves the period from request + mount()', function () {
    $page = new Reports;
    $page->period = '2026-02';
    $data = callPageView($page);

    expect($data)->toHaveKeys(['period', 'report', 'recentPeriods']);
    expect($data['period']->format('Y-m'))->toBe('2026-02');
    expect($data['recentPeriods'])->toHaveCount(12);
});

it('Reports page falls back to current month when the period string is malformed', function () {
    $page = new Reports;
    $page->period = 'not-a-date';
    $data = callPageView($page);

    expect($data['period']->format('Y-m'))->toBe(now()->format('Y-m'));
});

it('Reports page downloadMonthlyClose returns a PDF streamed response', function () {
    $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('accounting'));   // holds reports.download

    $page = new Reports;
    $page->period = '2026-02';

    $response = $page->downloadMonthlyClose();

    expect($response)->toBeInstanceOf(\Symfony\Component\HttpFoundation\StreamedResponse::class);
    expect($response->headers->get('Content-Type'))->toBe('application/pdf');
});

it('Reports page gating + navigation reflect the reports module toggle', function () {
    $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
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
    $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
    $user = makeUser('manager', [$this->asset->id]);
    $user->syncPermissions([]);
    $user->syncRoles([]);
    $this->actingAs($user);

    expect(Reports::canAccess())->toBeFalse();
});

it('Reports page exposes title + nav labels (translations resolved)', function () {
    expect((new Reports)->getTitle())->toBeString()->not->toBeEmpty();
    expect(Reports::getNavigationLabel())->toBeString()->not->toBeEmpty();
    expect(Reports::getNavigationGroup())->toBeString()->not->toBeEmpty();
});

/* ─────────────── ArAging drilldown page ─────────────── */

it('ArAging page builds view data with bucket labels + total balance', function () {
    // Overdue invoice — falls in d_1_30 bucket.
    makeInvoice($this->lease, [
        'status' => 'issued',
        'issue_date' => now()->subDays(15),
        'due_date' => now()->subDays(10),
        'balance' => 5000, 'paid_amount' => 0, 'total' => 5000,
    ]);

    asTenant($this->asset, function () {
        $page = new ArAging;
        $page->bucket = 'd_1_30';
        $data = callPageView($page);

        expect($data)->toHaveKeys(['invoices', 'bucket', 'buckets', 'totalBalance']);
        expect($data['bucket'])->toBe('d_1_30');
        expect($data['buckets'])->toHaveCount(5);
        expect($data['totalBalance'])->toBe(5000.0);
    });
});

it('ArAging page exposes title + access gate + nav group', function () {
    $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('manager', [$this->asset->id]));

    expect((new ArAging)->getTitle())->toBeString()->not->toBeEmpty();
    expect(ArAging::canAccess())->toBeTrue();
    expect(ArAging::getNavigationGroup())->toBeString();
});

/* ─────────────── LogoutTenantAction ─────────────── */

it('LogoutTenantAction deletes the current Sanctum token', function () {
    $token = $this->tenant->createToken('api')->accessToken;

    expect(PersonalAccessToken::where('id', $token->id)->exists())->toBeTrue();

    app(LogoutTenantAction::class)->handle($this->tenant, $token);

    expect(PersonalAccessToken::where('id', $token->id)->exists())->toBeFalse();
});
