<?php

namespace App\Filament\Admin\Resources\Units;

use App\Filament\Admin\RelationManagers\UnitEncumbrancesRelationManager;
use App\Filament\Admin\RelationManagers\UnitLeasesRelationManager;
use App\Filament\Admin\RelationManagers\UnitMetersRelationManager;
use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Concerns\ScopesToProperty;
use App\Filament\Admin\Resources\Units\Pages\CreateUnit;
use App\Filament\Admin\Resources\Units\Pages\EditUnit;
use App\Filament\Admin\Resources\Units\Pages\ListUnits;
use App\Filament\Admin\Resources\Units\Schemas\UnitForm;
use App\Filament\Admin\Resources\Units\Tables\UnitsTable;
use App\Filament\Concerns\SearchesNormalizedText;
use App\Models\Area;
use App\Models\Floor;
use App\Models\Unit;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class UnitResource extends Resource
{
    // NOT Filament auto-tenancy: asset_id is CLIENT-supplied (the operator picks the mall, and that
    // Select is enabled in All-Properties mode). Filament's ownership `creating` hook would force
    // asset_id to the current tenant — and in All-mode the tenant is the ALL pseudo-asset, silently
    // clobbering the chosen mall (the "Announcements tenancy trap"). ScopesToProperty
    // turns that hook off AND scopes reads from the model's own #[PropertyOwned]; the submitted asset_id is
    // re-validated by assertAssetInScope() on create + edit.
    use GuardsAssetInScope;
    use RoleGatedActions;
    use ScopesToProperty;
    use SearchesNormalizedText;

    protected static ?string $model = Unit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?string $recordTitleAttribute = 'code';

    /**
     * By unit code, by property, or by whoever is trading there right now.
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
            'asset.search_text',
            'activeLease.tenant.search_text',
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.resources.unit.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.unit.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.unit.plural');
    }

    /**
     * A unit's facility zone (`area_id`) must belong to the SAME property as the unit. The form
     * only scopes the picker's OPTIONS; that is not a server-side guarantee (a crafted Livewire
     * request can submit any id, and Filament's Select adds no implicit `exists`/`in` rule). Left
     * unguarded, a restricted user could tag a mall-A unit with a mall-B zone — which then leaks
     * mall-A request data to mall-B supervisors via the area-routing fan-out (module 30 → 11).
     * Mirrors AreaResource::assertSupervisorsInScope on the sibling relation.
     */
    public static function assertAreaInScope(mixed $areaId, ?int $assetId): void
    {
        if ($areaId === null || $areaId === '') {
            return;
        }

        abort_unless(
            Area::whereKey($areaId)->where('asset_id', $assetId)->exists(),
            403,
        );
    }

    /**
     * A unit's FLOOR must belong to the same property as the unit — the identical rule to
     * `assertAreaInScope` above, on the relation that arrived later and did not get it (validation
     * sweep, spacing, 2026-08-11). `UnitForm` clamps the floor picker's options to the unit's own
     * property, and the sibling zone field was guarded server-side from the start; this one was
     * left on the picker alone.
     *
     * A unit sitting on another mall's floor is a reporting leak, not a cosmetic one: the stacking
     * plan places the shop in the wrong building, and anything grouped by floor mixes two
     * properties' units into one row — which is exactly what property isolation exists to make
     * impossible.
     */
    public static function assertFloorInScope(mixed $floorId, ?int $assetId): void
    {
        if ($floorId === null || $floorId === '') {
            return;
        }

        abort_unless(
            Floor::whereKey($floorId)->where('asset_id', $assetId)->exists(),
            403,
        );
    }

    public static function form(Schema $schema): Schema
    {
        return UnitForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UnitsTable::configure($table);
    }

    /**
     * The Unit resource had NO relation managers. The one question an operator asks standing in
     * front of a shop — who is in here, and who was here before — had no answer on the unit's own
     * page, even though the data was always there.
     */
    public static function getRelations(): array
    {
        return [
            UnitLeasesRelationManager::class,
            // Why the unit picker flags this shop, on the shop itself: who holds an option
            // over it and until when. The ⚠ warning said THAT it was encumbered, never by whom.
            UnitEncumbrancesRelationManager::class,
            UnitMetersRelationManager::class,
            // No ActivitiesRelationManager here: `Unit` does not use `LogsActivity`, and the
            // manager fatals on `activitiesAsSubject()` when it does not. Giving units an
            // audit trail is worth doing — it is a change to a core model's behaviour, so it
            // belongs in its own commit, not smuggled into a visibility fix.
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUnits::route('/'),
            'create' => CreateUnit::route('/create'),
            'edit' => EditUnit::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        // getEloquentQuery() scopes to the active Filament tenant (Asset) itself
        // (see the trait note above), so the badge reflects the currently-viewed
        // property — not a global count. "All Properties" view returns the
        // portfolio-wide total (super_admin) or the user's visible set, because
        // getEloquentQuery() leaves the query unscoped / whereIn accordingly.
        return (string) static::getEloquentQuery()->where('status', 'vacant')->count();
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            __('admin.resources.asset.singular') => $record->asset?->name,
            __('admin.tables.unit.category') => __("admin.enums.category.{$record->category}"),
            __('admin.tables.unit.tenant') => $record->activeLease?->tenant?->name,
            __('admin.tables.common.status') => __("admin.statuses.unit.{$record->status}"),
        ];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['asset', 'activeLease.tenant']);
    }
}
