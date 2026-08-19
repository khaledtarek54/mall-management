<?php

/*
|--------------------------------------------------------------------------
| Withholding a mall from the shopper feed meant breaking it (2026-08-19)
|--------------------------------------------------------------------------
| `GET /api/v1/public/malls` returned every ACTIVE property, so publication and operation were the
| same flag. The only way to keep a mall out of the visitor app was to deactivate it — which is not
| a publishing decision, it is an operational kill switch that empties the property switcher, hides
| the units and stops the billing screens.
|
| They are not the same question. A mall under fit-out is fully operational and emphatically not
| something to advertise; a decommissioned one may need to stay visible while its last leases run
| out.
|
| The internal precedent was already there and one level down: a STORE can be withheld
| (`tenants.is_listed`), and module 36 §9.5 deliberately stops a chain being mapped across the
| portfolio from a public URL.
|
| **Defaults to LISTED, by the operator's decision.** Nothing changes on deploy. The risk accepted
| is that a property nobody meant to publish stays published until somebody unticks it — which is
| why the form carries helper text saying what the switch publishes.
*/

use App\Models\Asset;

it('lists a mall that is active and listed', function () {
    $asset = makeAsset(['code' => 'LISTED1', 'name' => 'Atriom Walk', 'is_active' => true]);

    $this->getJson('/api/v1/public/malls')
        ->assertOk()
        ->assertJsonFragment(['code' => $asset->code]);
});

it('defaults a new property to listed, so nothing changes on deploy', function () {
    $asset = makeAsset(['code' => 'DEFAULT1']);

    expect($asset->fresh()->is_publicly_listed)->toBeTrue();
});

it('drops an unlisted mall from the public list', function () {
    $listed = makeAsset(['code' => 'SHOWN1', 'is_active' => true]);
    $hidden = makeAsset(['code' => 'HIDDEN1', 'is_active' => true]);
    $hidden->forceFill(['is_publicly_listed' => false])->save();

    $response = $this->getJson('/api/v1/public/malls')->assertOk();

    // Paired, not asserted alone: a filter that returned nothing at all would satisfy the refusal
    // just as well as a correct one.
    $response->assertJsonFragment(['code' => $listed->code]);
    $response->assertJsonMissing(['code' => $hidden->code]);
});

/**
 * The gate that actually matters. Filtering the LIST is presentation — a mall code is short and
 * guessable (`ATRIOM`, `PLAZA`), so a property withheld from the menu whose stores and posts still
 * resolved by code would be exactly the kind of "hidden" URL that turns out not to be.
 */
it('404s every public route for an unlisted mall, not just the menu', function () {
    $hidden = makeAsset(['code' => 'HIDDEN2', 'is_active' => true]);
    $hidden->forceFill(['is_publicly_listed' => false])->save();

    $this->getJson("/api/v1/public/malls/{$hidden->code}/stores")->assertNotFound();
    $this->getJson("/api/v1/public/malls/{$hidden->code}/posts")->assertNotFound();
});

/** The control for the refusal above: the same routes must work for a listed mall. */
it('still serves those routes for a listed mall', function () {
    $shown = makeAsset(['code' => 'SHOWN2', 'is_active' => true]);

    $this->getJson("/api/v1/public/malls/{$shown->code}/stores")->assertOk();
    $this->getJson("/api/v1/public/malls/{$shown->code}/posts")->assertOk();
});

/**
 * Unlisting is a PUBLISHING decision and must not touch operations — that separation is the whole
 * reason the column exists, and it would be quietly undone by anything that read the new flag as
 * an activity flag.
 */
it('leaves an unlisted mall fully operational', function () {
    $hidden = makeAsset(['code' => 'HIDDEN3', 'is_active' => true]);
    $hidden->forceFill(['is_publicly_listed' => false])->save();

    expect($hidden->fresh()->is_active)->toBeTrue()
        ->and(Asset::query()->where('is_active', true)->pluck('code'))->toContain('HIDDEN3');
});
