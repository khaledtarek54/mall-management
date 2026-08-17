<?php

use App\Filament\Admin\Resources\Violations\Pages\ListViolations;
use App\Filament\Admin\Resources\Violations\ViolationResource;
use App\Models\Asset;
use App\Models\Charge;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Tenant;
use App\Models\Violation;
use App\Notifications\ViolationNoticeNotification;
use App\Support\Search\OptionDisplay;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Tenant violations (module 31) — FR-REQ-15/16/17.
 *
 *  - FR-REQ-15: record a violation against a tenant WITH an associated fine — and
 *    NOT bill it (no Invoice / Charge / InvoiceItem is created).
 *  - FR-REQ-16: the register is property-scoped + RBAC-gated (authorized staff).
 *  - FR-REQ-17: an EXPLICIT "Send notice" action reaches the tenant via the real
 *    tenant-notify path and stamps notified_at; gated in visible() AND action().
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'VIO']);
});

/** A tenant leasing in the given property (so the scoped select would offer it). */
function tenantLeasingIn(Asset $asset): Tenant
{
    $tenant = makeTenant();
    makeLease(makeUnit($asset), $tenant);

    return $tenant;
}

function makeViolation(int $assetId, int $tenantId, array $attrs = []): Violation
{
    return Violation::create(array_merge([
        'asset_id' => $assetId,
        'tenant_id' => $tenantId,
        'description' => 'Blocked fire exit',
        'violation_date' => now()->toDateString(),
    ], $attrs));
}

/* ---- FR-REQ-15: record a violation with a fine, do NOT bill it ---------- */

it('records a violation against a tenant with an associated fine', function () {
    $tenant = tenantLeasingIn($this->asset);

    $violation = makeViolation($this->asset->id, $tenant->id, [
        'description' => 'Unauthorised signage on the shopfront',
        'fine_amount' => 2500,
    ]);

    expect($violation->exists)->toBeTrue()
        ->and((float) $violation->fresh()->fine_amount)->toBe(2500.00)
        ->and($violation->tenant->is($tenant))->toBeTrue()
        ->and($violation->asset_id)->toBe($this->asset->id);
});

it('defaults status to open so the NOT-NULL column never receives null', function () {
    $tenant = tenantLeasingIn($this->asset);

    // No status supplied — the model $attributes default + DB default both cover it.
    expect(makeViolation($this->asset->id, $tenant->id)->status)->toBe(Violation::STATUS_OPEN);
});

it('allows a violation with no fine (the fine is optional)', function () {
    $tenant = tenantLeasingIn($this->asset);

    expect(makeViolation($this->asset->id, $tenant->id, ['fine_amount' => null])->fine_amount)->toBeNull();
});

it('records the fine WITHOUT billing it — no invoice/charge/invoice-item is created', function () {
    $tenant = tenantLeasingIn($this->asset);

    $invoicesBefore = Invoice::count();
    $chargesBefore = Charge::count();
    $itemsBefore = InvoiceItem::count();

    makeViolation($this->asset->id, $tenant->id, ['fine_amount' => 5000]);

    // FR-REQ-15 records the amount; it must NOT touch the billing/AR/GL path.
    expect(Invoice::count())->toBe($invoicesBefore)
        ->and(Charge::count())->toBe($chargesBefore)
        ->and(InvoiceItem::count())->toBe($itemsBefore);
});

/* ---- FR-REQ-16: RBAC + property scoping (authorized staff only) --------- */

it('gates the register on violations permissions', function () {
    // operations + coordinator record + notice violations.
    $this->actingAs(makeUser('operations'));
    expect(ViolationResource::canViewAny())->toBeTrue()
        ->and(ViolationResource::canCreate())->toBeTrue();

    $this->actingAs(makeUser('coordinator'));
    expect(ViolationResource::canViewAny())->toBeTrue()
        ->and(ViolationResource::canCreate())->toBeTrue();

    // viewer sees violations (blanket .view) but cannot create them.
    $this->actingAs(makeUser('viewer'));
    expect(ViolationResource::canViewAny())->toBeTrue()
        ->and(ViolationResource::canCreate())->toBeFalse();

    // leasing has no business in the facility/operations layer.
    $this->actingAs(makeUser('leasing'));
    expect(ViolationResource::canViewAny())->toBeFalse()
        ->and(ViolationResource::canCreate())->toBeFalse();
});

it('reserves delete for super_admin only', function () {
    $tenant = tenantLeasingIn($this->asset);
    $violation = makeViolation($this->asset->id, $tenant->id);

    $this->actingAs(makeUser('operations'));
    expect(ViolationResource::canDelete($violation))->toBeFalse();

    $this->actingAs(makeUser('super_admin'));
    expect(ViolationResource::canDelete($violation))->toBeTrue();
});

it('scopes the register to the current property', function () {
    $other = makeAsset(['code' => 'VOB']);
    $here = makeViolation($this->asset->id, tenantLeasingIn($this->asset)->id, ['description' => 'A-side breach']);
    $there = makeViolation($other->id, tenantLeasingIn($other)->id, ['description' => 'B-side breach']);

    $this->actingAs(makeUser('super_admin'));

    asTenant($this->asset, function () use ($here, $there) {
        $ids = scopedResourceQuery(ViolationResource::class)->pluck('id')->all();
        expect($ids)->toContain($here->id)->not->toContain($there->id);
    });
});

it('rejects an out-of-scope asset_id on write', function () {
    // A restricted user (assigned only to their mall) cannot tamper the property
    // Select to file a violation in another mall.
    $other = makeAsset(['code' => 'VOC']);
    $this->actingAs(makeUser('operations', [$this->asset->id]));

    ViolationResource::assertAssetInScope($this->asset->id); // in scope — no throw

    expect(fn () => ViolationResource::assertAssetInScope($other->id))
        ->toThrow(HttpException::class);
});

it('does not offer cross-property tenants to a restricted user in the tenant select', function () {
    // A tenant leasing only in another mall must never appear in the picker for a
    // user restricted to $this->asset (the picker's reach is OptionDisplay::PICKER_SCOPES).
    $mine = tenantLeasingIn($this->asset);

    $otherMall = makeAsset(['code' => 'VOD']);
    $theirs = tenantLeasingIn($otherMall);

    $this->actingAs(makeUser('operations', [$this->asset->id]));

    asTenant($this->asset, function () use ($mine, $theirs) {
        $options = OptionDisplay::options(Tenant::class);
        expect($options)->toHaveKey($mine->id)
            ->and($options)->not->toHaveKey($theirs->id);
    });
});

/* ---- the table renders with rows (FR-REQ-16 view) ----------------------- */

it('renders the violations table with rows', function () {
    $tenant = tenantLeasingIn($this->asset);
    $a = makeViolation($this->asset->id, $tenant->id, ['description' => 'Blocked fire exit', 'fine_amount' => 1500]);
    $b = makeViolation($this->asset->id, $tenant->id, ['description' => 'After-hours noise']);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    Livewire::test(ListViolations::class)
        ->assertCanSeeTableRecords([$a, $b])
        ->assertSee('Blocked fire exit')
        ->assertSee($tenant->name);
});

/* ---- FR-REQ-17: the tenant notice (explicit action) --------------------- */

it('sends the tenant a notice through the real tenant-notify path and stamps notified_at', function () {
    Notification::fake();

    $tenant = tenantLeasingIn($this->asset);
    $violation = makeViolation($this->asset->id, $tenant->id, ['fine_amount' => 2000]);

    expect($violation->notified_at)->toBeNull(); // NOT auto-sent on create

    $this->actingAs(makeUser('operations', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    Livewire::test(ListViolations::class)
        ->mountAction(TestAction::make('sendNotice')->table($violation))
        ->callMountedAction();

    Notification::assertSentTo($tenant, ViolationNoticeNotification::class);
    expect($violation->fresh()->notified_at)->not->toBeNull();
});

it('delivers the violation notice via the bell + push only (no email)', function () {
    $violation = new Violation(['description' => 'x']);
    $via = (new ViolationNoticeNotification($violation))->via(makeTenant());

    expect($via)->toEqualCanonicalizing(['database', 'push']);
});

it('contains a send failure — a violation with no tenant is a safe no-op, never a 500', function () {
    Notification::fake();

    $tenant = tenantLeasingIn($this->asset);
    $violation = makeViolation($this->asset->id, $tenant->id);

    // Remove the recipient: $violation->tenant now resolves to null (soft-deleted).
    trashBypassingDeletionPolicy($tenant);

    $this->actingAs(makeUser('operations', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    // The action must not throw; nothing is sent and notified_at stays null.
    Livewire::test(ListViolations::class)
        ->mountAction(TestAction::make('sendNotice')->table($violation))
        ->callMountedAction();

    Notification::assertNothingSent();
    expect($violation->fresh()->notified_at)->toBeNull();
});

it('blocks the Send-notice action for a role lacking violations.notify (dispatch gate, not just visible())', function () {
    Notification::fake();

    $tenant = tenantLeasingIn($this->asset);
    $violation = makeViolation($this->asset->id, $tenant->id);

    // viewer holds violations.view (can open the list) but NOT violations.notify.
    $this->actingAs(makeUser('viewer', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    expect(ViolationResource::canNotify($violation))->toBeFalse();

    // Dispatch via mountAction (which does NOT pre-assert visibility) — the
    // authorize()/abort_unless gate must still block it. callAction would give a
    // false pass (it asserts visibility first).
    Livewire::test(ListViolations::class)
        ->mountAction(TestAction::make('sendNotice')->table($violation))
        ->callMountedAction();

    Notification::assertNothingSent();
    expect($violation->fresh()->notified_at)->toBeNull();
});
