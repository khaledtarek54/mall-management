<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Actions\ReversalReasonField;
use App\Filament\Actions\ReverseDocumentAction;
use App\Models\Employee;
use App\Models\EmployeeAdvance;
use App\Models\EmployeeAdvanceRepayment;
use App\Models\PaymentMethod;
use App\Services\GrantEmployeeAdvanceService;
use App\Services\RecordAdvanceRepaymentService;
use App\Support\Modules;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Employee advances & loans (سلف — module 24, Phase 2). Grant an advance (money out →
 * GL Dr Employee Advances / Cr Cash|Bank) and record repayments (Dr Cash|Bank / Cr
 * Employee Advances). Outstanding is DERIVED (amount − Σ repayments) via a withSum
 * subquery. Gated on `employees.grant_advance` / `employees.record_repayment`.
 */
class EmployeeAdvancesRelationManager extends RelationManager
{
    protected static string $relationship = 'advances';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.employees.advances');
    }

    /** Only when the employees module is on AND the user may view employees. */
    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Modules::enabled('employees') && (auth()->user()?->can('employees.view') ?? false);
    }

    public function table(Table $table): Table
    {
        return $table
            // No search box: EmployeeAdvance carries no `search_text` blob (it is not a
            // record anyone hunts for by name) and this table marks no column
            // searchable. Without this, TableDefaults' blob search would still render
            // the box — and a search box that always returns nothing is worse than
            // none, because it reads as "no such row". See App\Support\SearchPolicy.
            ->searchable(false)
            ->modifyQueryUsing(fn ($query) => $query->with('createdBy'))
            ->columns([
                TextColumn::make('advance_date')
                    ->label(__('admin.employees.advance_fields.advance_date'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('type')
                    ->label(__('admin.employees.advance_fields.type'))
                    ->formatStateUsing(fn (string $state) => __("admin.employees.types.$state"))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('amount')
                    ->label(__('admin.employees.advance_fields.amount'))
                    ->money('EGP'),
                TextColumn::make('repaid')
                    ->label(__('admin.employees.advance_fields.repaid'))
                    // Total repaid = amount − outstanding = cash repayments + approved payroll
                    // installments (Phase 4b), so repaid + outstanding always ties to amount.
                    ->state(fn (EmployeeAdvance $record) => round((float) $record->amount - $record->outstanding(), 2))
                    ->money('EGP')
                    ->color('success'),
                TextColumn::make('outstanding')
                    ->label(__('admin.employees.advance_fields.outstanding'))
                    // amount − cash repayments − approved payroll installments (the accessor).
                    ->state(fn (EmployeeAdvance $record) => $record->outstanding())
                    ->money('EGP')
                    ->weight('bold')
                    ->color(fn ($state) => (float) $state > 0 ? 'warning' : 'gray'),
                TextColumn::make('createdBy.name')
                    ->label(__('admin.employees.advance_fields.granted_by'))
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->headerActions([
                Action::make('grant_advance')
                    ->label(__('admin.employees.actions.grant_advance'))
                    ->icon('heroicon-o-banknotes')
                    ->color('warning')
                    ->visible(fn (RelationManager $livewire) => (auth()->user()?->can('employees.grant_advance') ?? false)
                        && $livewire->getOwnerRecord()->status === 'active')
                    ->authorize(fn () => auth()->user()?->can('employees.grant_advance') ?? false)
                    ->schema([
                        Select::make('type')
                            ->label(__('admin.employees.advance_fields.type'))
                            ->options(['advance' => __('admin.employees.types.advance'), 'loan' => __('admin.employees.types.loan')])
                            ->default('advance')
                            ->required()
                            ->native(false),
                        TextInput::make('amount')
                            ->label(__('admin.employees.advance_fields.amount'))
                            ->numeric()
                            ->minValue(0.01)
                            ->required()
                            ->prefix('EGP'),
                        DatePicker::make('advance_date')
                            ->label(__('admin.employees.advance_fields.advance_date'))
                            ->default(now())
                            ->required()
                            ->native(false),
                        Select::make('paid_from')
                            ->label(__('admin.employees.advance_fields.paid_from'))
                            // The catalogue, not a two-entry literal. OUTBOUND: granting an advance
                            // pays the employee, so it is the same direction as an expense.
                            ->options(fn () => PaymentMethod::optionsFor('employee_advances.paid_from', 'admin.employees.methods'))
                            ->default('cash')
                            ->required()
                            ->native(false),
                        Textarea::make('notes')->label(__('admin.employees.fields.notes'))->rows(2)->columnSpanFull(),
                    ])
                    ->action(function (array $data, RelationManager $livewire): void {
                        /** @var Employee $employee */
                        $employee = $livewire->getOwnerRecord();
                        // Server-side re-check (authz can't see the employee's terminal state).
                        abort_unless((auth()->user()?->can('employees.grant_advance') ?? false) && $employee->status === 'active', 403);
                        app(GrantEmployeeAdvanceService::class)->grant($employee, $data);
                        Notification::make()->title(__('admin.employees.granted'))->success()->send();
                    }),
            ])
            ->recordActions([
                // An advance recorded in error. Distinct from a REPAYMENT (below), which is the
                // employee giving the money back and is its own dated GL source. Refused once any
                // repayment exists, for the same reason custody is: those rows post on their own and
                // reversing the grant underneath them would strand their entries.
                ReverseDocumentAction::make(
                    can: fn () => auth()->user()?->can('employees.record_repayment') ?? false,
                    label: 'admin.actions.reverse_advance',
                    confirm: 'admin.actions.reverse_advance_confirm',
                    done: 'admin.notifications.advance_reversed',
                    when: fn (EmployeeAdvance $record) => ! $record->repayments()->exists(),
                ),
                Action::make('record_repayment')
                    ->label(__('admin.employees.actions.record_repayment'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('success')
                    ->visible(fn (EmployeeAdvance $record) => (auth()->user()?->can('employees.record_repayment') ?? false)
                        && $record->outstanding() > 0)
                    ->authorize(fn () => auth()->user()?->can('employees.record_repayment') ?? false)
                    ->schema([
                        TextInput::make('amount')
                            ->label(__('admin.employees.advance_fields.amount'))
                            ->numeric()
                            ->minValue(0.01)
                            // Can't repay more than what's outstanding.
                            ->maxValue(fn (EmployeeAdvance $record) => $record->outstanding())
                            ->default(fn (EmployeeAdvance $record) => $record->outstanding())
                            ->required()
                            ->prefix('EGP'),
                        DatePicker::make('repaid_on')
                            ->label(__('admin.employees.advance_fields.repaid_on'))
                            // UX only — RecordAdvanceRepaymentService is the gate (it also
                            // refuses a date in a closed accounting period, which a picker
                            // cannot express).
                            ->minDate(fn (EmployeeAdvance $record) => $record->advance_date)
                            ->maxDate(now())
                            ->default(now())
                            ->required()
                            ->native(false),
                        Select::make('method')
                            ->label(__('admin.employees.advance_fields.method'))
                            // The catalogue, not a two-entry literal: an operator who activates
                            // InstaPay can take a deposit by it and could not record a repayment by
                            // it. Inbound — the employee is paying the operator back.
                            ->options(fn () => PaymentMethod::optionsFor('employee_advance_repayments.method', 'admin.employees.methods'))
                            ->default('cash')
                            ->required()
                            ->native(false),
                        Textarea::make('notes')->label(__('admin.employees.fields.notes'))->rows(2)->columnSpanFull(),
                    ])
                    ->action(function (array $data, EmployeeAdvance $record): void {
                        abort_unless(auth()->user()?->can('employees.record_repayment') ?? false, 403);

                        try {
                            // The service re-checks outstanding under a lock (over-repayment → 422)
                            // and refuses a date that cannot carry a GL posting.
                            app(RecordAdvanceRepaymentService::class)->record($record, $data);
                        } catch (\DomainException $e) {
                            // A refused date (closed period / before the advance / future) is an
                            // expected outcome, not a fault — show it, don't 500.
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title(__('admin.employees.repaid_notice'))->success()->send();
                    }),

                // The correction path repayments were missing (gap-analysis F-91). A mis-keyed
                // repayment (5,000 for 500) left outstanding wrong and cash overstated, unfixable
                // short of deleting the whole advance. Reversing soft-deletes the chosen
                // repayment: outstanding recomputes and its GL entry voids, reason kept on the
                // retained row. Gated in BOTH visible() and action() — visible() is not a
                // dispatch gate (mountAction checks isDisabled(), never isVisible()). Whoever may
                // record a repayment may correct one.
                Action::make('reverse_repayment')
                    ->label(__('admin.employees.actions.reverse_repayment'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->visible(fn (EmployeeAdvance $record) => (auth()->user()?->can('employees.record_repayment') ?? false)
                        && $record->repayments()->exists())
                    ->authorize(fn () => auth()->user()?->can('employees.record_repayment') ?? false)
                    ->requiresConfirmation()
                    ->modalHeading(__('admin.employees.actions.reverse_repayment'))
                    ->modalDescription(__('admin.employees.reverse_repayment_modal_description'))
                    ->schema([
                        Select::make('repayment_id')
                            ->label(__('admin.employees.reverse_which_repayment'))
                            ->options(fn (EmployeeAdvance $record) => EmployeeAdvanceRepayment::query()
                                ->where('employee_advance_id', $record->getKey()) // SoftDeletes excludes reversed
                                ->orderByDesc('repaid_on')->orderByDesc('id')->get()
                                ->mapWithKeys(fn (EmployeeAdvanceRepayment $r) => [$r->id => $r->repaid_on->format('d/m/Y')
                                    .' — EGP '.number_format((float) $r->amount, 2)
                                    .' ('.__("admin.employees.methods.{$r->method}").')'])
                                ->all())
                            ->required()
                            ->native(false),
                        ReversalReasonField::make()
                            ->label(__('admin.employees.reverse_reason'))
                            ->rows(2)
                            ->columnSpanFull(),
                    ])
                    ->action(function (EmployeeAdvance $record, array $data): void {
                        abort_unless(auth()->user()?->can('employees.record_repayment') ?? false, 403);

                        // Resolve the chosen repayment scoped to THIS advance (form-tamper guard).
                        /** @var EmployeeAdvanceRepayment|null $repayment */
                        $repayment = $record->repayments()->whereKey($data['repayment_id'])->first();
                        if ($repayment === null) {
                            Notification::make()->title(__('admin.employees.errors.repayment_already_reversed'))->danger()->send();

                            return;
                        }

                        try {
                            app(RecordAdvanceRepaymentService::class)->reverse($repayment, $data['reason']);
                        } catch (\DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title(__('admin.employees.repayment_reversed'))->success()->send();
                    }),
            ])
            ->defaultSort('advance_date', 'desc');
    }
}
