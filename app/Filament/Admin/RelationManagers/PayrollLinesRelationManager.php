<?php

namespace App\Filament\Admin\RelationManagers;

use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollLine;
use App\Services\PayslipPdfService;
use App\Support\TenantScope;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

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

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('employee'))
            ->columns([
                TextColumn::make('employee.name')
                    ->label(__('admin.payroll_lines.fields.employee'))
                    ->description(fn (PayrollLine $record) => $record->employee?->code)
                    ->weight('medium'),
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
                TextColumn::make('net')
                    ->label(__('admin.payroll_lines.fields.net'))
                    ->state(fn (PayrollLine $record) => $record->net)
                    ->money('EGP')
                    ->weight('bold')
                    ->color('success'),
            ])
            ->headerActions([
                Action::make('add_line')
                    ->label(__('admin.payroll_lines.add_line'))
                    ->icon('heroicon-o-user-plus')
                    ->color('primary')
                    ->visible(fn () => $this->runIsEditable())
                    ->authorize(fn () => auth()->user()?->can('payrolls.edit') ?? false)
                    ->schema([
                        \Filament\Forms\Components\Select::make('employee_id')
                            ->label(__('admin.payroll_lines.fields.employee'))
                            ->options(fn () => $this->employeeOptions())
                            ->required()
                            ->searchable()
                            ->native(false),
                        TextInput::make('gross')->label(__('admin.payroll_lines.fields.gross'))->numeric()->minValue(0)->required()->prefix('EGP'),
                        TextInput::make('salary_tax')->label(__('admin.payroll_lines.fields.salary_tax'))->numeric()->minValue(0)->default(0)->prefix('EGP'),
                        TextInput::make('social_insurance')->label(__('admin.payroll_lines.fields.social_insurance'))->numeric()->minValue(0)->default(0)->prefix('EGP'),
                    ])
                    ->action(function (array $data): void {
                        // Server-side re-checks: run still a draft, permission, and the
                        // employee is within the run's property scope (form-tamper guard).
                        abort_unless($this->runIsEditable(), 403);
                        $employee = $this->authorizedEmployee((int) $data['employee_id']);

                        /** @var Payroll $run */
                        $run = $this->getOwnerRecord();
                        $run->lines()->create([
                            'employee_id' => $employee->id,
                            'gross' => (float) $data['gross'],
                            'salary_tax' => (float) ($data['salary_tax'] ?? 0),
                            'social_insurance' => (float) ($data['social_insurance'] ?? 0),
                        ]);
                        // recomputeFromLines() runs via the PayrollLine saved hook.
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn () => $this->runIsEditable())
                    ->authorize(fn () => auth()->user()?->can('payrolls.edit') ?? false)
                    ->schema([
                        TextInput::make('gross')->label(__('admin.payroll_lines.fields.gross'))->numeric()->minValue(0)->required()->prefix('EGP'),
                        TextInput::make('salary_tax')->label(__('admin.payroll_lines.fields.salary_tax'))->numeric()->minValue(0)->default(0)->prefix('EGP'),
                        TextInput::make('social_insurance')->label(__('admin.payroll_lines.fields.social_insurance'))->numeric()->minValue(0)->default(0)->prefix('EGP'),
                    ])
                    ->before(fn () => abort_unless($this->runIsEditable(), 403)),
                DeleteAction::make()
                    ->visible(fn () => $this->runIsEditable())
                    ->before(fn () => abort_unless($this->runIsEditable(), 403)),
                Action::make('payslip')
                    ->label(__('admin.payroll_lines.payslip'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->authorize(fn () => auth()->user()?->can('payrolls.view') ?? false)
                    ->action(function (PayrollLine $record) {
                        abort_unless(auth()->user()?->can('payrolls.view') ?? false, 403);
                        $svc = app(PayslipPdfService::class);
                        $pdf = $svc->build($record);

                        return response()->streamDownload(
                            fn () => print ($pdf),
                            $svc->filename($record),
                            ['Content-Type' => 'application/pdf'],
                        );
                    }),
            ])
            ->defaultSort('id');
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
        return $this->employeeQuery()->orderBy('name')->pluck('name', 'id')->all();
    }

    /** Re-validate the submitted employee against the run's scope (form-tamper guard). */
    private function authorizedEmployee(int $employeeId): Employee
    {
        return $this->employeeQuery()->whereKey($employeeId)->firstOr(fn () => abort(403));
    }
}
