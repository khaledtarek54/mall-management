<?php

namespace App\Filament\Admin\Resources\PaymentMethods;

use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\PaymentMethods\Pages\CreatePaymentMethod;
use App\Filament\Admin\Resources\PaymentMethods\Pages\EditPaymentMethod;
use App\Filament\Admin\Resources\PaymentMethods\Pages\ListPaymentMethods;
use App\Filament\Admin\Resources\PaymentMethods\Schemas\PaymentMethodForm;
use App\Filament\Admin\Resources\PaymentMethods\Tables\PaymentMethodsTable;
use App\Models\PaymentMethod;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * قنوات السداد — the ways money moves in and out, and where each one lands in the books.
 *
 * The screen is the whole point of the change. A rail used to be a PHP `const`, so adding Fawry was
 * a deploy across 9–14 files; it is now a row anyone with `payment_methods.create` can add. A
 * catalogue with no screen would be the same constant with extra steps — the project has shipped
 * that mistake before, which is what `App\Support\ServiceReachability` exists to catch.
 *
 * **Operator-level, not per property** (`#[PortfolioShared]`): Eltizam banks the same way at every
 * mall it runs, so there is no property picker here and nothing to scope.
 */
class PaymentMethodResource extends Resource
{
    // PORTFOLIO-SHARED, so it must opt OUT of the panel's tenancy. Filament scopes a resource by
    // asking the model for an `asset` relationship, and a shared catalogue has none — the list page
    // 500'd with a LogicException the moment a property was selected, which is every page load.
    use BypassesFilamentTenantAutoScope;
    use RoleGatedActions;

    protected static ?string $model = PaymentMethod::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?int $navigationSort = 12;

    protected static function permissionModule(): string
    {
        return 'payment_methods';
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.payment_methods.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.payment_methods.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.payment_methods.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.general_ledger');
    }

    public static function form(Schema $schema): Schema
    {
        return PaymentMethodForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PaymentMethodsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentMethods::route('/'),
            'create' => CreatePaymentMethod::route('/create'),
            'edit' => EditPaymentMethod::route('/{record}/edit'),
        ];
    }
}
