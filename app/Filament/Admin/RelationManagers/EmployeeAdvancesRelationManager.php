<?php

namespace App\Filament\Admin\RelationManagers;

use App\Models\Employee;
use App\Models\EmployeeAdvance;
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
            ->modifyQueryUsing(fn ($query) => $query->with('createdBy')->withSum('repayments as repaid_sum', 'amount'))
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
                TextColumn::make('repaid_sum')
                    ->label(__('admin.employees.advance_fields.repaid'))
                    ->money('EGP')
                    ->default(0)
                    ->color('success'),
                TextColumn::make('outstanding')
                    ->label(__('admin.employees.advance_fields.outstanding'))
                    // amount − repaid (derived from the withSum alias — no N+1).
                    ->state(fn (EmployeeAdvance $record) => round(max(0, (float) $record->amount - (float) ($record->repaid_sum ?? 0)), 2))
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
                            ->options(['cash' => __('admin.employees.methods.cash'), 'bank' => __('admin.employees.methods.bank')])
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
                            ->options(['cash' => __('admin.employees.methods.cash'), 'bank' => __('admin.employees.methods.bank')])
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
            ])
            ->defaultSort('advance_date', 'desc');
    }
}
