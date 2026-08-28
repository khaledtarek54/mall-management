<?php

namespace App\Filament\Admin\RelationManagers;

use App\Models\PayrollLine;
use App\Services\PayslipPdfService;
use App\Support\Filament\PdfDownloadAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * What this employee has actually been paid, month by month.
 *
 * **The question HR opens an employee to ask**, and it had no answer here: the record showed their
 * advances and nothing about their pay. The `payrollLines` relation existed on the model and no
 * screen used it, so "what did Mahmoud earn in June, and what was deducted?" meant opening each
 * payroll run in turn and finding his line — the same list-instead-of-record shape the lease page
 * had (2026-08-20).
 *
 * Read-only, deliberately. A payslip is a line on a RUN: its amounts are the run's totals, the run
 * freezes on approval, and editing one from here would be a second way to restate a posted payroll.
 * The payslip PDF is offered, because handing an employee their payslip is the reason to be here.
 */
class EmployeePayslipsRelationManager extends RelationManager
{
    protected static string $relationship = 'payrollLines';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.employees.payslips');
    }

    public function table(Table $table): Table
    {
        return $table
            // The run carries the period and the status; without it every row needs its own query.
            ->modifyQueryUsing(fn ($query) => $query->with('payroll'))
            // No search box: `TableDefaults` gives every table the folded-blob search and a
            // payroll LINE has no blob of its own — it is identified by its run and its
            // employee, both of which are the context you arrived from. A box that can never
            // match anything is worse than none. (Fixed alongside EG-08; the gate was red on
            // main after 1ae94b09 added this table.)
            ->searchable(false)
            ->columns([
                TextColumn::make('payroll.period_month')
                    ->label(__('admin.fields.period'))
                    ->date('m/Y')
                    ->sortable(),

                TextColumn::make('payroll.status')
                    ->label(__('admin.filters.status'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state ? __("admin.statuses.payroll.{$state}") : '—')
                    // A DRAFT payslip is a proposal, not pay. Saying which is which here matters more
                    // than on the run itself, because this is the screen someone reads to answer
                    // "was I paid?".
                    ->color(fn (?string $state) => match ($state) {
                        'approved' => 'success',
                        'cancelled' => 'danger',
                        default => 'warning',
                    }),

                TextColumn::make('gross')->label(__('admin.payroll_lines.fields.gross'))->money('EGP')->alignRight(),
                TextColumn::make('salary_tax')->label(__('admin.payroll_lines.fields.salary_tax'))->money('EGP')->alignRight()->toggleable(),
                TextColumn::make('social_insurance')->label(__('admin.payroll_lines.fields.social_insurance'))->money('EGP')->alignRight()->toggleable(),
                TextColumn::make('advance_deduction')
                    ->label(__('admin.payroll_lines.fields.advance_deduction'))
                    ->money('EGP')
                    ->alignRight()
                    ->placeholder('—')
                    // Which loan this installment repaid — the row is otherwise a deduction with no
                    // explanation, and "what is this 2,000?" is the question it exists to answer.
                    ->description(fn (PayrollLine $record): ?string => $record->employee_advance_id !== null
                        ? __('admin.employees.repays_advance')
                        : null),
                TextColumn::make('net')
                    ->label(__('admin.payroll_lines.fields.net'))
                    ->state(fn (PayrollLine $record): float => round(
                        (float) $record->gross - (float) $record->salary_tax
                            - (float) $record->social_insurance - (float) $record->advance_deduction
                            - (float) $record->other_deductions,
                        2,
                    ))
                    ->money('EGP')
                    ->alignRight()
                    ->weight('bold'),
            ])
            ->defaultSort('id', 'desc')
            ->recordActions([
                PdfDownloadAction::make('payslip')
                    ->label(__('admin.payroll_lines.payslip'))
                    ->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->service(PayslipPdfService::class)
                    ->recipient(fn (PayrollLine $record) => $record->employee)
                    ->authorize(fn (): bool => auth()->user()?->can('payrolls.view') ?? false),
            ])
            ->headerActions([])
            ->emptyStateIcon('heroicon-o-banknotes')
            ->emptyStateHeading(__('admin.employees.no_payslips'));
    }
}
