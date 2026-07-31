<?php

/*
|--------------------------------------------------------------------------
| What Filament actually does with a hidden action, pinned
|--------------------------------------------------------------------------
| This project carried an invariant, in CLAUDE.md and in half a dozen module docs:
|
|   "Filament's mountAction() checks isDisabled() and NEVER isVisible(), so a hidden action is
|    still dispatchable by a crafted Livewire call."
|
| Half of that is right and the conclusion is wrong on the version we ship. `mountAction()` does
| check `isDisabled()` — and `CanBeDisabled::isDisabled()` returns true when `isHidden()` does
| (vendor/filament/actions/src/Concerns/CanBeDisabled.php:24). So a `visible()`-only action IS
| refused at dispatch on Filament v4.11.8, and the "still exploitable" half of the invariant does
| not hold here.
|
| That was found by mutation-testing the module-08 fix: removing the `abort_unless` from CAM's
| generateAllocations left CamActionAuthzTest fully green, because the block was coming from
| visible() all along. Several close-out records cite those mountAction tests as proof a CRITICAL
| hole was closed; they never exercised the gate they describe.
|
| WHY THIS FILE EXISTS RATHER THAN A DOC EDIT. The behaviour is an upstream implementation detail
| — `isDisabled()` consulting `isHidden()` — not a documented guarantee. Relying on it silently is
| how the original claim went stale in the first place. If an upgrade decouples them, a
| `visible()`-only write becomes dispatchable across the whole admin panel at once, and the only
| warning would be this test going red.
|
| None of which makes double-gating wrong: `->authorize()` is a stated intent that does not depend
| on an upstream detail, and it is what ActionAuthzConformanceTest enforces. This file pins the
| second layer so we know which layer is actually holding.
*/

use Filament\Actions\Action;

it('treats a hidden action as disabled', function () {
    // The load-bearing link. mountAction() refuses disabled actions, so this is what stops a
    // crafted dispatch of a visible()-only action today.
    $hidden = Action::make('hidden')->visible(fn (): bool => false)->action(fn () => null);
    $shown = Action::make('shown')->visible(fn (): bool => true)->action(fn () => null);

    expect($hidden->isHidden())->toBeTrue()
        ->and($hidden->isDisabled())->toBeTrue(
            'Filament no longer disables hidden actions — a visible()-only write is now dispatchable '
            .'panel-wide. Every action gated only in visible() must move its check into '
            .'authorize()/abort_unless immediately; see ActionAuthzConformanceTest.'
        )
        ->and($shown->isDisabled())->toBeFalse();
});

it('treats an unauthorized action as disabled', function () {
    // The layer we control, and the one the project's own rule asks for.
    $denied = Action::make('denied')->authorize(fn (): bool => false)->action(fn () => null);
    $allowed = Action::make('allowed')->authorize(fn (): bool => true)->action(fn () => null);

    expect($denied->isDisabled())->toBeTrue()
        ->and($allowed->isDisabled())->toBeFalse();
});

it('still refuses the dispatch when hidden and authorized', function () {
    // Order matters inside isDisabled(): the isHidden() check comes BEFORE the authorization
    // branch, so an action that is authorized but hidden is still refused. If that order flips,
    // hiding stops being a backstop.
    $action = Action::make('hiddenButAuthorized')
        ->visible(fn (): bool => false)
        ->authorize(fn (): bool => true)
        ->action(fn () => null);

    expect($action->isDisabled())->toBeTrue();
});

it('records the Filament version this contract was verified against', function () {
    // So a future reader can tell whether the claim above was checked against what they are
    // running, instead of inheriting it the way the original invariant was inherited.
    expect(Composer\InstalledVersions::getPrettyVersion('filament/actions'))
        ->toStartWith('v4.', 'the dispatch contract above was verified against Filament v4.11.8 — re-verify on a major upgrade');
});
