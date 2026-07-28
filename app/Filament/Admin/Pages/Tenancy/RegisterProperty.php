<?php

namespace App\Filament\Admin\Pages\Tenancy;

use App\Filament\Admin\Resources\Assets\Schemas\AssetForm;
use App\Models\Asset;
use Filament\Actions\Action;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

/**
 * What a user sees when they have no property to work in.
 *
 * Filament routes anyone with zero accessible tenants here, and this is a **privilege boundary**,
 * not an onboarding convenience. Filament's own `canView()` asks `authorize('create', Asset::class)`
 * and — with no policy registered — Filament's authorize() helper defaults to ALLOWED. The result:
 * a read-only `viewer` (the auditor role), a `technician`, even an external `vendor` login, was
 * shown a working "Create your first property" form, and `handleRegistration()` then attached them
 * to the new mall with the pivot role `manager`. Eleven of fourteen roles could mint themselves a
 * property they then administered. Only super_admin, manager and mall_admin hold `assets.create`.
 *
 * So this page now does two different jobs depending on who is looking:
 *
 *  - **Can create** → the property form, as before.
 *  - **Cannot create** → an explanation and nothing to submit. Which is the other half of the
 *    problem: without it these users got a bare 404 with no hint that the fix is an assignment
 *    from their administrator.
 *
 * The gate is enforced in `handleRegistration()`, not in the form's visibility — a hidden form is
 * still dispatchable via a crafted Livewire call, the same trap `visible()` is for actions.
 */
class RegisterProperty extends RegisterTenant
{
    /** May the current user actually create a property? */
    public static function canCreateProperty(): bool
    {
        return Auth::user()?->can('assets.create') ?? false;
    }

    /**
     * Everyone with panel access may LAND here — that is what makes the explanation reachable.
     * Creation itself is gated in handleRegistration().
     */
    public static function canView(): bool
    {
        return Auth::check();
    }

    public static function getLabel(): string
    {
        return __('admin.tenancy.register_label');
    }

    public function getHeading(): string
    {
        return static::canCreateProperty()
            ? __('admin.tenancy.register_heading')
            : __('admin.tenancy.no_property_heading');
    }

    public function getSubheading(): ?string
    {
        return static::canCreateProperty()
            ? __('admin.tenancy.register_subheading')
            : __('admin.tenancy.no_property_subheading');
    }

    public function form(Schema $schema): Schema
    {
        if (! static::canCreateProperty()) {
            // No fields at all — not a disabled form. There is nothing here for this user to fill
            // in, and a greyed-out property form would read as "ask someone to unlock this",
            // which is not what needs to happen.
            return $schema->components([
                Text::make(__('admin.tenancy.no_property_body')),
            ]);
        }

        return AssetForm::configure($schema);
    }

    /**
     * @return array<Action>
     */
    protected function getFormActions(): array
    {
        return static::canCreateProperty() ? parent::getFormActions() : [];
    }

    protected function handleRegistration(array $data): Asset
    {
        // THE gate. `getFormActions()` above only removes the button.
        abort_unless(static::canCreateProperty(), 403);

        $asset = Asset::create($data);

        if ($user = Auth::user()) {
            $user->assignedAssets()->syncWithoutDetaching([
                $asset->id => [
                    'role' => 'manager',
                    'assigned_at' => now(),
                ],
            ]);
        }

        return $asset;
    }
}
