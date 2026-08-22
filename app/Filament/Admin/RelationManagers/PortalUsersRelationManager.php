<?php

namespace App\Filament\Admin\RelationManagers;

use App\Models\TenantUser;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Manage a tenant's portal login accounts (req #9). Only admin tenant users may
 * submit/write in the portal; the rest are read-only. Created here, under the
 * specific tenant, so accounts are always scoped to their company.
 */
class PortalUsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.resources.portal_user.plural');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('admin.fields.name'))
                ->required()
                ->maxLength(150),
            TextInput::make('email')
                ->label(__('admin.fields.email'))
                ->email()
                ->required()
                ->unique(ignoreRecord: true)
                ->maxLength(255),
            TextInput::make('password')
                ->label(__('admin.fields.password'))
                ->password()
                ->revealable()
                ->minLength(8)
                // The TenantUser 'hashed' cast hashes on save; leave blank on
                // edit to keep the current password.
                ->dehydrated(fn ($state) => filled($state))
                ->required(fn (string $operation) => $operation === 'create')
                ->helperText(__('admin.helpers.password_leave_blank'))
                ->maxLength(255),
            Toggle::make('is_admin')
                ->label(__('admin.fields.portal_admin'))
                ->helperText(__('admin.helpers.portal_admin'))
                // Portal admins can write in the portal (submit/pay), so granting
                // that flag is a super_admin-only act — mirrors the Delete gate below.
                // A non-super_admin editing a portal user keeps the current value.
                ->visible(fn () => Auth::user()?->hasRole('super_admin') ?? false)
                ->dehydrated(fn () => Auth::user()?->hasRole('super_admin') ?? false),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('email')
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.fields.name'))
                    ->searchable(),
                TextColumn::make('email')
                    ->label(__('admin.fields.email'))
                    ->searchable()
                    ->copyable(),
                IconColumn::make('is_admin')
                    ->label(__('admin.fields.portal_admin'))
                    ->boolean(),
                TextColumn::make('created_at')
                    ->label(__('admin.activity.when'))
                    ->dateTime('d/m/Y')
                    ->sortable(),
            ])
            ->headerActions([
                // Adding a portal login is granting somebody access to this tenant's data, so it is
                // stated rather than inherited from "can see the tenant". The new user cannot be an
                // admin — that toggle is super_admin-only above — so this creates a read-only login.
                CreateAction::make()
                    ->visible(fn (): bool => Auth::user()?->can('tenants.edit') ?? false)
                    ->authorize(fn (): bool => Auth::user()?->can('tenants.edit') ?? false),
            ])
            ->recordActions([
                // EDITING A PORTAL ADMIN IS ACCOUNT TAKEOVER, and it was open to every role holding
                // `tenants.edit` — `leasing` among them. The form carries a password field, so a
                // manager could reset an existing portal ADMIN's password to a value they chose and
                // sign in to /portal as that tenant, where an admin may pay, submit declarations and
                // read the whole AR. The `is_admin` toggle was already super_admin-only, which
                // stopped them GRANTING the flag and not taking over an account that had it.
                //
                // A read-only portal user is a different matter: impersonating one grants nothing an
                // admin-panel operator cannot already see, and fixing a typo'd email or resetting a
                // forgotten password is ordinary tenant-relations work.
                EditAction::make()
                    ->visible(fn (TenantUser $record): bool => static::mayEditPortalUser($record))
                    ->authorize(fn (TenantUser $record): bool => static::mayEditPortalUser($record)),
                // Delete stays super_admin-only (project-wide convention).
                DeleteAction::make()
                    ->visible(fn () => Auth::user()?->hasRole('super_admin') ?? false)
                    ->authorize(fn () => Auth::user()?->hasRole('super_admin') ?? false),
            ])
            ->defaultSort('is_admin', 'desc');
    }

    /** Named once so the `visible()` and the `authorize()` above cannot drift. */
    protected static function mayEditPortalUser(TenantUser $record): bool
    {
        $user = Auth::user();

        if ($record->is_admin) {
            return $user?->hasRole('super_admin') ?? false;
        }

        return $user?->can('tenants.edit') ?? false;
    }
}
