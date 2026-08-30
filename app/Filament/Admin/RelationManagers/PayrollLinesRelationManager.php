<?php

namespace App\Filament\Admin\RelationManagers;

use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\Payroll;
use App\Models\PayrollLine;
use App\Services\GeneratePayrollService;
use App\Services\PayslipPdfService;
use App\Support\Filament\PdfDownloadAction;
use App\Support\Filament\RecordChanged;
use App\Support\PayrollRates;
use App\Support\TenantScope;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Per-employee payroll lines (module 24, Phase 3). Add one line per employee to a
 * DRAFT run; the run header (gross / tax / insurance / net) derives from Σ lines, and
 * each line yields a bilingual payslip PDF. Lines are frozen once the run leaves draft
 * (its terms — and its GL entry — are settled). Gated on `payrolls.edit` (mutation) /
 * `payrolls.view` (payslip).
 */
class PayrollLinesRelationManager extends RelationManager
{
    protected static string $relationship = 'lines';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.payroll_lines.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('payrolls.view') ?? false;
    }

    /** Lines may only be changed while the run is a draft. */
    protected function runIsEditable(): bool
    {
        return $this->getOwnerRecord()->status === 'draft'
            && (auth()->user()?->can('payrolls.edit') ?? false);
    }

    /**
     * A line mutation re-derives the run header (Payroll::recomputeFromLines), but the
     * header fields render on the parent Edit page — a SEPARATE Livewire component that
     * won't know its record changed. Dispatch an event the page listens for so the
     * gross / tax / insurance / net totals update live, without a manual refresh.
     */
    private function refreshOwnerHeader(): void
    {
        RecordChanged::dispatchFrom($this);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('employee'))
            ->columns([
                TextColumn::make('employee.name')
                    ->label(__('admin.payroll_lines.fields.employee'))
                    ->description(fn (PayrollLine $record) => $record->employee?->code)
                    // Sortable so Filament builds the relation join itself. Without it a
                    // `defaultSort('employee.name')` falls through to a bare
                    // `order by employee.name` with nothing joined, which is a 500.
                    ->sortable()
                    ->weight('medium'),
                TextColumn::make('allowances')
                    ->label(__('admin.payroll_lines.fields.allowances'))
                    ->money('EGP')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('gross')
                    ->label(__('admin.payroll_lines.fields.gross'))
                    ->money('EGP'),
                TextColumn::make('salary_tax')
                    ->label(__('admin.payroll_lines.fields.salary_tax'))
                    ->money('EGP')
                    ->color('danger'),
                TextColumn::make('social_insurance')
                    ->label(__('admin.payroll_lines.fields.social_insurance'))
                    ->money('EGP')
                    ->color('danger'),
                TextColumn::make('advance_deduction')
                    ->label(__('admin.payroll_lines.fields.advance_deduction'))
                    ->money('EGP')
                    ->color('danger')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('other_deductions')
                    ->label(__('admin.payroll_lines.fields.other_deductions'))
                    ->money('EGP')
                    ->color('danger')
                    ->description(fn (PayrollLine $record) => $record->deduction_note)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('net')
                    ->label(__('admin.payroll_lines.fields.net'))
                    ->state(fn (PayrollLine $record) => $record->net)
                    ->money('EGP')
                    ->weight('bold')
                    ->color('success'),
                TextColumn::make('employer_social_insurance')
                    ->label(__('admin.payroll_lines.fields.employer_social_insurance'))
                    ->money('EGP')
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                // The Odoo-style "generate payslips" step: build one line per active
                // employee in the run's property, pre-filled from base salary + the
                // configured deduction rates. The lines are the review surface; the
                // header derives from Σ lines (GL untouched). Primary CTA — hand-adding
                // one employee at a time is the fallback.
                Action::make('generate_from_roster')
                    ->label(__('admin.payroll_lines.generate.label'))
                    ->icon('heroicon-o-sparkles')
                    ->color('primary')
                    ->visible(fn () => $this->runIsEditable())
                    ->authorize(fn () => auth()->user()?->can('payrolls.edit') ?? false)
                    ->requiresConfirmation()
                    ->modalHeading(__('admin.payroll_lines.generate.heading'))
                    ->modalDescription(function () {
                        /** @var Payroll $run */
                        $run = $this->getOwnerRecord();
                        $count = app(GeneratePayrollService::class)->eligibleCount($run);

                        if ($count === 0) {
                            return __('admin.payroll_lines.generate.none');
                        }

                        $line = __('admin.payroll_lines.generate.description', ['count' => $count]);

                        // **Say what it will deduct, before it deducts nothing.** Both rates default
                        // to 0 and every generated payslip is then gross = net — legally wrong in
                        // Egypt and completely silent, because a payslip with no tax line looks like
                        // a payslip. Measured on the seeded portfolio: 9 employees, both rates 0
                        // (2026-08-20). Stated rather than refused: a run with no deductions is a
                        // real case (a contractor roster, a mall whose accountant withholds
                        // centrally), so this is the operator's call to make knowingly.
                        // The rung for THIS run's month, not today's — the modal must quote the
                        // numbers the generate is about to use, and for a back-dated run those are
                        // not the current ones (EG-03).
                        $rates = PayrollRates::for($this->getOwnerRecord()->period_month);
                        $tax = $rates->salaryTaxRate;
                        $si = $rates->employeeSocialInsuranceRate;

                        return $tax > 0 || $si > 0
                            ? $line.' '.__('admin.payroll_lines.generate.rates', ['tax' => $tax, 'si' => $si])
                            : $line.' '.__('admin.payroll_lines.generate.no_rates');
                    })
                    ->modalSubmitActionLabel(__('admin.payroll_lines.generate.confirm'))
                    ->action(function (): void {
                        abort_unless($this->runIsEditable(), 403);

                        /** @var Payroll $run */
                        $run = $this->getOwnerRecord();

                        try {
                            $result = app(GeneratePayrollService::class)->generate($run);
                        } catch (\DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        if ($result['added'] === 0) {
                            Notification::make()
                                ->title(__('admin.payroll_lines.generate.nothing_added'))
                                ->warning()
                                ->send();

                            return;
                        }

                        $body = __('admin.payroll_lines.generate.added_body', ['count' => $result['added']]);
                        if ($result['zero_salary'] > 0) {
                            $body .= ' '.__('admin.payroll_lines.generate.zero_salary_note', ['count' => $result['zero_salary']]);
                        }

                        Notification::make()
                            ->title(__('admin.payroll_lines.generate.done'))
                            ->body($body)
                            ->success()
                            ->send();

                        // The derived header totals live on the parent Edit form (a
                        // separate Livewire component) — nudge it to re-pull, so they
                        // update live instead of only after a manual page refresh.
                        $this->refreshOwnerHeader();
                    }),

                Action::make('add_line')
                    ->label(__('admin.payroll_lines.add_line'))
                    ->icon('heroicon-o-user-plus')
                    ->color('gray')
                    ->visible(fn () => $this->runIsEditable())
                    ->authorize(fn () => auth()->user()?->can('payrolls.edit') ?? false)
                    ->schema([
                        Select::make('employee_id')
                            ->label(__('admin.payroll_lines.fields.employee'))
                            ->options(fn () => $this->employeeOptions())
                            ->required()
                            ->searchable()
                            ->native(false),
                        ...$this->moneyFields(),
                    ])
                    ->action(function (array $data): void {
                        // Server-side re-checks: run still a draft, permission, and the
                        // employee is within the run's property scope (form-tamper guard).
                        abort_unless($this->runIsEditable(), 403);
                        $employee = $this->authorizedEmployee((int) $data['employee_id']);

                        /** @var Payroll $run */
                        $run = $this->getOwnerRecord();
                        // One line per employee per run — reject a duplicate before the DB
                        // unique index would throw a raw 500 (the picker also hides them).
                        abort_if($run->lines()->where('employee_id', $employee->id)->exists(), 422);
                        $run->lines()->create([
                            'employee_id' => $employee->id,
                            'gross' => (float) $data['gross'],
                            'salary_tax' => (float) ($data['salary_tax'] ?? 0),
                            'social_insurance' => (float) ($data['social_insurance'] ?? 0),
                        ]);
                        // recomputeFromLines() runs via the PayrollLine saved hook.
                        $this->refreshOwnerHeader();
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn () => $this->runIsEditable())
                    // ONE `authorize()`, both conditions. `authorize()` ASSIGNS a single slot, so
                    // adding a second silently discarded the first — the run-is-editable half — and
                    // left a line on an APPROVED run editable by anyone holding `payrolls.edit`.
                    ->authorize(fn () => $this->runIsEditable() && (auth()->user()?->can('payrolls.edit') ?? false))
                    ->schema($this->moneyFields())
                    ->before(fn () => abort_unless($this->runIsEditable(), 403))
                    ->after(fn () => $this->refreshOwnerHeader()),
                DeleteAction::make()
                    // `authorize()` beside `visible()`: a relation manager has no resource for the
                    // authorization seam to ask, so this IS the gate. The `before()` below stays —
                    // it is the TOCTOU check for a run approved between render and click.
                    ->visible(fn () => $this->runIsEditable())
                    ->authorize(fn () => $this->runIsEditable())
                    ->before(fn () => abort_unless($this->runIsEditable(), 403))
                    ->after(fn () => $this->refreshOwnerHeader()),
                // Repay an advance/loan installment out of this payslip (Phase 4b). Shows only on a
                // draft run when the employee has an outstanding advance (or this line already has an
                // installment to edit/clear). The installment reduces net pay and — on approval —
                // the payroll GL entry credits Employee Advances, closing the سلف loop.
                Action::make('deduct_advance')
                    ->label(__('admin.payroll_lines.deduct_advance.label'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('warning')
                    ->visible(fn (PayrollLine $record) => $this->runIsEditable()
                        && ((float) $record->advance_deduction > 0 || $this->eligibleAdvances($record)->isNotEmpty()))
                    ->authorize(fn () => auth()->user()?->can('payrolls.edit') ?? false)
                    ->fillForm(fn (PayrollLine $record) => [
                        'employee_advance_id' => $record->employee_advance_id,
                        'advance_deduction' => (float) $record->advance_deduction,
                    ])
                    ->schema([
                        Select::make('employee_advance_id')
                            ->label(__('admin.payroll_lines.deduct_advance.advance'))
                            ->options(fn (PayrollLine $record) => $this->advanceOptions($record))
                            ->native(false)
                            ->helperText(__('admin.payroll_lines.deduct_advance.advance_helper')),
                        TextInput::make('advance_deduction')
                            ->label(__('admin.payroll_lines.deduct_advance.amount'))
                            ->numeric()->minValue(0)->default(0)->prefix('EGP')
                            ->helperText(__('admin.payroll_lines.deduct_advance.amount_helper')),
                    ])
                    ->action(function (PayrollLine $record, array $data): void {
                        abort_unless($this->runIsEditable(), 403);
                        $amount = round((float) ($data['advance_deduction'] ?? 0), 2);

                        if ($amount <= 0) {
                            // Clearing the installment.
                            $record->update(['advance_deduction' => 0, 'employee_advance_id' => null]);
                            $this->refreshOwnerHeader();

                            return;
                        }

                        // The advance must belong to THIS line's employee + be within scope (tamper guard).
                        $advance = $this->eligibleAdvances($record)
                            ->merge($record->employee_advance_id ? EmployeeAdvance::withTrashed()->whereKey($record->employee_advance_id)->get() : collect())
                            ->firstWhere('id', (int) ($data['employee_advance_id'] ?? 0));
                        if ($advance === null) {
                            Notification::make()->title(__('admin.payroll_lines.deduct_advance.advance_required'))->danger()->send();

                            return;
                        }

                        // Guard 1: can't repay more than the advance's outstanding.
                        if ($amount > $advance->outstanding() + 0.001) {
                            Notification::make()->title(__('admin.payroll_lines.errors.advance_over_repay', [
                                'outstanding' => number_format($advance->outstanding(), 2),
                            ]))->danger()->send();

                            return;
                        }
                        // Guard 2: the installment can't exceed take-home (net ≥ 0) — take-home
                        // is gross LESS the other deductions already on the line, not just tax + SI.
                        $takeHomeBeforeAdvance = round((float) $record->gross - (float) $record->salary_tax
                            - (float) $record->social_insurance - (float) $record->other_deductions, 2);
                        if ($amount > $takeHomeBeforeAdvance + 0.001) {
                            Notification::make()->title(__('admin.payroll_lines.errors.net_negative'))->danger()->send();

                            return;
                        }

                        try {
                            $record->update(['advance_deduction' => $amount, 'employee_advance_id' => $advance->id]);
                        } catch (\DomainException $e) {
                            // Backstop: the model re-checks net ≥ 0 — surface it as a toast, not a 500.
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }
                        $this->refreshOwnerHeader();
                    }),

                PdfDownloadAction::make('payslip')
                    ->label(__('admin.payroll_lines.payslip'))
                    ->service(PayslipPdfService::class)
                    // The recipient is a PERSON. An employee who reads only Arabic being handed an
                    // English breakdown of their own deductions is the plainest case for this.
                    ->recipient(fn (PayrollLine $record) => $record->employee)
                    ->authorize(fn () => auth()->user()?->can('payrolls.view') ?? false),
            ])
            // A→Z by employee. A payroll run is read to find one person's line, and insertion
            // order is not a thing anyone can search by eye.
            ->defaultSort('employee.name')
            ->emptyStateIcon('heroicon-o-user-group')
            ->emptyStateHeading(__('admin.payroll_lines.empty.heading'))
            ->emptyStateDescription(__('admin.payroll_lines.empty.description'));
    }

    /**
     * The three money inputs shared by add + edit. `social_insurance` carries the cross-field
     * guard: gross − tax − insurance must stay ≥ 0, so a line can't be saved with a negative
     * net (a payslip printing "Net −1,000"). The model enforces the same invariant as a
     * backstop; this validates it inline so the operator sees it on the field, not a 500.
     *
     * @return array<int, Field>
     */
    private function moneyFields(): array
    {
        // net = gross − tax − SI − advance installment − other deductions, must stay ≥ 0.
        // Computed purely from $get (which reflects the SUBMITTED values during validation), so
        // it's correct on whichever field the rule fires. The advance installment is set via its
        // own action but RETAINED on the line — lowering gross / raising a deduction here has to
        // account for it too, else the model's net-negative invariant throws uncaught. It's
        // carried in a read-only hidden field (filled from the record on edit, 0 on a new line).
        $netNegative = fn (Get $get): bool => (float) $get('gross')
            - (float) $get('salary_tax') - (float) $get('social_insurance')
            - (float) ($get('advance_deduction') ?? 0) - (float) ($get('other_deductions') ?? 0) < 0;
        $netRule = fn (Get $get) => function (string $attribute, $value, $fail) use ($get, $netNegative) {
            if ($netNegative($get)) {
                $fail(__('admin.payroll_lines.errors.net_negative'));
            }
        };

        return [
            Hidden::make('advance_deduction')->dehydrated(false),
            TextInput::make('gross')->label(__('admin.payroll_lines.fields.gross'))->helperText(__('admin.payroll_lines.fields.gross_helper'))->numeric()->minValue(0)->required()->prefix('EGP')
                ->rule($netRule), // lowering gross below the retained deductions drives net negative
            TextInput::make('allowances')->label(__('admin.payroll_lines.fields.allowances'))->helperText(__('admin.payroll_lines.fields.allowances_helper'))->numeric()->minValue(0)->default(0)->prefix('EGP')
                ->rule(function (Get $get) {
                    return function (string $attribute, $value, $fail) use ($get) {
                        if ((float) $value > (float) $get('gross')) {
                            $fail(__('admin.payroll_lines.errors.allowances_exceed_gross'));
                        }
                    };
                }),
            TextInput::make('salary_tax')->label(__('admin.payroll_lines.fields.salary_tax'))->numeric()->minValue(0)->default(0)->prefix('EGP'),
            TextInput::make('social_insurance')->label(__('admin.payroll_lines.fields.social_insurance'))->numeric()->minValue(0)->default(0)->prefix('EGP')
                ->rule($netRule),
            TextInput::make('other_deductions')->label(__('admin.payroll_lines.fields.other_deductions'))->helperText(__('admin.payroll_lines.fields.other_deductions_helper'))->numeric()->minValue(0)->default(0)->prefix('EGP')
                ->rule($netRule), // full net incl. the retained advance installment
            TextInput::make('deduction_note')->label(__('admin.payroll_lines.fields.deduction_note'))->maxLength(255)->placeholder(__('admin.payroll_lines.fields.deduction_note_placeholder')),
            TextInput::make('employer_social_insurance')->label(__('admin.payroll_lines.fields.employer_social_insurance'))->helperText(__('admin.payroll_lines.fields.employer_social_insurance_helper'))->numeric()->minValue(0)->default(0)->prefix('EGP'),
        ];
    }

    /** Employees selectable for this run: its property (or the user's visible set if consolidated). */
    private function employeeQuery()
    {
        /** @var Payroll $run */
        $run = $this->getOwnerRecord();
        $query = Employee::query();

        if ($run->asset_id) {
            $query->where('asset_id', $run->asset_id);
        } elseif (($ids = TenantScope::visibleAssetIds()) !== null) {
            $query->whereIn('asset_id', $ids);
        }

        return $query;
    }

    /** @return array<int, string> */
    private function employeeOptions(): array
    {
        // Exclude employees already on a line for this run (one line per employee).
        $taken = $this->getOwnerRecord()->lines()->pluck('employee_id')->all();

        return $this->employeeQuery()
            ->when($taken, fn ($q) => $q->whereNotIn('id', $taken))
            ->orderBy('name')->pluck('name', 'id')->all();
    }

    /** Re-validate the submitted employee against the run's scope (form-tamper guard). */
    private function authorizedEmployee(int $employeeId): Employee
    {
        return $this->employeeQuery()->whereKey($employeeId)->firstOr(fn () => abort(403));
    }

    /**
     * The advances this line could repay: THIS line's employee's advances with an outstanding
     * balance, and within the run's property scope (an advance's asset_id is denormalised from
     * the employee, so this also blocks repaying an out-of-scope loan). `outstanding()` isn't a
     * column, so filter in PHP — an employee has only a handful of advances.
     *
     * @return Collection<int, EmployeeAdvance>
     */
    private function eligibleAdvances(PayrollLine $record): Collection
    {
        if (! $record->employee_id) {
            return collect();
        }

        $query = EmployeeAdvance::where('employee_id', $record->employee_id);
        /** @var Payroll $run */
        $run = $this->getOwnerRecord();
        if ($run->asset_id) {
            $query->where('asset_id', $run->asset_id);
        } elseif (($ids = TenantScope::visibleAssetIds()) !== null) {
            $query->whereIn('asset_id', $ids);
        }

        return $query->orderByDesc('advance_date')->get()
            ->filter(fn (EmployeeAdvance $a) => $a->outstanding() > 0)
            ->values();
    }

    /** @return array<int, string> */
    private function advanceOptions(PayrollLine $record): array
    {
        $advances = $this->eligibleAdvances($record);

        // Keep the currently-linked advance selectable even if it no longer has headroom.
        if ($record->employee_advance_id && ! $advances->contains('id', $record->employee_advance_id)) {
            if ($linked = EmployeeAdvance::withTrashed()->find($record->employee_advance_id)) {
                $advances = $advances->push($linked);
            }
        }

        return $advances->mapWithKeys(fn (EmployeeAdvance $a) => [
            $a->id => __("admin.employees.types.{$a->type}").' · '.$a->advance_date->format('d/m/Y')
                .' · '.__('admin.employees.advance_fields.outstanding').' EGP '.number_format($a->outstanding(), 2),
        ])->all();
    }
}
