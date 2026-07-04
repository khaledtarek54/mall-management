<?php

namespace App\Filament\Admin\Resources\Employees;

use App\Filament\Admin\Resources\Concerns\BypassesScopingOnAll;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Admin\Resources\Employees\Pages\EditEmployee;
use App\Filament\Admin\Resources\Employees\Pages\ListEmployees;
use App\Filament\Admin\Resources\Employees\Schemas\EmployeeForm;
use App\Filament\Admin\Resources\Employees\Tables\EmployeesTable;
use App\Models\Employee;
use App\Support\TenantScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Employee master (module 24 — HR), scoped to the current property (direct
 * asset_id, like Unit / Fixed Asset). Gated by the `employees` module +
 * `employees.*` permissions (owned by the HR role).
 */
class EmployeeResource extends Resource
{
    use BypassesScopingOnAll;
    use RoleGatedActions;

    protected static ?string $model = Employee::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?int $navigationSort = 44;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $tenantOwnershipRelationshipName = 'asset';

    protected static function permissionModule(): string
    {
        return 'employees';
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.employees.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.employees.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.employees.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.employees.group');
    }

    public static function form(Schema $schema): Schema
    {
        return EmployeeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EmployeesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmployees::route('/'),
            'create' => CreateEmployee::route('/create'),
            'edit' => EditEmployee::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'code', 'national_id'];
    }

    /**
     * Server-side guard against a tampered `asset_id` on create/edit — in
     * "All Properties" mode the Select is enabled and its value is client-supplied,
     * so re-validate that the target property is within the user's visible set
     * (null = portfolio user, sees all).
     */
    public static function assertAssetInScope(mixed $assetId): void
    {
        $visible = TenantScope::visibleAssetIds();
        if ($visible !== null && ! in_array((int) $assetId, $visible, true)) {
            abort(403);
        }
    }
}
