<?php

use App\Filament\Admin\Resources\Holidays\HolidayResource;
use App\Filament\Admin\Resources\Holidays\Pages\ListHolidays;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\Holiday;
use App\Models\User;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * **"Delete = super_admin only" was a claim nothing enforced for Filament's OWN delete button.**
 *
 * `RoleGatedActions::canDelete()` says so, `App\Support\DeletionPolicy` is built on it, and
 * `DeletionPolicyConformanceTest` gates that no forbidden Delete button or permission reappears. All
 * of that was true and none of it was the gate anyone assumed.
 *
 * In Filament v4, `Resources\Pages\Page::getDefaultActionAuthorizationResponse()` routes the built-in
 * `Create`/`Edit`/`View`/`Delete`/`ForceDelete`/`Restore` actions to a Laravel POLICY. This
 * application has no policies — no `app/Policies`, no `Gate::policy()`, no `Gate::before()` — so
 * `Filament\get_authorization_response()` falls through to `Response::allow()`.
 *
 * `canCreate()` and `canEdit()` survived that by accident: `CreateRecord::authorizeAccess()` and
 * `EditRecord::authorizeAccess()` re-check them and `abort(403)` when the PAGE mounts. **A
 * `DeleteAction` has no page**, so it had no second layer at all — and roughly thirty call sites
 * across the panel carry a bare `DeleteAction::make()`. A plain `manager` could delete an
 * unreferenced tenant, unit, lease, asset, holiday, rent index — or a USER ACCOUNT.
 *
 * The fix is one seam, not thirty files: `DeleteAction::make()` already resolves to
 * `AnnouncingDeleteAction` through the container, exactly like `Action::make()` resolves to
 * `AuthorizedAction`, so the check goes there. Same reasoning as CLAUDE.md's `visible()`-is-not-a-gate
 * entry: the fortieth call site is covered before anyone remembers it.
 *
 * These tests assert the ROW, not the toast. `->callAction()` on an ungated delete goes green either
 * way — what cannot false-pass is whether the record is still there.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'DL']);
});

it('refuses a manager the delete button on a table row, and the row survives', function () {
    $holiday = Holiday::create([
        'date' => '2026-12-25',
        'name_en' => 'Christmas',
        'name_ar' => 'عيد الميلاد',
        'kind' => 'closure',
    ]);

    $this->actingAs(makeUser('manager', [$this->asset->id]));

    expect(HolidayResource::canDelete($holiday))->toBeFalse();

    asTenant($this->asset, function () use ($holiday) {
        // The UI layer: the button is not there. Filament's own test helper refuses to call a
        // hidden action, which is why this asserts visibility rather than calling and hoping — see
        // CLAUDE.md on `->callAction()` being a false pass in both directions.
        Livewire::test(ListHolidays::class)->assertTableActionHidden('delete', $holiday);
    });

    // The hard layer, dispatched directly past the UI: a crafted payload must 403, not delete.
    expect(fn () => DeleteAction::make('delete')->record($holiday)->call())
        ->toThrow(HttpException::class);

    expect(Holiday::find($holiday->id))->not->toBeNull('A manager deleted a holiday the policy reserves for super_admin.');
});

it('refuses a manager the delete button on an edit page, and the user account survives', function () {
    $victim = makeUser('viewer', [$this->asset->id]);

    $this->actingAs(makeUser('manager', [$this->asset->id]));

    expect(UserResource::canDelete($victim))->toBeFalse();

    asTenant($this->asset, function () use ($victim) {
        Livewire::test(EditUser::class, ['record' => $victim->getKey()])->assertActionHidden('delete');
    });

    expect(fn () => DeleteAction::make('delete')->record($victim)->call())
        ->toThrow(HttpException::class);

    expect(User::find($victim->id))->not->toBeNull('A manager deleted another user account.');
});

it('still lets a super_admin delete, so the guard refuses the right people', function () {
    // The control. A gate that refused everybody would satisfy both refusals above and would break
    // the one deletion path the project deliberately keeps.
    $holiday = Holiday::create([
        'date' => '2026-12-26',
        'name_en' => 'Boxing Day',
        'name_ar' => 'يوم الصناديق',
        'kind' => 'closure',
    ]);

    $this->actingAs(makeUser('super_admin', [$this->asset->id]));

    expect(HolidayResource::canDelete($holiday))->toBeTrue();

    asTenant($this->asset, function () use ($holiday) {
        Livewire::test(ListHolidays::class)
            ->assertTableActionVisible('delete', $holiday)
            ->callTableAction('delete', $holiday);
    });

    expect(Holiday::find($holiday->id))->toBeNull('A super_admin could not delete a holiday.');
});

it('hides the edit button from a role that holds view and not edit', function () {
    // The other half of the same defect. `ViewAction` was added to seven catalogue tables "for the
    // role that holds `.view` and not `.edit`" — and that role was still shown Edit, because
    // Filament routes the built-in Edit action to a policy too. 38 tables gated it by hand and 19
    // did not; the seam is the answer, not a 20th edit.
    $holiday = Holiday::create([
        'date' => '2026-12-27',
        'name_en' => 'Reading day',
        'name_ar' => 'يوم القراءة',
        'kind' => 'closure',
    ]);

    $this->actingAs(makeUser('viewer', [$this->asset->id]));

    expect(HolidayResource::canEdit($holiday))->toBeFalse();

    asTenant($this->asset, function () use ($holiday) {
        Livewire::test(ListHolidays::class)
            ->assertTableActionHidden('edit', $holiday)
            // …and the read-only view IS offered, which is the whole point of adding it.
            ->assertTableActionVisible('view', $holiday);
    });
});

it('still shows the edit button to a manager', function () {
    // The control. A gate that hid Edit from everyone would satisfy the case above and break the
    // panel.
    $holiday = Holiday::create([
        'date' => '2026-12-28',
        'name_en' => 'Another day',
        'name_ar' => 'يوم آخر',
        'kind' => 'closure',
    ]);

    $this->actingAs(makeUser('manager', [$this->asset->id]));

    expect(HolidayResource::canEdit($holiday))->toBeTrue();

    asTenant($this->asset, function () use ($holiday) {
        Livewire::test(ListHolidays::class)->assertTableActionVisible('edit', $holiday);
    });
});
