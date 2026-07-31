<?php

namespace App\Filament\Admin\Resources\Employees;

use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Employees\Pages\CreateEmployee;
use App\Filament\Admin\Resources\Employees\Pages\EditEmployee;
use App\Filament\Admin\Resources\Employees\Pages\ListEmployees;
use App\Filament\Admin\Resources\Employees\Schemas\EmployeeForm;
use App\Filament\Admin\Resources\Employees\Tables\EmployeesTable;
use App\Filament\Concerns\SearchesNormalizedText;
use App\Models\Employee;
use App\Support\TenantScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Employee master (module 24 — HR), scoped to the current property (direct
 * asset_id, like Unit / Fixed Asset). Gated by the `employees` module +
 * `employees.*` permissions (owned by the HR role).
 */
class EmployeeResource extends Resource
{
    // NOT Filament auto-tenancy: asset_id is CLIENT-supplied (the operator picks the mall, and that
    // Select is enabled in All-Properties mode). Filament's ownership `creating` hook would force
    // asset_id to the current tenant — and in All-mode the tenant is the ALL pseudo-asset, silently
    // clobbering the chosen mall (the "Announcements tenancy trap"). BypassesFilamentTenantAutoScope
    // turns that hook off; reads are scoped in getEloquentQuery() below and the submitted asset_id is
    // re-validated by assertAssetInScope() on create + edit.
    use BypassesFilamentTenantAutoScope;
    use RoleGatedActions;
    use SearchesNormalizedText;

    protected static ?string $model = Employee::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    protected static function permissionModule(): string
    {
        return 'employees';
    }

    /** Property-scope the list ourselves (Filament auto-tenancy is off — see the trait note above). */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if ($assetId = TenantScope::currentAssetId()) {
            $query->where('asset_id', $assetId);
        } elseif (($ids = TenantScope::visibleAssetIds()) !== null) {
            // All-Properties mode: a restricted user still sees only their own malls.
            $query->whereIn('asset_id', $ids);
        }

        return $query;
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
        return __('admin.groups.hr_payroll');
    }

    public static function form(Schema $schema): Schema
    {
        return EmployeeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EmployeesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Admin\RelationManagers\EmployeeAdvancesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEmployees::route('/'),
            'create' => CreateEmployee::route('/create'),
            'edit' => EditEmployee::route('/{record}/edit'),
        ];
    }

    /**
     * By name, staff code or national id, or by department.
     *
     * Every path ends in `search_text` on purpose — see
     * App\Filament\Concerns\SearchesNormalizedText.
     *
     * @return array<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return [
            'search_text',
            'department.search_text',
        ];
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
    /**
     * Context under the title. A bare reference does not tell an operator whether the
     * row in front of them is the one they were hunting for.
     *
     * @param  Employee  $record  Narrowed from Filament's Model signature so static analysis
     *                    can see the columns — the alternative was ten baseline entries.
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var \App\Models\Department|null $department */
        $department = $record->department;

        return [
            __('admin.fields.reference') => $record->code,
            __('admin.fields.role') => $record->position,
            __('admin.tables.department.name') => $department?->name,
        ];
    }

    /**
     * Eager-load exactly what getGlobalSearchResultDetails() reaches for. Without this
     * the details above fire one query per row, per keystroke, on top of the search.
     */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['department']);
    }

}
