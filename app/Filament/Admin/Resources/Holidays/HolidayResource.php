<?php

namespace App\Filament\Admin\Resources\Holidays;

use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Holidays\Pages\CreateHoliday;
use App\Filament\Admin\Resources\Holidays\Pages\EditHoliday;
use App\Filament\Admin\Resources\Holidays\Pages\ListHolidays;
use App\Filament\Admin\Resources\Holidays\Schemas\HolidayForm;
use App\Filament\Admin\Resources\Holidays\Tables\HolidaysTable;
use App\Models\Holiday;
use App\Support\TenantScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * الإجازات — the days the mall's people are not at work.
 *
 * The register exists because Egypt's holidays cannot be computed: the Eids move on the Hijri
 * calendar and are fixed by moon sighting, and a mid-week holiday is routinely shifted to the
 * neighbouring Thursday. Somebody has to type them, once a year.
 *
 * **Hybrid scoping, like `Department`.** A null `asset_id` is a national holiday every mall
 * observes; a row naming a property overrides it for that date, which is how one mall trades
 * through Eid. So the list shows portfolio-wide rows PLUS the selected mall's own, and the form's
 * property picker is deliberately free rather than pinned — see `PropertyField::PORTFOLIO_LEVEL`.
 */
class HolidayResource extends Resource
{
    use BypassesFilamentTenantAutoScope;
    use RoleGatedActions;

    protected static ?string $model = Holiday::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 11;

    protected static function permissionModule(): string
    {
        return 'holidays';
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.facility.holiday.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.facility.holiday.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.facility.holiday.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.facility');
    }

    /**
     * Portfolio-wide rows AND the selected mall's own.
     *
     * Written out rather than taken from `ScopesToProperty`, for the reason `DepartmentResource`
     * does the same: a strict scope would hide every national holiday the moment somebody picked a
     * mall, and a national holiday is the ordinary case.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->when(
            TenantScope::visibleAssetIds(),
            fn (Builder $query, array $ids) => $query->where(
                fn (Builder $q) => $q->whereNull('asset_id')->orWhereIn('asset_id', $ids),
            ),
        );
    }

    public static function form(Schema $schema): Schema
    {
        return HolidayForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return HolidaysTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHolidays::route('/'),
            'create' => CreateHoliday::route('/create'),
            'edit' => EditHoliday::route('/{record}/edit'),
        ];
    }
}
