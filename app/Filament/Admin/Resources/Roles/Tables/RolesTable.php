<?php

namespace App\Filament\Admin\Resources\Roles\Tables;

use App\Filament\Admin\Resources\Roles\RoleResource;
use App\Support\AccessControlAudit;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.fields.role_name'))
                    ->weight('bold')
                    ->searchable(),
                TextColumn::make('description')
                    ->label(__('admin.fields.role_description'))
                    ->state(fn ($record) => RolesPermissionsSeeder::ROLES[$record->name] ?? __('admin.fields.role_custom'))
                    ->color(fn ($record) => isset(RolesPermissionsSeeder::ROLES[$record->name]) ? null : 'info')
                    ->limit(80),
                TextColumn::make('permissions_count')
                    ->label(__('admin.tables.role.permissions'))
                    ->counts('permissions')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('users_count')
                    ->label(__('admin.tables.role.users'))
                    ->counts('users')
                    ->badge()
                    ->color('gray'),
            ])
            // RBAC hygiene: which roles did someone hand-roll, and which are dead weight.
            // Both are questions an access-control review asks and neither was answerable.
            ->filters([
                Filter::make('custom')
                    ->label(__('admin.tables.role.custom_only'))
                    ->query(fn ($query) => $query->whereNotIn('name', array_keys(RolesPermissionsSeeder::ROLES))),
                Filter::make('unassigned')
                    ->label(__('admin.tables.role.unassigned_only'))
                    ->query(fn ($query) => $query->whereDoesntHave('users')),
            ])
            ->emptyStateIcon('heroicon-o-shield-check')
            ->emptyStateHeading(__('admin.empty.roles.heading'))
            ->emptyStateDescription(__('admin.empty.roles.description'))
            // Grouped into one menu — three inline actions pushed the users column and the
            // actions themselves off the right edge of the table.
            ->recordActions([
                ActionGroup::make([
                    // Read the record without opening its edit form — less
                    // friction, and no write surface for view-only roles. The
                    // schema is the resource's own form rendered disabled, so it
                    // cannot drift from the fields that actually exist.
                    ViewAction::make()
                        ->visible(fn ($record) => RoleResource::canView($record))
                        ->authorize(fn ($record) => RoleResource::canView($record)),
                    // Navigate to the Edit PAGE (not a modal): the per-module permission
                    // CheckboxLists are dehydrated(false) and only EditRole::afterSave
                    // gathers + syncs them (and audits the diff). A modal EditAction
                    // would no-op the permission change AND skip the audit.
                    EditAction::make()
                        ->visible(fn ($record) => RoleResource::canEdit($record))
                        ->url(fn ($record): string => RoleResource::getUrl('edit', ['record' => $record])),

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
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () => RoleResource::canDeleteAny()),
                ]),
            ])
            ->defaultSort('name');
    }
}
