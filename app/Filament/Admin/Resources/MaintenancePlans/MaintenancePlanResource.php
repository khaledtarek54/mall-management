<?php

namespace App\Filament\Admin\Resources\MaintenancePlans;

use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\MaintenancePlans\Pages\CreateMaintenancePlan;
use App\Filament\Admin\Resources\MaintenancePlans\Pages\EditMaintenancePlan;
use App\Filament\Admin\Resources\MaintenancePlans\Pages\ListMaintenancePlans;
use App\Filament\Admin\Resources\MaintenancePlans\Schemas\MaintenancePlanForm;
use App\Filament\Admin\Resources\MaintenancePlans\Tables\MaintenancePlansTable;
use App\Filament\Concerns\SearchesNormalizedText;
use App\Models\MaintenancePlan;
use App\Support\TenantScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Preventive-maintenance plans (module 26) — recurring facility schedules that raise
 * work orders when due. Property-scoped; gated by the `preventive_maintenance` module +
 * `preventive_maintenance.*` permissions (operations).
 */
class MaintenancePlanResource extends Resource
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

    protected static ?string $model = MaintenancePlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'title';

    protected static function permissionModule(): string
    {
        return 'preventive_maintenance';
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
        return __('admin.groups.facility');
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

    /**
     * Searched through the fold-normalized blob, never a raw column.
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
        ];
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
