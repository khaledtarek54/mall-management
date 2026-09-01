<?php

use App\Mail\SavedReportDelivered;
use App\Models\SavedReport;
use App\Services\Reports\DeliverSavedReportService;
use App\Support\ReportParameters;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;

/**
 * A scheduled report carries the mall it was saved in — off the screen as well as on it.
 *
 * Most report pages carry no `$assetId` property. They scope with `TenantScope::currentAssetId()`,
 * which reads the Filament tenant — the mall the operator is standing in. That is right on screen
 * and reproduces nothing off it: a scheduled delivery runs in a queue worker where no tenant is
 * set, `currentAssetId()` answers **null**, and every scoped query reads null as *no property
 * filter*.
 *
 * So a rent roll saved in one mall was emailed every month as the WHOLE PORTFOLIO — tenant names,
 * contracted rents, rates per square metre, security deposits — to whatever addresses the schedule
 * names. Those are routinely outside the business: the recipients field invites the owner's
 * accountant and the auditor precisely because they have no login here, which is also why they
 * cannot tell whose tenants they are reading. The CSV carries no property column and the filename
 * names no mall.
 *
 * The fixture puts a lease in EACH of two malls, because a one-mall fixture cannot fail: with only
 * one property's data present, a portfolio-scoped render and a correctly-scoped one produce the
 * same file.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->mallA = makeAsset(['code' => 'AA', 'name' => 'Atriom Walk']);
    $this->mallB = makeAsset(['code' => 'BB', 'name' => 'Nile Plaza']);

    $this->leaseA = makeLease(makeUnit($this->mallA), null, ['status' => 'active']);
    $this->leaseB = makeLease(makeUnit($this->mallB), null, ['status' => 'active']);

    $this->operator = makeUser('super_admin');
    $this->actingAs($this->operator);
});

/** Save the rent roll the way the screen does — through the real snapshot. */
function savedRentRollIn(App\Models\Asset $mall): SavedReport
{
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($mall, isQuiet: true);

    $page = app(App\Filament\Admin\Pages\RentRoll::class);
    $page->mount();

    $saved = SavedReport::create([
        'report' => 'rent_roll',
        'name' => 'Monthly rent roll',
        'parameters' => ReportParameters::snapshot($page),
        'user_id' => test()->operator->id,
        'recipients' => ['owners-accountant@outside.test'],
        'frequency' => 'monthly',
        'day_of_month' => 1,
    ]);

    // The delivery runs in a queue worker, where nothing has set a tenant. Clearing it here is what
    // makes this test measure the real thing rather than the state the save happened to leave.
    Filament::setTenant(null, isQuiet: true);

    return $saved;
}

it('emails only the mall the view was saved in', function () {
    Mail::fake();

    $saved = savedRentRollIn($this->mallA);

    expect(app(DeliverSavedReportService::class)->deliver($saved))->toBeTrue();

    Mail::assertSent(SavedReportDelivered::class, function (SavedReportDelivered $mail) {
        // The control and the refusal in one assertion: mall A's tenant must be in the file (or the
        // test would pass on an empty CSV), and mall B's must not.
        expect($mail->csv)->toContain($this->leaseA->tenant->name)
            ->and($mail->csv)->not->toContain($this->leaseB->tenant->name);

        return true;
    });
});

it('refuses to deliver a view that recorded no property rather than sending the portfolio', function () {
    Mail::fake();

    $saved = savedRentRollIn($this->mallA);
    // A view saved before the property was captured. It is indistinguishable from one deliberately
    // spanning the portfolio, and only one of those is safe to send to an external address.
    $saved->update(['parameters' => collect($saved->parameters)->except(ReportParameters::PROPERTY_KEY)->all()]);

    expect(app(DeliverSavedReportService::class)->deliver($saved))->toBeFalse();

    Mail::assertNothingSent();
});

it('refuses once the owner loses access to that mall', function () {
    Mail::fake();

    // A RESTRICTED operator, deliberately: `AssignedAssets::idsFor()` answers null for a super
    // admin, so a super admin can never lose a mall and this refusal could not be measured on one.
    $manager = makeUser('manager');
    $manager->assignedAssets()->sync([$this->mallA->id, $this->mallB->id]);
    $this->actingAs($manager);

    $saved = savedRentRollIn($this->mallA);
    $saved->update(['user_id' => $manager->id]);

    // The control: while they hold mall A, the delivery goes out.
    expect(app(DeliverSavedReportService::class)->deliver($saved->fresh()))->toBeTrue();
    Mail::assertSent(SavedReportDelivered::class);

    // A schedule is not a standing grant — the same reasoning that renders as the owner rather than
    // as nobody. Moved off mall A, they stop receiving mall A's rent roll.
    $manager->assignedAssets()->sync([$this->mallB->id]);

    expect(app(DeliverSavedReportService::class)->deliver($saved->fresh()))->toBeFalse();
    Mail::assertSent(SavedReportDelivered::class, 1);   // still just the control's
});
