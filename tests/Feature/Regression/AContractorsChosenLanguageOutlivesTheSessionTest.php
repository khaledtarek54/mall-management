<?php

/*
|--------------------------------------------------------------------------
| Regression — the contractor portal has a durable language, like the other two
|--------------------------------------------------------------------------
| `/locale/{locale}` writes the choice to the session AND onto the signed-in record, because a
| session answers for the screen in front of you and for nothing that arrives while you are not
| looking at it — a queue worker and a scheduled command have no session at all.
|
| Both loops that make that work were written `['web', 'portal']`, in TWO places, and the vendor
| panel shipped on 2026-08-28 with its own guard (`config/auth.php` → `vendor` →
| `vendor_contacts`). So for the whole life of the contractor portal the switcher rendered in it —
| the render hook is registered panel-wide in `AppServiceProvider` — wrote the session and nothing
| else: a contractor who chose Arabic was back in English at the next sign-in, and every
| notification addressed to them rendered in whatever language raised it.
|
| `vendor_contacts` had no `locale` column either, so adding the guard alone would have been the
| inert half of the fix (and a SQL error on the write). Column, guard list and preference read move
| together, and the list has ONE home so a fifth panel is one edit.
*/

use App\Http\Middleware\SetLocale;
use App\Models\Vendor;
use App\Models\VendorContact;
use Filament\Facades\Filament;
use Filament\Panel;

beforeEach(function () {
    $this->vendor = Vendor::create(['name' => 'Cool Air Co', 'status' => Vendor::STATUS_ACTIVE]);
    $this->contact = VendorContact::create([
        'vendor_id' => $this->vendor->id,
        'name' => 'Hani',
        'email' => 'hani@coolair.test',
        'password' => 'secret-secret',
        'is_portal_user' => true,
    ]);
});

it('writes a contractor language choice onto the contractor, not only into the session', function () {
    expect($this->contact->locale)->toBeNull();

    $this->actingAs($this->contact, 'vendor')->get('/locale/ar')->assertRedirect();

    expect($this->contact->fresh()->locale)->toBe('ar');
});

it('reads that choice back on a later visit with no session to help', function () {
    $this->actingAs($this->contact, 'vendor')->get('/locale/ar');

    // Prove it is the STORED preference answering. Both other tiers are cleared first: the session
    // the switcher just wrote, and the locale this process is left holding.
    $this->flushSession();
    app()->setLocale('en');

    $this->actingAs($this->contact, 'vendor')->get('/')->assertOk();

    expect(app()->getLocale())->toBe('ar');
});

it('knows every guard a person can be signed in on', function () {
    // DERIVED from the panels rather than restated from the constant under test, so a fifth panel
    // turns this red on the day it ships — which is the whole reason the list is written once.
    $guards = collect(Filament::getPanels())
        ->map(fn (Panel $panel): string => $panel->getAuthGuard())
        ->unique()
        ->values()
        ->all();

    expect(count($guards))->toBeGreaterThanOrEqual(3)
        ->and(array_values(array_diff($guards, SetLocale::GUARDS)))->toBe([]);
});

it('still remembers a retailer language, which is where this mechanism started', function () {
    // The control on the guards that already worked. Adding a third must not cost either of them.
    $portalUser = makeTenantUser(makeTenant());

    $this->actingAs($portalUser, 'portal')->get('/locale/ar');

    expect($portalUser->fresh()->locale)->toBe('ar');
});

it('still answers a signed-out visitor from the session alone', function () {
    // Nobody to write to, and that is not an error — it is all an anonymous visitor has.
    $this->get('/locale/ar')->assertRedirect();

    expect(session('locale'))->toBe('ar');
});
