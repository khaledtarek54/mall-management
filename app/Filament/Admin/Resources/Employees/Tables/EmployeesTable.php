<?php

namespace App\Filament\Admin\Resources\Employees\Tables;

use App\Filament\Admin\Resources\Employees\EmployeeResource;
use App\Models\Employee;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class EmployeesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['department', 'asset']))
            ->columns([
                TextColumn::make('code')
                    ->label(__('admin.employees.fields.code'))
                    ->fontFamily('mono')
                    ->searchable(),
                TextColumn::make('name')
                    ->label(__('admin.employees.fields.name'))
                    ->weight('bold')
                    ->description(fn (Employee $record) => $record->position)
                    ->searchable(),
                TextColumn::make('department.name')
                    ->label(__('admin.employees.fields.department'))
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
                TextColumn::make('asset.name')
                    ->label(__('admin.employees.fields.property'))
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('base_salary')
                    ->label(__('admin.employees.fields.base_salary'))
                    ->money('EGP')
                    ->sortable()
                    // Monthly base payroll cost for whatever the filters select — per
                    // department, per property, active-only. Nowhere on screen before.
                    ->summarize(Sum::make('total')->label(__('admin.employees.fields.total_base_salary'))->money('EGP')),
                TextColumn::make('payment_method')
                    ->label(__('admin.employees.fields.payment_method'))
                    ->formatStateUsing(fn (string $state) => __("admin.employees.methods.$state"))
                    ->badge()
                    ->color('info')
                    ->toggleable(),
                TextColumn::make('hire_date')
                    ->label(__('admin.employees.fields.hire_date'))
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->label(__('admin.employees.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.employees.statuses.$state"))
                    ->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->filters([
                // Not defaulted — same reasoning as the fixed-asset register: a filter
                // that hides rows on first load makes headcounts quietly wrong for
                // anyone who doesn't notice the chip.
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn (): array => __('admin.employees.statuses')),
                SelectFilter::make('department_id')
                    ->label(__('admin.employees.fields.department'))
                    ->relationship('department', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('payment_method')
                    ->label(__('admin.employees.fields.payment_method'))
                    ->options(fn (): array => __('admin.employees.methods')),
                Filter::make('hire_date')
                    ->schema([
                        DatePicker::make('from')->label(__('admin.filters.date_from'))->native(false),
                        DatePicker::make('until')->label(__('admin.filters.date_until'))->native(false),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('hire_date', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('hire_date', '<=', $d))),
                TrashedFilter::make(),
            ])
            ->recordActions([
                Action::make('terminate')
                    ->label(__('admin.employees.actions.terminate'))
                    ->icon('heroicon-o-user-minus')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Employee $record) => $record->status === 'active' && EmployeeResource::canEdit($record))
                    ->authorize(fn (Employee $record) => EmployeeResource::canEdit($record))
                    ->schema([
                        DatePicker::make('terminated_on')
                            ->label(__('admin.employees.fields.terminated_on'))
                            ->default(now())
                            ->required()
                            ->native(false),
                    ])
                    ->action(function (array $data, Employee $record): void {
                        // Server-side re-check (authz can't see form tampering of a terminal flip).
                        abort_unless(EmployeeResource::canEdit($record) && $record->status === 'active', 403);
                        $record->update(['status' => 'terminated', 'terminated_on' => $data['terminated_on']]);
                        Notification::make()->title(__('admin.employees.terminated'))->success()->send();
                    }),
                EditAction::make()->visible(fn (Employee $record) => EmployeeResource::canEdit($record)),
            ])
            ->emptyStateIcon('heroicon-o-identification')
            ->emptyStateHeading(__('admin.empty.employees.heading'))
            ->emptyStateDescription(__('admin.empty.employees.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('admin.empty.employees.cta'))
                    ->icon('heroicon-o-plus'),
            ])
            ->defaultSort('name');
    }
}
