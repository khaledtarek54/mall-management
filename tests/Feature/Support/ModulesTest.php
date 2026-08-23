<?php

use App\Settings\ModulesSettings;
use App\Support\Modules;

/*
|--------------------------------------------------------------------------
| Modules feature-flag helper
|--------------------------------------------------------------------------
| App\Support\Modules::enabled() is the single gate every optional module
| asks "am I turned on?". It reads the boolean off ModulesSettings (a Spatie
| settings class, resolved as a per-request singleton from the container).
*/

it('reflects the configured ModulesSettings value for a toggleable key', function () {
    $settings = app(ModulesSettings::class);

    $settings->credit_notes = true;
    $settings->save();
    expect(Modules::enabled('credit_notes'))->toBeTrue();

    $settings->credit_notes = false;
    $settings->save();
    expect(Modules::enabled('credit_notes'))->toBeFalse();
});

it('returns false for a disabled module', function () {
    $settings = app(ModulesSettings::class);
    $settings->vendors = false;
    $settings->save();

    expect(Modules::enabled('vendors'))->toBeFalse();
});

it('returns true for every TOGGLEABLE module when all flags are on', function () {
    $settings = app(ModulesSettings::class);

    foreach (Modules::KEYS as $key) {
        $settings->{$key} = true;
    }
    $settings->save();

    // `toggleable()`, not `KEYS` — a FROZEN module is deliberately not answerable by a flag, so
    // asserting over the whole registry asserted the pre-freeze world and had been red since.
    expect(Modules::toggleable())->not->toBeEmpty();

    foreach (Modules::toggleable() as $key) {
        expect(Modules::enabled($key))->toBeTrue("{$key} is toggleable and its flag is on.");
    }
});

it('keeps a FROZEN module off even with its flag switched on', function () {
    // The other half, and the one that matters: `Modules::enabled()` answers false BEFORE the
    // settings row is consulted, so a stale row, a restored backup or a hand-edited `settings`
    // table cannot bring an uncertified integration back. The test above used to contradict this
    // by asserting every key goes true; this asserts the freeze instead of working around it.
    expect(Modules::FROZEN)->not->toBeEmpty('Nothing is frozen — this case would pass vacuously.');

    $settings = app(ModulesSettings::class);

    foreach (array_keys(Modules::FROZEN) as $key) {
        $settings->{$key} = true;
    }
    $settings->save();

    foreach (array_keys(Modules::FROZEN) as $key) {
        expect(Modules::enabled($key))->toBeFalse("{$key} is frozen; a settings row must not revive it.");
        expect(Modules::frozen($key))->toBeTrue();
    }
});

it('returns false for every optional module when all flags are off', function () {
    $settings = app(ModulesSettings::class);

    foreach (Modules::KEYS as $key) {
        $settings->{$key} = false;
    }
    $settings->save();

    foreach (Modules::KEYS as $key) {
        expect(Modules::enabled($key))->toBeFalse();
    }
});

it('treats unknown / core keys as always-on (not driven by settings)', function () {
    // Core surfaces (Properties, Units, Tenants, Leases, Invoices, ...) and
    // any typo'd key are not in KEYS, so enabled() short-circuits to true and
    // never touches ModulesSettings.
    expect(Modules::enabled('invoices'))->toBeTrue()
        ->and(Modules::enabled('leases'))->toBeTrue()
        ->and(Modules::enabled('totally_unknown_module'))->toBeTrue()
        ->and(Modules::KEYS)->not->toContain('invoices')
        ->and(Modules::KEYS)->not->toContain('leases');
});

it('isolates module toggles from one another', function () {
    $settings = app(ModulesSettings::class);
    $settings->requests = false;
    $settings->cam = true;
    $settings->save();

    expect(Modules::enabled('requests'))->toBeFalse()
        ->and(Modules::enabled('cam'))->toBeTrue();
});

it('caches ModulesSettings as a per-request singleton', function () {
    // Spatie registers each settings class as a container singleton, which is
    // the "cached per-request" behaviour the Modules docblock relies on: two
    // resolves hand back the very same object.
    $first = app(ModulesSettings::class);
    $second = app(ModulesSettings::class);

    expect($first)->toBe($second);

    // Because enabled() reads off that same cached instance, mutating it in
    // memory (no save()) is already visible to the helper — proving it does
    // not re-read storage on every call.
    $first->notes = false;
    expect(Modules::enabled('notes'))->toBeFalse();

    $first->notes = true;
    expect(Modules::enabled('notes'))->toBeTrue();
});
