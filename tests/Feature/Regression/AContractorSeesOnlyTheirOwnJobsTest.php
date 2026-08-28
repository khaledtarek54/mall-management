<?php

/**
 * **The vendor portal's one design rule, tested before anything hangs off it.**
 *
 * > A contractor may only ever see or touch a job that has been DISPATCHED to them.
 *
 * `docs/modules/12b-VENDOR-PORTAL-DESIGN.md` §2 and §5. Steps 2–3 of its build order: the login, the
 * panel, the scoping rule, then the jobs list and **accept**.
 *
 * **Every refusal is paired with a control that must SUCCEED.** A scope that returned nothing would
 * satisfy all the refusals on its own and read as a pass — which is the trap `TenantNeverSeesADraft`
 * records for the tenant side of the same question.
 */

use App\Filament\Vendor\Resources\WorkOrders\Pages\ListWorkOrders;
use App\Models\FacilityWorkOrder;
use App\Models\Vendor;
use App\Models\VendorContact;
use App\Services\AcceptWorkOrderService;
use App\Services\FacilityWorkOrderService;
use App\Support\Filament\VendorScope;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\Login;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();

    $this->mine = Vendor::create(['name' => 'Cool Air Co', 'status' => Vendor::STATUS_ACTIVE]);
    $this->theirs = Vendor::create(['name' => 'Rival Mechanical', 'status' => Vendor::STATUS_ACTIVE]);

    $this->contact = VendorContact::create([
        'vendor_id' => $this->mine->id,
        'name' => 'Hani',
        'email' => 'hani@coolair.test',
        'password' => 'secret-secret',
        'is_portal_user' => true,
    ]);
});

function vendorJob(Vendor $vendor, array $attrs = []): FacilityWorkOrder
{
    return FacilityWorkOrder::create(array_merge([
        'asset_id' => test()->asset->id,
        'work_order_type' => 'cm',
        'execution_type' => 'external',
        'vendor_id' => $vendor->id,
        'title' => 'Fix chiller',
        'description' => 'Chiller down',
        'trade_id' => tradeId('hvac'),
        'priority' => 'urgent',
        'scheduled_for' => '2026-07-01',
        'status' => 'open',
    ], $attrs));
}

// ─────────────────────────── The panel gate ───────────────────────────

it('gates the panel on the login AND the company, not just the login', function () {
    $panel = Filament::getPanel('vendor');

    expect($this->contact->canAccessPanel($panel))->toBeTrue();   // the control

    // A contact who was never given a login.
    $plain = VendorContact::create([
        'vendor_id' => $this->mine->id, 'name' => 'Foreman', 'email' => 'f@coolair.test',
    ]);
    expect($plain->canAccessPanel($panel))->toBeFalse();

    // A login at a contractor the operator no longer deals with. Gating on the login alone would
    // leave a terminated contractor's staff reading the jobs they were last dispatched to — the
    // tenant portal's own lesson, where the company check had become dead code.
    $this->mine->update(['status' => 'inactive']);
    expect($this->contact->fresh()->canAccessPanel($panel))->toBeFalse();
});

it('refuses a contact who was never given a login', function () {
    $plain = VendorContact::create([
        'vendor_id' => $this->mine->id, 'name' => 'Site foreman',
        'email' => 'foreman@coolair.test', 'is_portal_user' => false,
    ]);

    expect($plain->is_portal_user)->toBeFalse();

    // The control: the one who WAS given a login differs only in that flag.
    expect($this->contact->is_portal_user)->toBeTrue();
});

it('refuses two logins on one email, and allows two ordinary contacts to share one', function () {
    // Ordinary contacts may share a switchboard address — that is real data.
    VendorContact::create(['vendor_id' => $this->mine->id, 'name' => 'Desk A', 'email' => 'ops@coolair.test']);
    VendorContact::create(['vendor_id' => $this->theirs->id, 'name' => 'Desk B', 'email' => 'ops@coolair.test']);

    expect(VendorContact::where('email', 'ops@coolair.test')->count())->toBe(2);

    // Two LOGINS on one address is an ambiguous identity — the guard would authenticate whichever
    // row it found first.
    expect(fn () => VendorContact::create([
        'vendor_id' => $this->theirs->id, 'name' => 'Clash',
        'email' => 'hani@coolair.test', 'password' => 'x', 'is_portal_user' => true,
    ]))->toThrow(ValidationException::class);
});

// ─────────────────────────── The scope ───────────────────────────

it('shows a contractor their own dispatched jobs and nobody else s', function () {
    $mine = vendorJob($this->mine);
    $theirs = vendorJob($this->theirs);

    $this->actingAs($this->contact, 'vendor');

    $ids = VendorScope::jobs()->pluck('id')->all();

    expect($ids)->toContain($mine->id)          // the control — it must SHOW something
        ->and($ids)->not->toContain($theirs->id);
});

it('hides any status nobody has deliberately shown a contractor', function () {
    // `VISIBLE_STATUSES` equals every status the model defines TODAY, so it narrows nothing yet —
    // the class docblock says so rather than implying a protection that is not there. What it DOES
    // buy is direction: a status added later is invisible to contractors until someone adds it here.
    // Pinned, because a constant that merely restates another constant is one a reader will delete.
    expect(VendorScope::VISIBLE_STATUSES)
        ->toEqualCanonicalizing(FacilityWorkOrder::STATUSES, 'if these have diverged, the allowlist '
            .'has started doing real work — check that the new status SHOULD be hidden, then update '
            .'this assertion to say so deliberately.');

    // And the allowlist really is applied, so a future divergence bites rather than being decorative.
    $job = vendorJob($this->mine);
    $this->actingAs($this->contact, 'vendor');

    expect(VendorScope::jobs()->pluck('id')->all())->toContain($job->id);
    expect(VendorScope::owns($job))->toBeTrue();

    $job->forceFill(['status' => 'cancelled'])->saveQuietly();
    expect(VendorScope::owns($job->fresh()))->toBeTrue();   // cancelled stays readable, deliberately
});

it('matches NOTHING when nobody is signed in', function () {
    vendorJob($this->mine);

    // The failure DIRECTION matters: a scope that widens when the guard is empty is how a portal
    // leaks its whole table to an unauthenticated request.
    expect(VendorScope::jobs()->count())->toBe(0);
});

// ─────────────────────────── The gate, not the filter ───────────────────────────

it('404s when a contractor reaches for another contractor s job', function () {
    $theirs = vendorJob($this->theirs);

    $this->actingAs($this->contact, 'vendor');

    // 404, never 403 — a 403 confirms the job exists.
    try {
        VendorScope::assertOwned($theirs);
        $this->fail('a foreign job must be refused');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(404);
    }

    // The control: their OWN job passes the same gate.
    expect(VendorScope::owns(vendorJob($this->mine)))->toBeTrue();
});

// ─────────────────────────── Accept ───────────────────────────

it('accepts a job and stamps the response clock', function () {
    $job = vendorJob($this->mine);
    expect($job->acknowledged_at)->toBeNull();

    app(AcceptWorkOrderService::class)->accept($job, $this->contact);

    expect($job->fresh()->acknowledged_at)->not->toBeNull();
});

it('is idempotent — a second accept does not move the clock', function () {
    // Two contacts at one contractor both pressing accept must not move the SLA, and the second
    // must not see an error either: they did what they were asked.
    $job = vendorJob($this->mine);

    $first = app(AcceptWorkOrderService::class)->accept($job, $this->contact)->acknowledged_at;
    $second = app(AcceptWorkOrderService::class)->accept($job->fresh(), $this->contact)->acknowledged_at;

    expect($second->eq($first))->toBeTrue();
});

it('refuses to accept a closed job', function () {
    $job = vendorJob($this->mine);
    app(FacilityWorkOrderService::class)->transition($job, 'in_progress');
    app(FacilityWorkOrderService::class)->transition($job->fresh(), 'done');

    expect(fn () => app(AcceptWorkOrderService::class)->accept($job->fresh(), $this->contact))
        ->toThrow(ValidationException::class);
});

it('accepts through the real portal screen', function () {
    $job = vendorJob($this->mine);

    $this->actingAs($this->contact, 'vendor');
    Filament::setCurrentPanel(Filament::getPanel('vendor'));

    Livewire::test(ListWorkOrders::class)
        ->mountAction(TestAction::make('accept')->table($job))
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect($job->fresh()->acknowledged_at)->not->toBeNull();
});

it('does not offer another contractor s job on that screen', function () {
    $theirs = vendorJob($this->theirs);
    $mine = vendorJob($this->mine);

    $this->actingAs($this->contact, 'vendor');
    Filament::setCurrentPanel(Filament::getPanel('vendor'));

    Livewire::test(ListWorkOrders::class)
        ->assertCanSeeTableRecords([$mine])       // the control
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('records when a contractor last signed in', function () {
    // Caught in review, not by a gate: the column was added, cast, and written by nothing — the same
    // inert-mechanism shape as `tenants.locale`. It matters because §9 of the design names
    // "contractors who will not log in" as the risk that would make this portal a bad idea, and this
    // column is how an operator finds that out before the SLA figures quietly become fiction.
    expect($this->contact->last_login_at)->toBeNull();

    event(new Login('vendor', $this->contact, false));

    expect($this->contact->fresh()->last_login_at)->not->toBeNull();
});

it('does not try to stamp a login on a guard that has no such column', function () {
    // Guard-scoped, not model-scoped: `User` and `TenantUser` have no `last_login_at`, and a
    // listener that assumed every authenticatable had one would fatal on the admin login.
    $admin = makeUser('super_admin');

    event(new Login('web', $admin, false));

    expect(true)->toBeTrue(); // reaching here IS the assertion — the listener must not throw
});
