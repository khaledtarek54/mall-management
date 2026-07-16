<?php

namespace App\Filament\Admin\Resources\PurchaseRequests;

use App\Filament\Admin\RelationManagers\PurchaseRequestLinesRelationManager;
use App\Filament\Admin\Resources\Concerns\BypassesScopingOnAll;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\PurchaseRequests\Pages\CreatePurchaseRequest;
use App\Filament\Admin\Resources\PurchaseRequests\Pages\EditPurchaseRequest;
use App\Filament\Admin\Resources\PurchaseRequests\Pages\ListPurchaseRequests;
use App\Filament\Admin\Resources\PurchaseRequests\Schemas\PurchaseRequestForm;
use App\Filament\Admin\Resources\PurchaseRequests\Tables\PurchaseRequestsTable;
use App\Models\PurchaseRequest;
use App\Support\TenantScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Procurement (module 29) — the request-to-purchase flow (FR-PROC-01..05).
 * Property-scoped; gated on the `procurement` module + `procurement.*` permissions.
 */
class PurchaseRequestResource extends Resource
{
    use BypassesScopingOnAll;
    use RoleGatedActions;

    protected static ?string $model = PurchaseRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    protected static ?int $navigationSort = 48;

    protected static ?string $recordTitleAttribute = 'reference';

    protected static ?string $tenantOwnershipRelationshipName = 'asset';

    protected static function permissionModule(): string
    {
        return 'procurement';
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.procurement.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.procurement.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.procurement.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.procurement.group');
    }

    public static function form(Schema $schema): Schema
    {
        return PurchaseRequestForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PurchaseRequestsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            PurchaseRequestLinesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPurchaseRequests::route('/'),
            'create' => CreatePurchaseRequest::route('/create'),
            'edit' => EditPurchaseRequest::route('/{record}/edit'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['reference', 'order_reference'];
    }

    public static function assertAssetInScope(mixed $assetId): void
    {
        $visible = TenantScope::visibleAssetIds();
        if ($visible !== null && ! in_array((int) $assetId, $visible, true)) {
            abort(403);
        }
    }
}
