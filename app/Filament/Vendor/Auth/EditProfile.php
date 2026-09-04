<?php

namespace App\Filament\Vendor\Auth;

use App\Models\VendorContact;
use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Validation\Rules\Unique;

/**
 * **The contractor's own profile page** — the one panel where Filament's default email rule
 * disagrees with the domain, and where the disagreement locked people out of their own password.
 *
 * `Filament\Auth\Pages\EditProfile::getEmailFormComponent()`
 * (`vendor/filament/filament/src/Auth/Pages/EditProfile.php:357-366`) builds
 * `TextInput::make('email')->unique(ignoreRecord: true)` against the panel's authenticatable model
 * (`:416` sets `->model($this->getUser())`). Measured 2026-09-04 by building the rule Filament
 * builds: `(string) Rule::unique(VendorContact::class, 'email')->ignore(7, 'id')` is
 * `unique:vendor_contacts,email,"7",id` — the WHOLE table, with no `is_portal_user` clause.
 *
 * That is right on the other two panels and wrong here, and the difference is in the SCHEMA rather
 * than in anybody's opinion. Measured the same day with `Schema::getIndexes()`: `users.email` and
 * `tenant_users.email` each carry a real UNIQUE index; `vendor_contacts.email` carries none, and
 * `2026_08_28_210000_a_contractor_can_sign_in` says why in `up()` — two contractors can legitimately
 * share a switchboard address, so uniqueness is required only among rows that can actually SIGN IN.
 * That partial rule lives on the model ({@see VendorContact::constrainToLogins()}).
 *
 * **It did not merely refuse an email change; it refused the SAVE.** This page writes name, email
 * and password in one submit — `save()` calls `$this->form->getState()`, which validates every
 * field — so a contractor whose address also sits on an ordinary non-login contact row, the
 * ordinary shape of a shared inbox, could never change their own password. Refused on a field they
 * never touched, over an address this system's own rules say is fine.
 *
 * **Why this rebuilds the component instead of chaining onto `parent::`.**
 * `CanBeValidated::unique()` (`vendor/filament/forms/src/Components/Concerns/CanBeValidated.php:563`)
 * registers through `$this->rule(...)`, which APPENDS (`:479`), and nothing can drop a rule already
 * registered. So `parent::getEmailFormComponent()->unique(…scoped…)` leaves the unscoped rule
 * standing beside the scoped one and refuses exactly the same saves while reading as a fix.
 *
 * `modifyRuleUsing`'s closure parameter MUST be named `$rule`: Filament evaluates it by PARAMETER
 * NAME (`$component->evaluate($modifyRuleUsing, ['rule' => $rule])`), the trap CLAUDE.md records for
 * `fn (Builder $q, array $data)`.
 */
class EditProfile extends BaseEditProfile
{
    protected function getEmailFormComponent(): Component
    {
        return TextInput::make('email')
            ->label(__('filament-panels::auth/pages/edit-profile.form.email.label'))
            ->email()
            ->required()
            ->maxLength(255)
            // Narrowed to the rows that can sign in — read FROM the model, so this form and
            // `VendorContact::saving` cannot answer differently about one address.
            ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule): Unique => $rule->where(
                fn (Builder $query) => VendorContact::constrainToLogins($query),
            ))
            // The domain's sentence, written for the person reading it: a contractor cannot
            // withdraw somebody else's portal access, so the escape it names is one they can take.
            ->validationMessages([
                'unique' => __('vendor.profile.email_taken'),
            ])
            ->live(debounce: 500);
    }
}
