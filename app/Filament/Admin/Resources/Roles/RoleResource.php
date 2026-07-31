<?php

namespace App\Filament\Admin\Resources\Roles;

use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Roles\Pages\CreateRole;
use App\Filament\Admin\Resources\Roles\Pages\EditRole;
use App\Filament\Admin\Resources\Roles\Pages\ListRoles;
use App\Filament\Admin\Resources\Roles\Schemas\RoleForm;
use App\Filament\Admin\Resources\Roles\Tables\RolesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Spatie\Permission\Models\Role;

class RoleResource extends Resource
{
    /**
     * Deliberately absent from global search — the reason is stated in
     * App\Support\SearchPolicy::GLOBAL_SEARCH_EXEMPT, which the conformance
     * gate reads. Do not flip this without removing that entry.
     */
    protected static bool $isGloballySearchable = false;

    use RoleGatedActions;

    protected static function permissionModule(): string
    {
        return 'roles';
    }

    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    // Roles are a system-level concept, not property-scoped.
    protected static bool $isScopedToTenant = false;

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.roles');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.role.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.role.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.settings');
    }

    public static function form(Schema $schema): Schema
    {
        return RoleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RolesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
