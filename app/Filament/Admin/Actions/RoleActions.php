<?php

namespace App\Filament\Admin\Actions;

use App\Filament\Admin\Resources\Roles\RoleResource;
use App\Support\AccessControlAudit;
use App\Support\RowActionPolicy;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * **Everything you can DO to a role, defined once.**
 *
 * `clone` lived inline in `RolesTable`,
 * so the act was reachable from the LIST and the record's
 * own page carried Delete and little else — backwards from the record-hub architecture this
 * project took from Yardi: **the list finds, the record acts**. Defined here, composed onto the
 * record page, so the two surfaces can never drift.
 *
 * Safe to move, and measured rather than assumed: every role that can perform this act can open
 * the page it moved to. Four resources failed that check — an act held by a role that
 * deliberately lacks `{module}.edit` — and kept their verbs on the row; see
 * {@see RowActionPolicy}.
 */
class RoleActions
{
    /**
     * @return array<int, Action>
     */
    public static function all(): array
    {
        return [
            // Start a new role from an existing one. Building "accounting, but without the
            // credit-note rights" meant ticking ~200 boxes across 40 collapsed sections and
            // getting none of them wrong — so in practice nobody made a custom role, they
            // over-granted an existing one. Cloning makes the narrow role the easy path.
            Action::make('clone')
                ->label(__('admin.roles.actions.clone'))
                ->icon('heroicon-o-document-duplicate')
                ->color('gray')
                ->visible(fn () => RoleResource::canCreate())
                ->authorize(fn () => RoleResource::canCreate())
                ->schema([
                    TextInput::make('name')
                        ->label(__('admin.fields.role_name'))
                        ->required()
                        ->maxLength(125)
                        // Same shape as RoleForm — a role name is a permission-system key,
                        // not a display label.
                        ->regex('/^[a-z0-9_]+$/')
                        ->helperText(__('admin.fields.role_name_helper'))
                        ->unique(table: 'roles', column: 'name')
                        ->default(fn (Role $record) => Str::of($record->name)->append('_copy')->value()),
                ])
                ->action(function (Role $record, array $data): void {
                    abort_unless(RoleResource::canCreate(), 403);

                    // Re-resolved through the Eloquent query rather than used as returned:
                    // Role::create() gives back Spatie's CONTRACT, which the audit sink
                    // (rightly) will not accept as a subject to log against.
                    Role::create([
                        'name' => $data['name'],
                        'guard_name' => $record->guard_name,
                    ]);

                    /** @var Role $clone Spatie types its statics as the CONTRACT; this is the model. */
                    $clone = Role::query()->where('name', $data['name'])
                        ->where('guard_name', $record->guard_name)
                        ->firstOrFail();

                    $permissions = $record->permissions()->pluck('name')->all();
                    $clone->syncPermissions($permissions);
                    app(PermissionRegistrar::class)->forgetCachedPermissions();

                    // A new role carrying someone else's whole permission set is a privilege
                    // event, so it goes in the same trail as an edit — otherwise the audit
                    // shows a role appearing from nowhere fully armed.
                    AccessControlAudit::log($clone, 'permission_granted', $permissions);

                    Notification::make()
                        ->title(__('admin.roles.notices.cloned', ['name' => $clone->name]))
                        ->body(__('admin.roles.notices.cloned_body', ['count' => count($permissions)]))
                        ->success()
                        ->send();
                }),
        ];
    }
}
