<?php

namespace App\Filament\Admin\Resources\Payrolls;

use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Payrolls\Pages\CreatePayroll;
use App\Filament\Admin\Resources\Payrolls\Pages\EditPayroll;
use App\Filament\Admin\Resources\Payrolls\Pages\ListPayrolls;
use App\Filament\Admin\Resources\Payrolls\Schemas\PayrollForm;
use App\Filament\Admin\Resources\Payrolls\Tables\PayrollsTable;
use App\Models\Payroll;
use App\Models\PayrollLine;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * مسير رواتب — monthly payroll runs. Each approved run recognises the salary
 * expense + its statutory withholdings on the GL. Scoped by the run's `asset_id`
 * dimension, always also showing consolidated (null-asset) company-level runs.
 */
class PayrollResource extends Resource
{
    use BypassesFilamentTenantAutoScope;
    use GuardsAssetInScope;
    use RoleGatedActions;

    protected static ?string $model = Payroll::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = 29;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.payrolls');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.payroll.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.payroll.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.accounting');
    }

    public static function form(Schema $schema): Schema
    {
        return PayrollForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PayrollsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Admin\RelationManagers\PayrollLinesRelationManager::class,
            \App\Filament\Admin\RelationManagers\ActivitiesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayrolls::route('/'),
            'create' => CreatePayroll::route('/create'),
            'edit' => EditPayroll::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if ($assetId = \App\Support\TenantScope::currentAssetId()) {
            // Property-level runs for this asset OR consolidated company runs.
            $query->where(fn ($q) => $q->where('asset_id', $assetId)->orWhereNull('asset_id'));
        } elseif (($ids = \App\Support\TenantScope::visibleAssetIds()) !== null) {
            $query->where(fn ($q) => $q->whereIn('asset_id', $ids)->orWhereNull('asset_id'));
        }

        return $query;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['number'];
    }

    /**
     * The payroll register for one run as CSV rows — every employee's gross, statutory
     * withholdings and net pay, the consolidated muster roll HR/finance works each month
     * (the per-employee payslips are the same figures one PDF at a time). Reads the run's
     * lines (employee `withTrashed` so a frozen run stays reproducible after staff turnover)
     * and closes with gross / tax / insurance / net totals that tie to the run header.
     *
     * @return array{headers: array<int,string>, rows: array<int, array<int, string|float>>}
     */
    public static function registerCsv(Payroll $run): array
    {
        $rows = [];
        $totalBasic = 0.0;
        $totalAllowances = 0.0;
        $totalGross = 0.0;
        $totalTax = 0.0;
        $totalInsurance = 0.0;
        $totalAdvance = 0.0;
        $totalOther = 0.0;
        $totalNet = 0.0;
        $totalEmployerInsurance = 0.0;

        /** @var PayrollLine $line */
        foreach ($run->lines()->with('employee')->get() as $line) {
            $gross = round((float) $line->gross, 2);
            $allowances = round((float) $line->allowances, 2);
            $basic = round($gross - $allowances, 2);
            $tax = round((float) $line->salary_tax, 2);
            $insurance = round((float) $line->social_insurance, 2);
            $advance = round((float) $line->advance_deduction, 2);
            $other = round((float) $line->other_deductions, 2);
            $net = round((float) $line->net, 2);
            $employerInsurance = round((float) $line->employer_social_insurance, 2);
            $totalBasic += $basic;
            $totalAllowances += $allowances;
            $totalGross += $gross;
            $totalTax += $tax;
            $totalInsurance += $insurance;
            $totalAdvance += $advance;
            $totalOther += $other;
            $totalNet += $net;
            $totalEmployerInsurance += $employerInsurance;

            $rows[] = [
                (string) data_get($line, 'employee.code', ''),
                (string) data_get($line, 'employee.name', ''),
                (string) data_get($line, 'employee.position', ''),
                $basic, $allowances, $gross, $tax, $insurance, $advance, $other, $net, $employerInsurance,
            ];
        }

        $rows[] = ['', __('admin.reports.csv.total'), '',
            round($totalBasic, 2), round($totalAllowances, 2), round($totalGross, 2),
            round($totalTax, 2), round($totalInsurance, 2), round($totalAdvance, 2), round($totalOther, 2),
            round($totalNet, 2), round($totalEmployerInsurance, 2)];

        return [
            'headers' => [
                __('admin.employees.fields.code'), __('admin.employees.fields.name'),
                __('admin.employees.fields.position'),
                __('admin.payroll_lines.fields.basic'), __('admin.payroll_lines.fields.allowances'),
                __('admin.payroll_lines.fields.gross'),
                __('admin.payroll_lines.fields.salary_tax'), __('admin.payroll_lines.fields.social_insurance'),
                __('admin.payroll_lines.fields.advance_deduction'), __('admin.payroll_lines.fields.other_deductions'),
                __('admin.payroll_lines.fields.net'), __('admin.payroll_lines.fields.employer_social_insurance'),
            ],
            'rows' => $rows,
        ];
    }
}
