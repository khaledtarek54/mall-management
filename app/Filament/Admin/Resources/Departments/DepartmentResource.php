<?php

namespace App\Filament\Admin\Resources\Departments;

use App\Filament\Admin\RelationManagers\DepartmentMembersRelationManager;
use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Departments\Pages\EditDepartment;
use App\Filament\Admin\Resources\Departments\Pages\ListDepartments;
use App\Filament\Admin\Resources\Departments\Schemas\DepartmentForm;
use App\Filament\Admin\Resources\Departments\Tables\DepartmentsTable;
use App\Models\Department;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class DepartmentResource extends Resource
{
    use GuardsAssetInScope;
    use RoleGatedActions;

    protected static ?string $model = Department::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 7;

    protected static ?string $recordTitleAttribute = 'name';

    // Departments are operator-wide org units, not per-property records.
    protected static bool $isScopedToTenant = false;

    // Fixed reference set (HR / Marketing / Accounting / Leasing / Operations),
    // seeded — operators manage membership, never the department list itself:
    // NO create, NO delete.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.resources.department.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.department.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.department.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.hr');
    }

    public static function form(Schema $schema): Schema
    {
        return DepartmentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DepartmentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            DepartmentMembersRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDepartments::route('/'),
            'edit' => EditDepartment::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // Hybrid scope: operator-wide (null asset_id) departments are visible to
        // everyone; property-scoped ones only within the user's visible set. Mirrors
        // Department::selectableOptions(). visibleAssetIds() = null for portfolio
        // users (super_admin / owners), who then see all departments.
        $ids = \App\Support\TenantScope::visibleAssetIds();
        if ($ids !== null) {
            $query->where(fn (Builder $q) => $q->whereNull('asset_id')->orWhereIn('asset_id', $ids));
        }

        return $query;
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'code'];
    }
}
