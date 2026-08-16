<?php

namespace App\Filament\Portal\Resources\Invoices;

use App\Filament\Concerns\SearchesNormalizedText;
use App\Filament\Portal\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Portal\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Portal\Resources\Invoices\Schemas\InvoiceInfolist;
use App\Filament\Portal\Resources\Invoices\Tables\InvoicesTable;
use App\Models\Invoice;
use App\Support\Portal;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InvoiceResource extends Resource
{
    use SearchesNormalizedText;

    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'number';

    /**
     * An invoice is hunted by its number, but just as often by who it is for or which unit it billed.
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
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.invoices');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.invoice.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.invoice.plural');
    }

    public static function infolist(Schema $schema): Schema
    {
        return InvoiceInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InvoicesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
            'view' => ViewInvoice::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->visibleToTenant()
            ->where('tenant_id', Portal::tenantId());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
