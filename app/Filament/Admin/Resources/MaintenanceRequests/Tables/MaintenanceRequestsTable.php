<?php

namespace App\Filament\Admin\Resources\MaintenanceRequests\Tables;

use App\Enums\TenantRequestType;
use App\Filament\Admin\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Filament\Admin\Resources\MaintenanceWorkOrders\Schemas\CorrectiveWorkOrderForm;
use App\Models\Department;
use App\Models\TenantRequest;
use App\Models\User;
use App\Services\RaiseCorrectiveMaintenanceService;
use App\Services\TenantRequestService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
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
            ->modifyQueryUsing(fn ($query) => $query->with(['tenant', 'unit', 'assignee', 'department']))
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
                TextColumn::make('request_type')
                    ->label(__('admin.fields.request_type'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => ($state instanceof TenantRequestType ? $state : TenantRequestType::from((string) $state))->label())
                    ->color(fn ($state): string => match ($state instanceof TenantRequestType ? $state : TenantRequestType::tryFrom((string) $state)) {
                        TenantRequestType::Maintenance => 'warning',
                        TenantRequestType::Complaint => 'danger',
                        TenantRequestType::Inquiry => 'info',
                        TenantRequestType::Access => 'primary',
                        TenantRequestType::Billing => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('category')
                    ->label(__('admin.fields.subcategory'))
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state) => $state ? __("admin.enums.tenant_request_subcategory.{$state}") : null),
                TextColumn::make('channel')
                    ->label(__('admin.tables.maintenance.channel'))
                    ->badge()
                    ->color('info')
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state) => $state ? __("admin.enums.maintenance_channel.{$state}") : null)
                    ->toggleable(isToggledHiddenByDefault: false),
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
                TextColumn::make('department.name')
                    ->label(__('admin.resources.department.singular'))
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('assignee.name')
                    ->label(__('admin.tables.maintenance.assigned_to'))
                    ->placeholder(__('admin.fields.unassigned'))
                    ->toggleable(),
                TextColumn::make('assignedVendor.name')
                    ->label(__('admin.fields.assigned_vendor') ?: 'Vendor')
                    ->badge()
                    ->color('info')
                    ->placeholder('—')
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
                TextColumn::make('csat_rating')
                    ->label(__('admin.fields.csat'))
                    ->formatStateUsing(fn (?int $state) => $state ? str_repeat('★', $state).str_repeat('☆', 5 - $state) : null)
                    ->color('warning')
                    ->placeholder('—')
                    ->tooltip(fn ($record) => $record->csat_comment)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.maintenance_request')),
                SelectFilter::make('request_type')
                    ->label(__('admin.fields.request_type'))
                    ->options(fn () => TenantRequestType::options()),
                SelectFilter::make('priority')
                    ->label(__('admin.filters.priority'))
                    ->options(fn () => __('admin.enums.maintenance_priority')),
                SelectFilter::make('channel')
                    ->label(__('admin.filters.channel'))
                    ->options(fn () => __('admin.enums.maintenance_channel')),
                SelectFilter::make('department_id')
                    ->label(__('admin.resources.department.singular'))
                    ->options(fn () => Department::selectableOptions()),
                SelectFilter::make('assigned_to')
                    ->label(__('admin.filters.assigned_to'))
                    ->options(fn () => User::orderBy('name')->pluck('name', 'id')),
                Filter::make('open_only')
                    ->label(__('admin.filters.open_only'))
                    ->query(fn (Builder $query) => $query->whereIn('status', TenantRequest::OPEN_STATUSES))
                    ->default(),
                Filter::make('sla_breached')
                    ->label(__('admin.filters.sla_breached'))
                    ->query(fn (Builder $query) => $query
                        ->whereIn('status', TenantRequest::OPEN_STATUSES)
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
                    ->visible(fn (TenantRequest $record) => MaintenanceRequestResource::canEdit($record)
                        && ! empty(TenantRequestService::TRANSITIONS[$record->status] ?? []))
                    ->modalHeading(fn (TenantRequest $record) => __('admin.actions.change_status_heading', ['ref' => $record->reference]))
                    ->fillForm(fn (TenantRequest $record) => ['status' => null])
                    ->schema(fn (TenantRequest $record) => [
                        Select::make('status')
                            ->label(__('admin.fields.new_status'))
                            ->options(fn () => collect(TenantRequestService::TRANSITIONS[$record->status] ?? [])
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
                    ->action(function (TenantRequest $record, array $data) {
                        $svc = app(TenantRequestService::class);
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
                    ->visible(fn (TenantRequest $record) => MaintenanceRequestResource::canEdit($record)
                        && $record->isOpen())
                    ->fillForm(fn (TenantRequest $record) => ['assigned_to' => $record->assigned_to])
                    ->schema([
                        Select::make('assigned_to')
                            ->label(__('admin.fields.assigned_to'))
                            ->options(fn () => User::orderBy('name')->pluck('name', 'id'))
                            ->searchable()
                            ->placeholder(__('admin.fields.unassigned')),
                    ])
                    ->action(function (TenantRequest $record, array $data) {
                        app(TenantRequestService::class)
                            ->assign($record, $data['assigned_to'] ?? null);

                        Notification::make()
                            ->title(__('admin.actions.assigned'))
                            ->success()
                            ->send();
                    }),
                Action::make('redirect')
                    ->label(__('admin.actions.redirect'))
                    ->icon('heroicon-o-arrows-right-left')
                    ->color('gray')
                    ->visible(fn (TenantRequest $record) => MaintenanceRequestResource::canEdit($record))
                    ->fillForm(fn (TenantRequest $record) => ['department_id' => $record->department_id])
                    ->schema([
                        Select::make('department_id')
                            ->label(__('admin.resources.department.singular'))
                            ->options(fn () => Department::selectableOptions())
                            ->searchable()
                            ->placeholder(__('admin.fields.unassigned'))
                            ->native(false),
                    ])
                    ->action(function (TenantRequest $record, array $data) {
                        app(TenantRequestService::class)
                            ->redirectToDepartment($record, $data['department_id'] ?? null);

                        Notification::make()
                            ->title(__('admin.actions.redirected'))
                            ->success()
                            ->send();
                    }),
                // Module 11 → 26 — turn a reported fault into a facility work order, linked back.
                // Gated on the WORK ORDER module (this creates one), not on the request's own
                // permissions: a coordinator triaging tickets and an engineer raising jobs are
                // different rights.
                Action::make('raise_work_order')
                    ->label(__('admin.preventive_maintenance.cm.from_request'))
                    ->icon('heroicon-o-wrench-screwdriver')
                    ->color('primary')
                    ->modalDescription(__('admin.preventive_maintenance.cm.from_request_hint'))
                    ->visible(fn (TenantRequest $record) => $record->isOpen()
                        && (auth()->user()?->can('preventive_maintenance.create') ?? false))
                    ->authorize(fn () => auth()->user()?->can('preventive_maintenance.create') ?? false)
                    ->fillForm(fn (TenantRequest $record) => [
                        'title' => $record->title,
                        'description' => $record->description,
                        'priority' => $record->priority,
                    ])
                    ->schema(fn (TenantRequest $record) => CorrectiveWorkOrderForm::fields($record->unit?->asset_id))
                    ->action(function (TenantRequest $record, array $data): void {
                        abort_unless(auth()->user()?->can('preventive_maintenance.create') ?? false, 403);

                        try {
                            $wo = app(RaiseCorrectiveMaintenanceService::class)->fromTenantRequest($record, $data);
                        } catch (\DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('admin.preventive_maintenance.cm.raised_notice'))
                            ->body($wo->reference)
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
            ->defaultSort('submitted_at', 'desc')
            ->emptyStateIcon('heroicon-o-wrench-screwdriver')
            ->emptyStateHeading(__('admin.empty.maintenance.heading'))
            ->emptyStateDescription(__('admin.empty.maintenance.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('admin.empty.maintenance.cta'))
                    ->icon('heroicon-o-plus'),
            ]);
    }
}
