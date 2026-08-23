<?php

namespace App\Filament\Admin\Resources\FailureCodes;

use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\FailureCodes\Pages\CreateFailureCode;
use App\Filament\Admin\Resources\FailureCodes\Pages\EditFailureCode;
use App\Filament\Admin\Resources\FailureCodes\Pages\ListFailureCodes;
use App\Filament\Admin\Resources\FailureCodes\Schemas\FailureCodeForm;
use App\Filament\Admin\Resources\FailureCodes\Tables\FailureCodesTable;
use App\Models\FailureCode;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * أكواد الأعطال — the failure-code library.
 *
 * Portfolio-level master data, like the trade register: a refrigerant leak is a refrigerant leak in
 * every mall. No property field and no `ScopesToProperty`.
 *
 * The panel has tenancy configured, so Filament scopes every resource through an `asset`
 * relationship unless told otherwise — and a portfolio-shared register has none, which 500s the
 * list page. Same opt-out the trade and charge-code registers use.
 */
class FailureCodeResource extends Resource
{
    use BypassesFilamentTenantAutoScope;
    use RoleGatedActions;

    protected static ?string $model = FailureCode::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static function permissionModule(): string
    {
        return 'failure_codes';
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.facility.failure_code.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.facility.failure_code.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.facility.failure_code.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return FailureCodeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FailureCodesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFailureCodes::route('/'),
            'create' => CreateFailureCode::route('/create'),
            'edit' => EditFailureCode::route('/{record}/edit'),
        ];
    }
}
