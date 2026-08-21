<?php

namespace App\Support\Filament;

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
