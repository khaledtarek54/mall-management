<?php

namespace App\Filament\Admin\Resources\AccountMappings;

use App\Filament\Admin\RelationManagers\ActivitiesRelationManager;
use App\Filament\Admin\Resources\AccountMappings\Pages\CreateAccountMapping;
use App\Filament\Admin\Resources\AccountMappings\Pages\EditAccountMapping;
use App\Filament\Admin\Resources\AccountMappings\Pages\ListAccountMappings;
use App\Filament\Admin\Resources\AccountMappings\Schemas\AccountMappingForm;
use App\Filament\Admin\Resources\AccountMappings\Tables\AccountMappingsTable;
use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Models\AccountMapping;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * ربط الحسابات — the posting map: which chart account each semantic role posts to.
 *
 * **The screen that was documented but never built.** `AccountMappingSeeder` has claimed since it was
 * written that "the accountant can re-point any role from the UI without touching code" — and there
 * was no UI. The table was seeded and thereafter unreachable, so re-pointing rent revenue at a
 * different account meant a developer running SQL against production. This is the screen that makes
 * the sentence true, and it is what Voyager gives an accountant as a matter of course.
 *
 * **It is the handover point for a new chart of accounts.** When Jawad's real Egyptian chart lands,
 * every role has to be re-pointed at its accounts; that is configuration work an accountant does,
 * not a migration.
 *
 * **Shared, with per-property overrides.** One global default per role (`asset_id` null) and an
 * optional row per property that wins over it — so a mall with its own revenue account can have one
 * without forking the map. Classified SHARED in `App\Support\PropertyIsolation`; the property picker
 * is scoped to what the operator may see, so an override cannot be aimed at someone else's mall.
 */
class AccountMappingResource extends Resource
{
    use BypassesFilamentTenantAutoScope;
    use RoleGatedActions;

    protected static ?string $model = AccountMapping::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?string $recordTitleAttribute = 'key';

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.account_mappings');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.account_mapping.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.account_mapping.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return AccountMappingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AccountMappingsTable::configure($table);
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
            'index' => ListAccountMappings::route('/'),
            'create' => CreateAccountMapping::route('/create'),
            'edit' => EditAccountMapping::route('/{record}/edit'),
        ];
    }
}
