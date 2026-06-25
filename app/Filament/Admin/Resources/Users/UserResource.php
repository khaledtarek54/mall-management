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

    public static function canAccess(): bool
    {
        return Auth::user()?->hasRole('super_admin') ?? false;
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->hasRole('super_admin') ?? false;
    }

    public static function canEdit($record): bool
    {
        return Auth::user()?->hasRole('super_admin') ?? false;
    }

    public static function canDelete($record): bool
    {
        return Auth::user()?->hasRole('super_admin') && Auth::id() !== $record->id;
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
