<?php

namespace App\Filament\Admin\Resources\Trades;

use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Trades\Pages\CreateTrade;
use App\Filament\Admin\Resources\Trades\Pages\EditTrade;
use App\Filament\Admin\Resources\Trades\Pages\ListTrades;
use App\Filament\Admin\Resources\Trades\Schemas\TradeForm;
use App\Filament\Admin\Resources\Trades\Tables\TradesTable;
use App\Models\Trade;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * التخصصات — the trade register.
 *
 * Portfolio-level master data: a trade is a trade in every mall, and what differs per property is
 * which vendor covers it. So there is no property field here and no `ScopesToProperty` — see
 * `App\Support\PropertyIsolation`.
 *
 * **No `$recordTitleAttribute` and no global search.** Fourteen rows of operator configuration,
 * maintained by scrolling; nobody types "hvac" into the search bar to find a *record*, they type it
 * to find the work orders. Registered in `SearchPolicy::GLOBAL_SEARCH_EXEMPT` with that reason.
 */
class TradeResource extends Resource
{
    // The panel has tenancy configured, so Filament scopes EVERY resource through an `asset`
    // relationship unless told otherwise — and a portfolio-shared register has none, which 500s
    // the list page with a LogicException rather than showing an empty table. Same opt-out the
    // charge-code and tax-code catalogues use.
    use BypassesFilamentTenantAutoScope;
    use RoleGatedActions;

    protected static ?string $model = Trade::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static function permissionModule(): string
    {
        return 'trades';
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.facility.trade.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.facility.trade.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.facility.trade.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return TradeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TradesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTrades::route('/'),
            'create' => CreateTrade::route('/create'),
            'edit' => EditTrade::route('/{record}/edit'),
        ];
    }
}
