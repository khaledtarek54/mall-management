<?php

namespace App\Filament\Admin\Resources\Payments;

use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Payments\Pages\CreatePayment;
use App\Filament\Admin\Resources\Payments\Pages\EditPayment;
use App\Filament\Admin\Resources\Payments\Pages\ListPayments;
use App\Filament\Admin\Resources\Payments\Schemas\PaymentForm;
use App\Filament\Admin\Resources\Payments\Tables\PaymentsTable;
use App\Models\Payment;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class PaymentResource extends Resource
{
    use RoleGatedActions;

    protected static ?string $model = Payment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'reference';

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

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.billing');
    }

    public static function form(Schema $schema): Schema
    {
        return PaymentForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaymentsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Admin\RelationManagers\ActivitiesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayments::route('/'),
            'create' => CreatePayment::route('/create'),
            'edit' => EditPayment::route('/{record}/edit'),
        ];
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([
                SoftDeletingScope::class,
            ]);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['reference', 'tenant.name'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            __('admin.tables.payment.tenant') => $record->tenant?->name,
            __('admin.tables.payment.amount') => 'EGP ' . number_format((float) $record->amount, 2),
            __('admin.tables.payment.date') => $record->payment_date?->format('d/m/Y'),
            __('admin.tables.payment.method') => __("admin.enums.method.{$record->method}"),
        ];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with('tenant');
    }
}
