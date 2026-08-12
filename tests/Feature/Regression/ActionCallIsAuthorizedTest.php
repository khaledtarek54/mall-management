<?php

use App\Support\Filament\AuthorizedAction;
use Filament\Actions\Action;

/**
 * `->authorize()` was a restatement of `visible()`, not a second layer.
 *
 * Verified in Filament v4.11.8's own source: `CanBeHidden::isHiddenInGroup()` ends
 * `! $this->isAuthorizedOrNotHiddenWhenUnauthorized()` and `CanBeDisabled::isDisabled()` ends
 * `! $this->isAuthorized()`, so authorization folds into hidden and hidden folds into disabled —
 * one mechanism wearing three names. And **`Action::call()` checked nothing at all**.
 *
 * So the only genuinely independent gate was an `abort_unless` inside the action closure, and 76
 * write actions — journal-entry post and void, void_invoice, period close — had only
 * `->authorize()`. No live exploit: `mountAction()` refuses a disabled action, so that single layer
 * holds today. What did not exist was the defence in depth the codebase documents, and a single
 * upstream change to how hidden relates to disabled would have reopened all 76 at once, silently.
 *
 * `Action::make()` resolves through the container, so one binding puts the check on the call path
 * of every custom action. These tests pin the behaviour AND the binding — the second matters
 * because a Filament release that switches `make()` to `new static` would remove the layer without
 * changing a line of our code.
 */
it('refuses to run an action the user is not authorized for', function () {
    // The whole point. Before this, call() evaluated the body regardless.
    $ran = false;

    $action = Action::make('post_journal_entry')
        ->authorize(fn (): bool => false)
        ->action(function () use (&$ran) {
            $ran = true;
        });

    expect(fn () => $action->call())
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);

    expect($ran)->toBeFalse('The action body ran despite authorization being denied.');
});

it('runs an action the user IS authorized for — the paired control', function () {
    // A refusal test passes just as happily when the dispatch is a no-op.
    $ran = false;

    Action::make('post_journal_entry')
        ->authorize(fn (): bool => true)
        ->action(function () use (&$ran) {
            $ran = true;
        })
        ->call();

    expect($ran)->toBeTrue();
});

it('leaves an action that declares no authorize() alone', function () {
    // `isAuthorized()` falls back to true when nothing was declared, so the ~500 actions that gate
    // with an in-closure `abort_unless` — or genuinely need no gate — are untouched. Getting this
    // wrong would have broken every one of them.
    $ran = false;

    Action::make('harmless')->action(function () use (&$ran) {
        $ran = true;
    })->call();

    expect($ran)->toBeTrue();
});

it('keeps the closure gate working when both layers are present', function () {
    // Defence in depth means BOTH, and the inner one must still be reached and still win. An
    // action authorized at the Filament layer can refuse for a reason only the body knows.
    $action = Action::make('void_invoice')
        ->authorize(fn (): bool => true)
        ->action(function (): void {
            abort_unless(false, 403);
        });

    expect(fn () => $action->call())
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
});

it('still resolves every Filament action through the guarded subclass', function () {
    // The binding IS the mechanism. `Action::make()` calls `app(static::class, …)`; if a Filament
    // release changes that to `new static`, the layer disappears with no diff in our code — so the
    // seam is asserted rather than assumed.
    expect(Action::make('anything'))->toBeInstanceOf(AuthorizedAction::class);
});

it('does not swallow what the action itself throws', function () {
    // A DomainException is a refusal that renders as a toast; turning it into a 403 here would make
    // every business-rule refusal look like a permissions problem.
    $action = Action::make('cancel_invoice')
        ->authorize(fn (): bool => true)
        ->action(function (): void {
            throw new DomainException('Already cancelled.');
        });

    expect(fn () => $action->call())->toThrow(DomainException::class, 'Already cancelled.');
});
