<?php

/*
|--------------------------------------------------------------------------
| "Nothing was billed" must say WHY — on every screen that bills by hand (2026-08-28)
|--------------------------------------------------------------------------
| Reported from the panel. An operator pressed "Bill this period" on a lease's Billing forecast tab
| and was shown:
|
|     Nothing was billed
|     admin.billing_preview.reason.lease_not_billable
|
| Two defects in one toast. The visible one is the raw key: `MonthlyBillingService::generateForLease()`
| answers three reason codes a PLAN never produces, and the tab rendered them through
| `admin.billing_preview.reason.*` — the short vocabulary a preview table CELL uses, which had
| wording for the plan's codes only.
|
| The invisible one is why it was ever possible. The lease's own "Generate Invoice" action turned
| the same codes into words with a seven-branch ladder of its own — a title and a paragraph of
| advice per code, including a three-way reading of `lease_not_billable` (wrong status / not yet
| commenced / term ended). One machine code, two independent translations, and only one of them was
| updated when the vocabulary grew. Both now go through `App\Support\BillingRefusal`.
|
| The forecast tab is where it surfaced because it OFFERS the button on a lease that cannot bill:
| the forecast deliberately projects a draft lease (that is what the "not active" caveat above the
| table is for), so the refusal happens at the click and the wording is all the operator gets.
*/

use App\Filament\Admin\RelationManagers\BillingForecastRelationManager;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Models\Lease;
use App\Services\LeaseCreationService;
use App\Support\BillingRefusal;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Notifications\Livewire\Notifications;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'RF']);
    $this->unit = makeUnit($this->asset, ['code' => 'RF-01', 'status' => 'vacant', 'area_sqm' => 100]);
    CarbonImmutable::setTestNow('2026-09-15');

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    Filament::setTenant(null, isQuiet: true);
    app()->setLocale('en');
});

function refusalLease(object $ctx, array $attrs = []): Lease
{
    $lease = makeLease($ctx->unit, makeTenant(), array_merge([
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2027-12-31',
        'base_rent_monthly' => 50000,
        'service_charge_monthly' => 5000,
        'has_marketing_levy' => false,
        'escalation_type' => 'none',
        'escalation_rate' => 0,
    ], $attrs));

    LeaseCreationService::seedStandardCharges($lease, rent: 50000, service: 5000);

    return $lease->fresh();
}

/**
 * The toast the forecast tab's own button sent — read the way Filament's own `assertNotified()`
 * reads it, by mounting the notifications component. `Notification::send()` pushes to the session
 * and the component CLAIMS it from there, so a test that reads `session('filament.notifications')`
 * directly finds nothing and reports a screen that sent no notification at all.
 */
function lastForecastToast(Lease $lease): array
{
    session()->forget(['filament.notifications', 'filament.claimed_notifications']);

    Livewire::test(BillingForecastRelationManager::class, [
        'ownerRecord' => $lease,
        'pageClass' => EditLease::class,
    ])->callAction(TestAction::make('billPeriod')->table(0));

    $notifications = new Notifications;
    $notifications->mount();

    return collect($notifications->notifications)->map->toArray()->last() ?? [];
}

it('names the cause when the forecast tab refuses to bill a lease that is not active', function () {
    $lease = refusalLease($this, ['status' => 'draft']);

    $toast = lastForecastToast($lease);

    // The bug, exactly: the body WAS the key.
    expect($toast['body'] ?? '')->not->toContain('admin.billing_preview')
        ->and($toast['body'] ?? '')->not->toMatch('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+){2,}$/i')
        // …and it says which of the three refusals this is, which is the whole point of saying
        // anything: "cannot be billed" leaves the operator with nowhere to go.
        ->and($toast['body'])->toContain(__('admin.statuses.lease.draft'))
        ->and($toast['title'])->toBe(__('admin.actions.not_billable_title', ['period' => 'September 2026']))
        ->and($toast['status'])->toBe('warning')
        ->and($lease->invoices()->count())->toBe(0);
});

it('reads in Arabic for an Arabic operator, month included', function () {
    $lease = refusalLease($this, ['status' => 'draft']);

    app()->setLocale('ar');

    $toast = lastForecastToast($lease);

    expect($toast['title'])->toMatch('/\p{Arabic}/u')
        ->and($toast['body'])->toMatch('/\p{Arabic}/u')
        // `format('F Y')` is not localised, so the ladder this replaced put an English month in the
        // middle of an Arabic sentence on every one of its seven branches.
        ->and($toast['title'])->not->toContain('September')
        // …and no placeholder the call site forgot to fill. `not_billable_expired` shipped with one.
        ->and($toast['body'])->not->toMatch('/:[a-z_]{3,}/');
});

it('still bills a lease that can be billed — the refusal is not a broken button', function () {
    // A refusal test passes just as happily when the action is a no-op, so pair it with the control.
    $lease = refusalLease($this);

    $toast = lastForecastToast($lease);

    expect($toast['title'])->toBe(__('admin.actions.invoice_created'))
        ->and($lease->invoices()->count())->toBe(1);
});

it('gives the same wording on the lease page, because there is only one', function () {
    // The lease's own Generate Invoice action and the forecast tab's button are two screens over
    // one service. They used to word a refusal independently; that is what let one of them fall
    // behind. Compared through the presenter both now call, on the case that was broken.
    $lease = refusalLease($this, ['status' => 'draft']);
    $period = CarbonImmutable::parse('2026-09-01');

    $refusal = BillingRefusal::explain($lease, $period, [
        'status' => 'skipped', 'reason' => 'lease_not_billable',
    ]);

    expect(lastForecastToast($lease)['body'])->toBe($refusal['body']);
});
