<?php

namespace App\Filament\Portal\Resources\Payments;

use App\Filament\Concerns\SearchesNormalizedText;
use App\Filament\Portal\Resources\Payments\Pages\ListPayments;
use App\Filament\Portal\Resources\Payments\Pages\ViewPayment;
use App\Filament\Portal\Resources\Payments\Schemas\PaymentInfolist;
use App\Filament\Portal\Resources\Payments\Tables\PaymentsTable;
use App\Models\Payment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class PaymentResource extends Resource
{
    use SearchesNormalizedText;

    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'reference';

    /**
     * By receipt reference, by the cheque or gateway id on the bank statement, or by who paid.
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
        return __('admin.navigation.payments');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.payment.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.payment.plural');
    }

    public static function infolist(Schema $schema): Schema
    {
        return PaymentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaymentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayments::route('/'),
            'view' => ViewPayment::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('tenant_id', \App\Support\Portal::tenantId());
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
