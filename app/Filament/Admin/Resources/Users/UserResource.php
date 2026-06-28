<?php

namespace App\Filament\Admin\Resources\Users;

use App\Filament\Admin\Resources\Users\Pages\CreateUser;
use App\Filament\Admin\Resources\Users\Pages\EditUser;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Admin\Resources\Users\Schemas\UserForm;
use App\Filament\Admin\Resources\Users\Tables\UsersTable;
use App\Models\User;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'email';

    // Users are a cross-property concept — a single user is assigned to many
    // properties via asset_user, so the resource bypasses tenancy.
    protected static bool $isScopedToTenant = false;

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.users');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.user.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.user.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.hr');
    }

    // Users is the HR department's resource: access/create/edit are gated on
    // the users.* permissions (so hr — and the cross-cutting roles that hold
    // them — can manage staff accounts). Delete stays super_admin-only below.
    public static function canAccess(): bool
    {
        return Auth::user()?->can('users.view') ?? false;
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->can('users.view') ?? false;
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->can('users.create') ?? false;
    }

    public static function canEdit($record): bool
    {
        return Auth::user()?->can('users.edit') ?? false;
    }

    public static function canDelete($record): bool
    {
        return Auth::user()?->hasRole('super_admin') && Auth::id() !== $record->id;
    }

    // Force-delete + restore must never be more permissive than delete.
    public static function canForceDelete($record): bool
    {
        return Auth::user()?->hasRole('super_admin') ?? false;
    }

    public static function canRestore($record): bool
    {
        return Auth::user()?->hasRole('super_admin') ?? false;
    }

    // Bulk delete is off by default (project convention); single delete above stays.
    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function canForceDeleteAny(): bool
    {
        return false;
    }

    /**
     * Server-side guard: only a super_admin may grant or revoke the super_admin
     * role. For any other actor, force the submitted role set to preserve the
     * target's CURRENT super_admin status — so an hr/manager can't escalate a
     * user (or themselves) to super_admin, nor strip it. Called from the User
     * Create/Edit pages before save. (The form also hides the option in the UI,
     * but this is the enforcement that actually holds.)
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function guardSuperAdminAssignment(array $data, ?User $record = null): array
    {
        if (! array_key_exists('roles', $data) || Auth::user()?->hasRole('super_admin')) {
            return $data;
        }

        $superId = \Spatie\Permission\Models\Role::query()
            ->where('name', 'super_admin')->where('guard_name', 'web')->value('id');

        if (! $superId) {
            return $data;
        }

        $roles = collect($data['roles'])
            ->map(fn ($v) => (int) $v)
            ->reject(fn ($id) => $id === (int) $superId);

        if ($record?->hasRole('super_admin')) {
            $roles->push((int) $superId); // preserve — non-super_admin can't revoke it either
        }

        $data['roles'] = $roles->unique()->values()->all();

        return $data;
    }

    public static function form(Schema $schema): Schema
    {
        return UserForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UsersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'email'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            __('admin.fields.email') => $record->email,
            __('admin.users.role') => $record->roles->pluck('name')->map(fn ($r) => __("admin.users.roles_list.{$r}", [], $r))->implode(', '),
        ];
    }
}
