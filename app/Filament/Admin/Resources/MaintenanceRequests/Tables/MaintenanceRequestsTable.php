<?php

namespace App\Filament\Admin\Resources\MaintenanceRequests\Tables;

use App\Filament\Admin\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Models\MaintenanceRequest;
use App\Models\User;
use App\Services\MaintenanceRequestService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class MaintenanceRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['tenant', 'unit', 'assignee']))
            ->columns([
                TextColumn::make('reference')
                    ->label(__('admin.tables.maintenance.reference'))
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->size('xs'),
                TextColumn::make('title')
                    ->label(__('admin.tables.maintenance.title'))
                    ->searchable()
                    ->limit(40)
                    ->weight('medium'),
                TextColumn::make('tenant.name')
                    ->label(__('admin.tables.maintenance.tenant'))
                    ->searchable(),
                TextColumn::make('unit.code')
                    ->label(__('admin.tables.maintenance.unit'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('category')
                    ->label(__('admin.tables.maintenance.category'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state) => __("admin.enums.maintenance_category.{$state}")),
                TextColumn::make('priority')
                    ->label(__('admin.tables.maintenance.priority'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.enums.maintenance_priority.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'urgent' => 'danger',
                        'high' => 'warning',
                        'medium' => 'info',
                        'low' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.maintenance_request.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'submitted' => 'info',
                        'acknowledged' => 'warning',
                        'in_progress' => 'primary',
                        'awaiting_tenant' => 'warning',
                        'resolved' => 'success',
                        'closed' => 'gray',
                        'cancelled' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('assignee.name')
                    ->label(__('admin.tables.maintenance.assigned_to'))
                    ->placeholder(__('admin.fields.unassigned'))
                    ->toggleable(),
                TextColumn::make('submitted_at')
                    ->label(__('admin.tables.maintenance.submitted'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('target_resolution_at')
                    ->label(__('admin.tables.maintenance.target'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->color(fn ($record): ?string => $record->isOverdue() ? 'danger' : null)
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.maintenance_request')),
                SelectFilter::make('priority')
                    ->label(__('admin.filters.priority'))
                    ->options(fn () => __('admin.enums.maintenance_priority')),
                SelectFilter::make('category')
                    ->label(__('admin.filters.category'))
                    ->options(fn () => __('admin.enums.maintenance_category')),
                SelectFilter::make('assigned_to')
                    ->label(__('admin.filters.assigned_to'))
                    ->options(fn () => User::orderBy('name')->pluck('name', 'id')),
                Filter::make('open_only')
                    ->label(__('admin.filters.open_only'))
                    ->query(fn (Builder $query) => $query->whereIn('status', MaintenanceRequest::OPEN_STATUSES))
                    ->default(),
                Filter::make('sla_breached')
                    ->label(__('admin.filters.sla_breached'))
                    ->query(fn (Builder $query) => $query
                        ->whereIn('status', MaintenanceRequest::OPEN_STATUSES)
                        ->whereNotNull('target_resolution_at')
                        ->where('target_resolution_at', '<', now())),
                TrashedFilter::make(),
            ])
            ->filtersFormColumns(2)
            ->headerActions([])
            ->recordActions([
                EditAction::make()
                    ->visible(fn ($record) => MaintenanceRequestResource::canEdit($record)),
                Action::make('changeStatus')
                    ->label(__('admin.actions.change_status'))
                    ->icon('heroicon-o-arrow-path-rounded-square')
                    ->color('primary')
                    ->visible(fn (MaintenanceRequest $record) => MaintenanceRequestResource::canEdit($record)
                        && ! empty(\App\Services\MaintenanceRequestService::TRANSITIONS[$record->status] ?? []))
                    ->modalHeading(fn (MaintenanceRequest $record) => __('admin.actions.change_status_heading', ['ref' => $record->reference]))
                    ->fillForm(fn (MaintenanceRequest $record) => ['status' => null])
                    ->schema(fn (MaintenanceRequest $record) => [
                        Select::make('status')
                            ->label(__('admin.fields.new_status'))
                            ->options(fn () => collect(\App\Services\MaintenanceRequestService::TRANSITIONS[$record->status] ?? [])
                                ->mapWithKeys(fn ($s) => [$s => __("admin.statuses.maintenance_request.{$s}")])
                                ->all())
                            ->required()
                            ->native(false)
                            ->live(),
                        Textarea::make('resolution_notes')
                            ->label(__('admin.fields.resolution_notes'))
                            ->rows(3)
                            ->required(fn ($get) => $get('status') === 'resolved')
                            ->visible(fn ($get) => $get('status') === 'resolved'),
                    ])
                    ->action(function (MaintenanceRequest $record, array $data) {
                        $svc = app(MaintenanceRequestService::class);
                        $svc->transition($record, $data['status'], $data);

                        Notification::make()
                            ->title(__('admin.actions.status_changed'))
                            ->body(__('admin.actions.status_changed_body', [
                                'ref' => $record->reference,
                                'status' => __("admin.statuses.maintenance_request.{$data['status']}"),
                            ]))
                            ->success()
                            ->send();
                    }),
                Action::make('assign')
                    ->label(__('admin.actions.assign'))
                    ->icon('heroicon-o-user-plus')
                    ->color('gray')
                    ->visible(fn (MaintenanceRequest $record) => MaintenanceRequestResource::canEdit($record)
                        && $record->isOpen())
                    ->fillForm(fn (MaintenanceRequest $record) => ['assigned_to' => $record->assigned_to])
                    ->schema([
                        Select::make('assigned_to')
                            ->label(__('admin.fields.assigned_to'))
                            ->options(fn () => User::orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->placeholder(__('admin.fields.unassigned')),
                    ])
                    ->action(function (MaintenanceRequest $record, array $data) {
                        app(MaintenanceRequestService::class)
                            ->assign($record, $data['assigned_to'] ?? null);

                        Notification::make()
                            ->title(__('admin.actions.assigned'))
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () => MaintenanceRequestResource::canDeleteAny()),
                    ForceDeleteBulkAction::make()
                        ->visible(fn () => MaintenanceRequestResource::canForceDeleteAny()),
                    RestoreBulkAction::make()
                        ->visible(fn () => MaintenanceRequestResource::canRestoreAny()),
                ]),
            ])
            ->defaultSort('submitted_at', 'desc');
    }
}
