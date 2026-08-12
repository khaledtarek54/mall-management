<?php

namespace App\Support\Filament;

use Filament\Actions\Action;

/**
 * Makes `->authorize()` a real second layer instead of a restatement of `visible()`.
 *
 * **The problem, verified in Filament v4.11.8's own source.** `visible()` and `->authorize()` are
 * the SAME layer, not two:
 *
 * - `CanBeHidden::isHiddenInGroup()` ends `return ! $this->isAuthorizedOrNotHiddenWhenUnauthorized()`
 *   — authorization folds into hidden.
 * - `CanBeDisabled::isDisabled()` returns true when `isHidden()` does, and ends
 *   `return ! $this->isAuthorized()` — authorization folds into disabled.
 * - **`Action::call()` performs no authorization check at all** — it evaluates the action function
 *   and nothing else.
 *
 * So the only genuinely independent second layer was an `abort_unless` written inside the action
 * closure, and 76 write actions — journal-entry post and void, void_invoice, period close — carried
 * `->authorize()` and no closure gate. There is no live exploit: `mountAction()` refuses a disabled
 * action, so today that single layer holds. The defect is that the *defence in depth* the codebase
 * documents did not exist, and one upstream change to how hidden relates to disabled would reopen
 * all 76 at once, silently.
 *
 * **The fix is one seam rather than 76 edits.** `Action::make()` resolves through the container
 * (`app(static::class, …)`), so binding `Filament\Actions\Action` to this subclass puts an
 * authorization check on the call path of every custom action in the app. `isAuthorized()` returns
 * true when no `->authorize()` was set, so an action that gates itself with `abort_unless` — or
 * genuinely needs no gate — is unaffected.
 *
 * `ActionAuthzConformanceTest` still requires every write action to declare *a* gate; this makes
 * whichever one it declared actually run at dispatch. `FilamentActionDispatchContractTest` pins the
 * upstream behaviour, and `ActionCallIsAuthorizedTest` pins this one — including that the binding is
 * still in force, so a Filament release that switches `make()` to `new static` turns the build red
 * instead of quietly removing the layer.
 */
class AuthorizedAction extends Action
{
    /**
     * @param  array<string, mixed>  $parameters
     */
    public function call(array $parameters = []): mixed
    {
        // 403 rather than a refusal toast: reaching here means a payload was dispatched for an
        // action this user may not run, which is not an operator mistake to explain.
        abort_unless($this->isAuthorized(), 403);

        return parent::call($parameters);
    }
}
