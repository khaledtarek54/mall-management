<?php

namespace App\Filament\Admin\Resources\VendorBills;

use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Concerns\ScopesToProperty;
use App\Filament\Admin\Resources\VendorBills\Pages\CreateVendorBill;
use App\Filament\Admin\Resources\VendorBills\Pages\EditVendorBill;
use App\Filament\Admin\Resources\VendorBills\Pages\ListVendorBills;
use App\Filament\Admin\Resources\VendorBills\RelationManagers\VendorBillPaymentsRelationManager;
use App\Filament\Admin\Resources\VendorBills\Schemas\VendorBillForm;
use App\Filament\Admin\Resources\VendorBills\Tables\VendorBillsTable;
use App\Filament\Concerns\SearchesNormalizedText;
use App\Models\VendorBill;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * فواتير الموردين — Accounts Payable. Each bill recognises an expense + a payable
 * and is settled by VendorBillPayments. Scoped by the bill's `asset_id` dimension,
 * always also showing consolidated (null-asset) company-level bills.
 */
class VendorBillResource extends Resource
{
    use GuardsAssetInScope;
    use RoleGatedActions;
    use ScopesToProperty;
    use SearchesNormalizedText;

    protected static ?string $model = VendorBill::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 1;

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
        return __('admin.groups.payables');
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

    /**
     * By our bill number, the vendor's own reference, or the vendor.
     *
     * Every path ends in `search_text` on purpose — see
     * App\Filament\Concerns\SearchesNormalizedText.
     *
     * @return array<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return [
            'search_text',
            'vendor.search_text',
        ];
    }
    /**
     * Context under the title. A bare reference does not tell an operator whether the
     * row in front of them is the one they were hunting for.
     *
     * @param  VendorBill  $record  Narrowed from Filament's Model signature so static analysis
     *                    can see the columns — the alternative was ten baseline entries.
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var \App\Models\Vendor|null $vendor */
        $vendor = $record->vendor;

        return [
            __('admin.fields.vendor') => $vendor?->name,
            __('admin.fields.total') => 'EGP '.number_format((float) $record->total, 2),
            __('admin.fields.bill_date') => $record->bill_date->format('d/m/Y'),
        ];
    }

    /**
     * Eager-load exactly what getGlobalSearchResultDetails() reaches for. Without this
     * the details above fire one query per row, per keystroke, on top of the search.
     */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['vendor']);
    }

}
