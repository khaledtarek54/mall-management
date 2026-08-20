<?php

namespace App\Filament\Admin\Resources\ServicePlans;

use App\Filament\Admin\RelationManagers\ServicePlanStopsRelationManager;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Concerns\ScopesToProperty;
use App\Filament\Admin\Resources\ServicePlans\Pages\CreateServicePlan;
use App\Filament\Admin\Resources\ServicePlans\Pages\EditServicePlan;
use App\Filament\Admin\Resources\ServicePlans\Pages\ListServicePlans;
use App\Filament\Admin\Resources\ServicePlans\Schemas\ServicePlanForm;
use App\Filament\Admin\Resources\ServicePlans\Tables\ServicePlansTable;
use App\Filament\Concerns\SearchesNormalizedText;
use App\Models\ServicePlan;
use App\Support\TenantScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Preventive-maintenance plans (module 26) — recurring facility schedules that raise
 * work orders when due. Property-scoped; gated by the `facility` module +
 * `facility.*` permissions (operations).
 */
class ServicePlanResource extends Resource
{
    // NOT Filament auto-tenancy: asset_id is CLIENT-supplied (the operator picks the mall, and that
    // Select is enabled in All-Properties mode). Filament's ownership `creating` hook would force
    // asset_id to the current tenant — and in All-mode the tenant is the ALL pseudo-asset, silently
    // clobbering the chosen mall (the "Announcements tenancy trap"). ScopesToProperty
    // turns that hook off AND scopes reads from the model's own #[PropertyOwned]; the submitted asset_id is
    // re-validated by assertAssetInScope() on create + edit.
    use RoleGatedActions;
    use ScopesToProperty;
    use SearchesNormalizedText;

    protected static ?string $model = ServicePlan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'title';

    protected static function permissionModule(): string
    {
        return 'facility';
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.facility.plan.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.facility.plan.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.facility.plan.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.facility');
    }

    public static function form(Schema $schema): Schema
    {
        return ServicePlanForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ServicePlansTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ServicePlanStopsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServicePlans::route('/'),
            'create' => CreateServicePlan::route('/create'),
            'edit' => EditServicePlan::route('/{record}/edit'),
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
