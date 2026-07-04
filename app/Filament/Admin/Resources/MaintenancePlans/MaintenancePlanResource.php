<?php

namespace App\Filament\Admin\Resources\MaintenancePlans;

use App\Filament\Admin\Resources\Concerns\BypassesScopingOnAll;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\MaintenancePlans\Pages\CreateMaintenancePlan;
use App\Filament\Admin\Resources\MaintenancePlans\Pages\EditMaintenancePlan;
use App\Filament\Admin\Resources\MaintenancePlans\Pages\ListMaintenancePlans;
use App\Filament\Admin\Resources\MaintenancePlans\Schemas\MaintenancePlanForm;
use App\Filament\Admin\Resources\MaintenancePlans\Tables\MaintenancePlansTable;
use App\Models\MaintenancePlan;
use App\Support\TenantScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Preventive-maintenance plans (module 26) — recurring facility schedules that raise
 * work orders when due. Property-scoped; gated by the `preventive_maintenance` module +
 * `preventive_maintenance.*` permissions (operations).
 */
class MaintenancePlanResource extends Resource
{
    use BypassesScopingOnAll;
    use RoleGatedActions;

    protected static ?string $model = MaintenancePlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 46;

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $tenantOwnershipRelationshipName = 'asset';

    protected static function permissionModule(): string
    {
        return 'preventive_maintenance';
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.preventive_maintenance.plan.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.preventive_maintenance.plan.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.preventive_maintenance.plan.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.preventive_maintenance.group');
    }

    public static function form(Schema $schema): Schema
    {
        return MaintenancePlanForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MaintenancePlansTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMaintenancePlans::route('/'),
            'create' => CreateMaintenancePlan::route('/create'),
            'edit' => EditMaintenancePlan::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['title'];
    }

    /** Server-side guard against a tampered `asset_id` (All-Properties mode). */
    public static function assertAssetInScope(mixed $assetId): void
    {
        $visible = TenantScope::visibleAssetIds();
        if ($visible !== null && ! in_array((int) $assetId, $visible, true)) {
            abort(403);
        }
    }
}
