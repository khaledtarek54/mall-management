<?php

namespace App\Support\Filament;

use Illuminate\Auth\Access\Response;

/**
 * Puts the {@see RecordChanged} announcement on an action's call path.
 *
 * `Action::make()` resolves out of the container, so every custom action in the app picks this
 * behaviour up through {@see AuthorizedAction}. Filament's own CRUD actions do NOT: `make()`
 * resolves `static::class`, and `CreateAction` / `EditAction` / `DeleteAction` are subclasses,
 * so they resolve themselves and never see that binding. They are also the actions relation
 * managers are overwhelmingly built from — which is precisely where the announcement matters,
 * because a relation manager is a different Livewire component from the form whose totals its
 * rows derive.
 *
 * Hence three thin subclasses, bound the same way. The alternative was an `->after()` on each
 * relation manager that happens to have a parent showing a derived figure, which is a judgement
 * call repeated sixty times and wrong the first time somebody adds a total to a parent form.
 */
trait AnnouncesRecordChange
{
    /**
     * The second layer, for the CRUD actions that had none.
     *
     * Filament v4 routes `Create`/`Edit`/`View`/`Delete`/`ForceDelete`/`Restore` authorization to a
     * Laravel policy; this application has none, so `get_authorization_response()` returns
     * `Response::allow()`. Create and Edit survive because their PAGES re-check on mount — a
     * `DeleteAction` has no page. A no-op by default so the create/edit subclasses are unchanged;
     * the destructive ones override it.
     */
    protected function assertActionAuthorized(): void {}

    /**
     * Filament's OWN default authorization answer — the one a call-site `->authorize()` silently
     * replaces.
     *
     * `CanBeAuthorized::isAuthorized()` consults `getDefaultActionAuthorizationResponse()` **only
     * when `$this->authorization` is null**. So declaring `->authorize()` on a relation-manager
     * action does not narrow the default, it discards it — and for a relation manager that default
     * begins `$this->isReadOnly() ? Response::deny() : …`, which is TRUE on a `ViewRecord` page
     * (`hasReadOnlyRelationManagersOnResourceViewPagesByDefault` is true and neither panel overrides
     * it). Adding twenty `->authorize()` calls therefore turned write actions live on `ViewTenant`
     * that Filament had been refusing.
     *
     * ANDed back in at the seam rather than repaired at twenty call sites, for the same reason the
     * seam exists at all: the twenty-first would not remember. Composing beats replacing, which is
     * the lesson the previous fix already learned one layer up.
     *
     * **Where that default is the WRONG answer, say so on the relation manager, not here.**
     * "The page is a View page" is a UI inference; this panel has no policies and gates on
     * permissions at the call site. `TenantNotesRelationManager` waives it deliberately and states
     * why — `customer_service` holds `notes.create`, holds no `tenants.edit`, and could otherwise
     * never log the call it just took. That waiver is only safe because each of its actions carries
     * its own `->authorize()`, which is what `RelationManagerCrudIsGatedConformanceTest` enforces.
     */
    protected function defaultAuthorizationAllows(): bool
    {
        $livewire = $this->getHasActionsLivewire();

        if ($livewire === null || ! method_exists($livewire, 'getDefaultActionAuthorizationResponse')) {
            return true;
        }

        return $livewire->getDefaultActionAuthorizationResponse($this)?->allowed() ?? true;
    }

    /**
     * The same answer as {@see isAuthorized()}, because Filament asks the question TWICE.
     *
     * `CanBeAuthorized` implements the two independently: `isAuthorized()` returns a bool and
     * `getAuthorizationResponse()` returns a `Response`, and each of the five subclasses composes
     * only the first. Nothing reaches the second today — `InteractsWithActions::callMountedAction()`
     * consults it only when `hasAuthorizationNotification()`, which defaults to false and no call
     * site in `app/` sets — so this is closing a latent divergence rather than a live hole.
     *
     * It is worth closing anyway, and cheaply: the day someone writes
     * `->authorizationNotification()` to explain a refusal instead of hiding the button, the mount
     * check would read the UNCOMPOSED response and could admit what `isAuthorized()` refuses. Two
     * answers to one question at a security seam is the shape this whole trait exists to remove.
     *
     * A denial from `parent` is returned as-is so its message survives; only the composed refusal
     * needs manufacturing.
     */
    public function getAuthorizationResponse(): Response
    {
        $response = parent::getAuthorizationResponse();

        if ($response->denied() || $this->isAuthorized()) {
            return $response;
        }

        return Response::deny();
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    public function call(array $parameters = []): mixed
    {
        $this->assertActionAuthorized();

        $result = parent::call($parameters);

        RecordChanged::announceAfterAction($this->getLivewire(), $result);

        return $result;
    }
}
