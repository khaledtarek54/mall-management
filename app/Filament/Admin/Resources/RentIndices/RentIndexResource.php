<?php

namespace App\Filament\Admin\Resources\RentIndices;

use App\Filament\Admin\RelationManagers\ActivitiesRelationManager;
use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\RentIndices\Pages\CreateRentIndex;
use App\Filament\Admin\Resources\RentIndices\Pages\EditRentIndex;
use App\Filament\Admin\Resources\RentIndices\Pages\ListRentIndices;
use App\Filament\Admin\Resources\RentIndices\Schemas\RentIndexForm;
use App\Filament\Admin\Resources\RentIndices\Tables\RentIndicesTable;
use App\Models\RentIndex;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * الرقم القياسي — the published index figures a CPI-linked lease escalates against.
 *
 * Voyager's **index source** *(cited, `docs/benchmarks/yardi/01-yardi-lease-administration.md`
 * §4)*, and the piece whose absence meant `escalation_type = 'cpi'` could be written on a lease and
 * never applied: the sweep refused to invent an index number, correctly, and there was nowhere for
 * the real one to live.
 *
 * **One screen, one job: record what CAPMAS published.** Somebody types the month's figure once,
 * and every CPI lease in the portfolio reads it on its own anniversary. Portfolio-shared for the
 * same reason the chart of accounts is — a per-mall copy of a national statistic is three chances
 * to key it differently.
 */
class RentIndexResource extends Resource
{
    use BypassesFilamentTenantAutoScope;
    use RoleGatedActions;

    protected static ?string $model = RentIndex::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 6;

    protected static ?string $recordTitleAttribute = 'code';

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.rent_indices');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.rent_index.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.rent_index.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.leasing');
    }

    public static function form(Schema $schema): Schema
    {
        return RentIndexForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RentIndicesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ActivitiesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRentIndices::route('/'),
            'create' => CreateRentIndex::route('/create'),
            'edit' => EditRentIndex::route('/{record}/edit'),
        ];
    }
}
