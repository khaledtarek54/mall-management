<?php

namespace App\Filament\Admin\Resources\PayrollRates;

use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\PayrollRates\Pages\CreatePayrollRate;
use App\Filament\Admin\Resources\PayrollRates\Pages\EditPayrollRate;
use App\Filament\Admin\Resources\PayrollRates\Pages\ListPayrollRates;
use App\Filament\Admin\Resources\PayrollRates\Schemas\PayrollRateForm;
use App\Filament\Admin\Resources\PayrollRates\Tables\PayrollRatesTable;
use App\Models\PayrollRate;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * نسب الرواتب — Egypt's statutory payroll numbers, as a dated ladder (EG-03).
 *
 * The screen is the point. These were three flat settings, so the accountant had to edit them by
 * hand every January, could not enter a rise in advance, and left no record of what a past run was
 * computed with — while the state moves the insurable-wage band on a January cadence. One row a
 * year, carrying what a decree carries: the band and the contribution rates together.
 *
 * **Operator-level, not per property** (`#[PortfolioShared]`): these are national figures.
 *
 * Gated on `payroll_rates.*` rather than on `payrolls.*`. Running a payroll and deciding what the
 * state's rates are is not the same authority — the second belongs with the accountant, and an HR
 * clerk who may generate a run should not be able to move the ceiling underneath it.
 */
class PayrollRateResource extends Resource
{
    // PORTFOLIO-SHARED, so it must opt OUT of the panel's tenancy. Filament scopes a resource by
    // asking the model for an `asset` relationship, and this catalogue has none — without this the
    // list page throws a LogicException the moment the table paginates, i.e. on every visit.
    use BypassesFilamentTenantAutoScope;
    use RoleGatedActions;

    protected static ?string $model = PayrollRate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalculator;

    protected static ?int $navigationSort = 32;

    protected static function permissionModule(): string
    {
        return 'payroll_rates';
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.payroll_rates_screen.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.payroll_rates_screen.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.payroll_rates_screen.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        // `hr_payroll`, the group the PANEL declares — the same one `PayrollResource` sits in.
        // `admin.groups.hr` resolves to a plausible "HR" and is a group `AdminPanelProvider` never
        // registers, so the screen filed itself outside the declared navigation order.
        return __('admin.groups.hr_payroll');
    }

    public static function form(Schema $schema): Schema
    {
        return PayrollRateForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PayrollRatesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayrollRates::route('/'),
            'create' => CreatePayrollRate::route('/create'),
            'edit' => EditPayrollRate::route('/{record}/edit'),
        ];
    }
}
