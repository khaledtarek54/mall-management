<?php

namespace App\Filament\Admin\Resources\ChargeCodes;

use App\Filament\Admin\RelationManagers\ActivitiesRelationManager;
use App\Filament\Admin\Resources\ChargeCodes\Pages\CreateChargeCode;
use App\Filament\Admin\Resources\ChargeCodes\Pages\EditChargeCode;
use App\Filament\Admin\Resources\ChargeCodes\Pages\ListChargeCodes;
use App\Filament\Admin\Resources\ChargeCodes\Schemas\ChargeCodeForm;
use App\Filament\Admin\Resources\ChargeCodes\Tables\ChargeCodesTable;
use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Models\ChargeCode;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * أكواد الرسوم — the charge-code catalogue (gap-analysis row 216).
 *
 * Adding a billable line — "key money", a chiller charge, a signage fee — used to mean editing a
 * PHP enum and a private const map inside the journalizer, then deploying. It is now a row an
 * accountant types, and the posting role it names resolves through the same `account_mappings` the
 * Posting Map screen edits, so a new code inherits per-property overrides for free.
 *
 * Shared, not property-scoped: a charge code is portfolio vocabulary, and the account it lands in is
 * where the per-property override belongs — one level down, on the mapping.
 */
class ChargeCodeResource extends Resource
{
    use BypassesFilamentTenantAutoScope;
    use RoleGatedActions;

    protected static ?string $model = ChargeCode::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'code';

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.charge_codes');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.charge_code.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.charge_code.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.general_ledger');
    }

    public static function form(Schema $schema): Schema
    {
        return ChargeCodeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ChargeCodesTable::configure($table);
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
            'index' => ListChargeCodes::route('/'),
            'create' => CreateChargeCode::route('/create'),
            'edit' => EditChargeCode::route('/{record}/edit'),
        ];
    }
}
