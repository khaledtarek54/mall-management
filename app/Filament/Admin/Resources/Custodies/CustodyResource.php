<?php

namespace App\Filament\Admin\Resources\Custodies;

use App\Filament\Admin\Resources\Concerns\BypassesScopingOnAll;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Custodies\Pages\CreateCustody;
use App\Filament\Admin\Resources\Custodies\Pages\EditCustody;
use App\Filament\Admin\Resources\Custodies\Pages\ListCustodies;
use App\Filament\Admin\Resources\Custodies\Schemas\CustodyForm;
use App\Filament\Admin\Resources\Custodies\Tables\CustodiesTable;
use App\Models\Custody;
use App\Support\TenantScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Custodies (عهدة — module 25, Treasury), scoped to the current property (denormalised
 * asset_id). Settled-to-date is DERIVED in one subquery; outstanding is computed per
 * row. Gated by the `custodies` module + `custodies.*` permissions (accounting).
 */
class CustodyResource extends Resource
{
    use BypassesScopingOnAll;
    use RoleGatedActions;

    protected static ?string $model = Custody::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static ?int $navigationSort = 45;

    protected static ?string $recordTitleAttribute = 'reference';

    protected static ?string $tenantOwnershipRelationshipName = 'asset';

    protected static function permissionModule(): string
    {
        return 'custodies';
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.custodies.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.custodies.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.custodies.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.custodies.group');
    }

    public static function form(Schema $schema): Schema
    {
        return CustodyForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CustodiesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Admin\RelationManagers\CustodyTransactionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustodies::route('/'),
            'create' => CreateCustody::route('/create'),
            'edit' => EditCustody::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        // Derived settled-to-date in one subquery — no per-row N+1.
        return parent::getEloquentQuery()->withSum('transactions as settled_sum', 'amount');
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['reference'];
    }

    /** Server-side guard: the custodian's property must be within the user's visible set. */
    public static function assertAssetInScope(mixed $assetId): void
    {
        $visible = TenantScope::visibleAssetIds();
        if ($visible !== null && ! in_array((int) $assetId, $visible, true)) {
            abort(403);
        }
    }
}
