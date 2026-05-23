<?php

namespace App\Filament\Owner\Resources\Invoices;

use App\Filament\Owner\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Owner\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Owner\Resources\Invoices\Tables\InvoicesTable;
use App\Models\Invoice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 2;

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

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.portfolio');
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
        $userId = Auth::id();

        return parent::getEloquentQuery()
            ->with(['tenant', 'lease.unit.asset'])
            ->whereHas('lease.unit.asset.owners', fn ($q) => $q->where('user_id', $userId));
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
