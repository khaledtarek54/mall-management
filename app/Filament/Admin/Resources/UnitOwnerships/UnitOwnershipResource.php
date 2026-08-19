<?php

namespace App\Filament\Admin\Resources\UnitOwnerships;

use App\Filament\Admin\RelationManagers\UnitOwnershipChargesRelationManager;
use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Concerns\ScopesToProperty;
use App\Filament\Admin\Resources\UnitOwnerships\Pages\CreateUnitOwnership;
use App\Filament\Admin\Resources\UnitOwnerships\Pages\EditUnitOwnership;
use App\Filament\Admin\Resources\UnitOwnerships\Pages\ListUnitOwnerships;
use App\Filament\Admin\Resources\UnitOwnerships\Schemas\UnitOwnershipForm;
use App\Filament\Admin\Resources\UnitOwnerships\Tables\UnitOwnershipsTable;
use App\Filament\Concerns\SearchesNormalizedText;
use App\Models\UnitOwnership;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The unit-ownership register (module 37) — مُلّاك الوحدات, the buyers who bought a shop instead of
 * renting one.
 *
 * Sits in the Leasing group beside Units and Leases, because that is where an operator goes to ask
 * "what is the position of unit A-102" — and the answer is either a lease or an ownership.
 *
 * **Not the property owners.** Module 32 apportions a mall's net to `Asset::propertyOwners()`, who
 * are `User`s RECEIVING money. These are `Tenant`s PAYING it.
 */
class UnitOwnershipResource extends Resource
{
    // asset_id is CLIENT-supplied (the operator picks the mall when not scoped to one), so Filament's
    // ownership `creating` hook must be off or it would clobber the chosen property. Reads are scoped
    // by ScopesToProperty from the model's own #[PropertyOwned]; the submitted asset_id is re-validated by assertAssetInScope().
    use GuardsAssetInScope;
    use RoleGatedActions;
    use ScopesToProperty;
    use SearchesNormalizedText;

    protected static ?string $model = UnitOwnership::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static ?int $navigationSort = 5;

    protected static ?string $recordTitleAttribute = 'reference';

    protected static function permissionModule(): string
    {
        return 'unit_ownerships';
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.unit_ownerships.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.unit_ownerships.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.unit_ownerships.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.leasing');
    }

    public static function form(Schema $schema): Schema
    {
        return UnitOwnershipForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return UnitOwnershipsTable::configure($table);
    }

    /**
     * The assessment schedule — what this owner is actually billed.
     *
     * Mounted 2026-08-19. Until then this resource had NO relation managers, and
     * `BillUnitOwnershipsService` bills an ownership from its `charges` rows: no screen anywhere
     * could create one, so every ownership an operator registered was skipped by the monthly run
     * forever. The register existed and nothing could add to it — the same shape as
     * `RemeasureUnitService` before the Remeasure action landed.
     *
     * @return array<int, class-string>
     */
    public static function getRelations(): array
    {
        return [
            UnitOwnershipChargesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUnitOwnerships::route('/'),
            'create' => CreateUnitOwnership::route('/create'),
            'edit' => EditUnitOwnership::route('/{record}/edit'),
        ];
    }

    /**
     * Searched through the fold-normalized blob, never a raw column — an owner's name is reached via
     * the party's own blob so «أحمد»/«احمد» match either way.
     *
     * @return array<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return [
            'search_text',
            'owner.search_text',
            'unit.search_text',
        ];
    }
}
