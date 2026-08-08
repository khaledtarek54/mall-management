<?php

use App\Filament\Admin\Pages\BillingRunPreview;
use App\Models\Charge;
use App\Models\Invoice;
use App\Support\Vat;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * The preview page renders for anyone who may READ invoices; POSTING from it is gated separately
 * on `invoices.create`, because posting mints real receivables and posts them to the GL.
 *
 * The refusal is proved through `$action->call()`, which evaluates the closure and so reaches the
 * `abort_unless` — `mountAction()` refuses a hidden action first and would go green whether or not
 * the gate exists (see FirstEightActionAuthzTest and FilamentActionDispatchContractTest). Every
 * refusal here is paired with an authorised control, because a refusal passes just as happily when
 * the dispatch is a silent no-op.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->asset = makeAsset();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

function billableLeaseIn(\App\Models\Asset $asset): \App\Models\Lease
{
    $lease = makeLease(makeUnit($asset), makeTenant(), [
        'status' => 'active',
        'commencement_date' => now()->subYear()->startOfMonth(),
        'expiry_date' => now()->addYear()->endOfMonth(),
    ]);

    Charge::create([
        'lease_id' => $lease->id,
        'name' => 'Base rent',
        'type' => 'base_rent',
        'amount' => 25000,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'vat_applicable' => false,
        'vat_rate' => Vat::EXEMPT,
        'start_date' => now()->subYear()->startOfMonth(),
        'is_active' => true,
    ]);

    return $lease;
}

it('renders the preview, with the proposed invoice actually on the page', function () {
    $lease = billableLeaseIn($this->asset);

    $this->actingAs(makeUser('viewer', [$this->asset->id]));
    Filament::setTenant($this->asset);

    expect(BillingRunPreview::canAccess())->toBeTrue();

    Livewire::test(BillingRunPreview::class)
        ->assertOk()
        // A page that renders empty would pass assertOk() and tell the operator nothing bills.
        ->assertSee($lease->reference)
        ->assertSee($lease->tenant->name);
});

it('hides the page from a department user with no invoice read permission (hr)', function () {
    $this->actingAs(makeUser('hr', [$this->asset->id]));
    Filament::setTenant($this->asset);

    expect(BillingRunPreview::canAccess())->toBeFalse();
});

it('refuses a read-only viewer POSTING the run, when the action closure is reached directly', function () {
    billableLeaseIn($this->asset);

    // `viewer` holds invoices.view — so the preview renders — but NOT invoices.create.
    $this->actingAs(makeUser('viewer', [$this->asset->id]));
    Filament::setTenant($this->asset);

    $action = Livewire::test(BillingRunPreview::class)->instance()->getAction('post');
    expect($action)->not->toBeNull();

    expect(fn () => $action->call())
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);

    expect(Invoice::count())->toBe(0); // nothing minted, nothing posted to the GL
});

it('control: an authorised user CAN post through exactly the same path', function () {
    // Without this control the refusal above would pass identically if call() never ran the
    // closure at all — which is the failure mode that made a whole authz file meaningless once.
    billableLeaseIn($this->asset);

    $this->actingAs(makeUser('accounting', [$this->asset->id]));
    Filament::setTenant($this->asset);

    Livewire::test(BillingRunPreview::class)->instance()->getAction('post')->call();

    expect(Invoice::count())->toBeGreaterThan(0);
});

it('posts only the property in scope', function () {
    $otherMall = makeAsset();
    billableLeaseIn($this->asset);
    $elsewhere = billableLeaseIn($otherMall);

    $user = makeUser('accounting', [$this->asset->id, $otherMall->id]);
    $this->actingAs($user);
    Filament::setTenant($this->asset);

    Livewire::test(BillingRunPreview::class)->instance()->getAction('post')->call();

    expect(Invoice::count())->toBe(1)
        ->and(Invoice::where('lease_id', $elsewhere->id)->exists())->toBeFalse();
});
