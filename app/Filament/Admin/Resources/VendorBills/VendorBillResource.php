<?php

namespace App\Filament\Admin\Resources\VendorBills;

use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\VendorBills\Pages\CreateVendorBill;
use App\Filament\Admin\Resources\VendorBills\Pages\EditVendorBill;
use App\Filament\Admin\Resources\VendorBills\Pages\ListVendorBills;
use App\Filament\Admin\Resources\VendorBills\RelationManagers\VendorBillPaymentsRelationManager;
use App\Filament\Admin\Resources\VendorBills\Schemas\VendorBillForm;
use App\Filament\Admin\Resources\VendorBills\Tables\VendorBillsTable;
use App\Models\VendorBill;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * فواتير الموردين — Accounts Payable. Each bill recognises an expense + a payable
 * and is settled by VendorBillPayments. Scoped by the bill's `asset_id` dimension,
 * always also showing consolidated (null-asset) company-level bills.
 */
class VendorBillResource extends Resource
{
    use BypassesFilamentTenantAutoScope;
    use RoleGatedActions;

    protected static ?string $model = VendorBill::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 26;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.vendor_bills');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.vendor_bill.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.vendor_bill.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.accounting');
    }

    public static function form(Schema $schema): Schema
    {
        return VendorBillForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VendorBillsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            VendorBillPaymentsRelationManager::class,
            \App\Filament\Admin\RelationManagers\ActivitiesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVendorBills::route('/'),
            'create' => CreateVendorBill::route('/create'),
            'edit' => EditVendorBill::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if ($assetId = \App\Support\TenantScope::currentAssetId()) {
            // Property-level bills for this asset OR consolidated company bills.
            $query->where(fn ($q) => $q->where('asset_id', $assetId)->orWhereNull('asset_id'));
        } elseif (($ids = \App\Support\TenantScope::visibleAssetIds()) !== null) {
            $query->where(fn ($q) => $q->whereIn('asset_id', $ids)->orWhereNull('asset_id'));
        }

        return $query;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['number', 'vendor.name', 'reference'];
    }
}
