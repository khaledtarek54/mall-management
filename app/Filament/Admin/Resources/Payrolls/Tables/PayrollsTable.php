<?php

namespace App\Filament\Admin\Resources\Payrolls\Tables;

use App\Filament\Admin\Resources\Payrolls\PayrollResource;
use App\Models\Payroll;
use App\Support\ReportCsv;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class PayrollsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label(__('admin.fields.payroll_number'))
                    ->fontFamily('mono')
                    ->size('xs')
                    ->copyable()
                    ->searchable(),
                TextColumn::make('asset.name')
                    ->label(__('admin.fields.property'))
                    ->placeholder(__('admin.fields.property_consolidated'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('period_month')
                    ->label(__('admin.fields.payroll_month'))
                    ->date('m/Y')
                    ->sortable(),
                TextColumn::make('gross_salaries')
                    ->label(__('admin.fields.gross_salaries'))
                    ->money('EGP')
                    ->alignRight(),
                TextColumn::make('salary_tax')
                    ->label(__('admin.fields.salary_tax'))
                    ->money('EGP')
                    ->alignRight()
                    ->toggleable(),
                TextColumn::make('social_insurance')
                    ->label(__('admin.fields.social_insurance'))
                    ->money('EGP')
                    ->alignRight()
                    ->toggleable(),
                TextColumn::make('net_paid')
                    ->label(__('admin.fields.net_paid'))
                    ->money('EGP')
                    ->weight('bold')
                    ->alignRight(),
                TextColumn::make('paid_from')
                    ->label(__('admin.fields.paid_from'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.enums.expense_paid_from.{$state}")),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.payroll.{$state}"))
                    ->color(fn (string $state) => match ($state) {
                        'approved' => 'success',
                        'cancelled' => 'gray',
                        default => 'warning',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.payroll')),
                SelectFilter::make('paid_from')
                    ->label(__('admin.fields.paid_from'))
                    ->options(fn () => __('admin.enums.expense_paid_from')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                // The per-run payroll register (muster roll) as a spreadsheet — every employee's
                // gross / withholdings / net + totals. Only when the run has per-employee lines
                // (a lump-sum run has nothing to break down).
                Action::make('export_register')
                    ->label(__('admin.reports.csv.export'))
                    ->icon('heroicon-o-table-cells')
                    ->color('gray')
                    ->visible(fn (Payroll $record) => PayrollResource::canView($record) && $record->lines()->exists())
                    ->authorize(fn (Payroll $record) => PayrollResource::canView($record))
                    ->action(function (Payroll $record) {
                        $csv = PayrollResource::registerCsv($record);

                        return ReportCsv::stream("payroll-register-{$record->number}", $csv['headers'], $csv['rows']);
                    }),
                EditAction::make()
                    ->visible(fn ($record) => PayrollResource::canEdit($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () => PayrollResource::canDeleteAny()),
                ]),
            ])
            ->defaultSort('period_month', 'desc')
            ->emptyStateIcon('heroicon-o-users')
            ->emptyStateHeading(__('admin.empty.payrolls.heading'))
            ->emptyStateDescription(__('admin.empty.payrolls.description'));
    }
}
