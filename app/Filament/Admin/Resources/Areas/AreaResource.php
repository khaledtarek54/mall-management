<?php

namespace App\Filament\Admin\Resources\Areas;

use App\Filament\Admin\Resources\Areas\Pages\CreateArea;
use App\Filament\Admin\Resources\Areas\Pages\EditArea;
use App\Filament\Admin\Resources\Areas\Pages\ListAreas;
use App\Filament\Admin\Resources\Areas\Schemas\AreaForm;
use App\Filament\Admin\Resources\Areas\Tables\AreaTable;
use App\Filament\Admin\Resources\Concerns\BypassesScopingOnAll;
use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Models\Area;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * The facility-zone register (module 30) — scoped to the current property
 * (direct asset_id, like Unit / Warehouse / Equipment). Gated by the
 * `areas.*` permissions and lives in the Operations navigation group with the
 * other facility modules.
 */
class AreaResource extends Resource
{
    use BypassesScopingOnAll;
    use GuardsAssetInScope;
    use RoleGatedActions;

    protected static ?string $model = Area::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected static ?int $navigationSort = 29;

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?string $tenantOwnershipRelationshipName = 'asset';

    protected static function permissionModule(): string
    {
        return 'areas';
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.areas.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.areas.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.areas.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.operations');
    }

    public static function form(Schema $schema): Schema
    {
        return AreaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AreaTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAreas::route('/'),
            'create' => CreateArea::route('/create'),
            'edit' => EditArea::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'code'];
    }
}
