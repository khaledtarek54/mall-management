<?php

namespace App\Filament\Admin\Resources\Payrolls;

use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Payrolls\Pages\CreatePayroll;
use App\Filament\Admin\Resources\Payrolls\Pages\EditPayroll;
use App\Filament\Admin\Resources\Payrolls\Pages\ListPayrolls;
use App\Filament\Admin\Resources\Payrolls\Schemas\PayrollForm;
use App\Filament\Admin\Resources\Payrolls\Tables\PayrollsTable;
use App\Models\Payroll;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * مسير رواتب — monthly payroll runs. Each approved run recognises the salary
 * expense + its statutory withholdings on the GL. Scoped by the run's `asset_id`
 * dimension, always also showing consolidated (null-asset) company-level runs.
 */
class PayrollResource extends Resource
{
    use BypassesFilamentTenantAutoScope;
    use RoleGatedActions;

    protected static ?string $model = Payroll::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 29;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.payrolls');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.payroll.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.payroll.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.accounting');
    }

    public static function form(Schema $schema): Schema
    {
        return PayrollForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PayrollsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Admin\RelationManagers\PayrollLinesRelationManager::class,
            \App\Filament\Admin\RelationManagers\ActivitiesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayrolls::route('/'),
            'create' => CreatePayroll::route('/create'),
            'edit' => EditPayroll::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if ($assetId = \App\Support\TenantScope::currentAssetId()) {
            // Property-level runs for this asset OR consolidated company runs.
            $query->where(fn ($q) => $q->where('asset_id', $assetId)->orWhereNull('asset_id'));
        } elseif (($ids = \App\Support\TenantScope::visibleAssetIds()) !== null) {
            $query->where(fn ($q) => $q->whereIn('asset_id', $ids)->orWhereNull('asset_id'));
        }

        return $query;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['number'];
    }
}
