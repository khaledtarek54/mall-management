<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Concerns\RoleScopedWidget;
use App\Filament\Admin\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Models\MaintenanceRequest;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class OpenMaintenanceRequests extends TableWidget
{
    use RoleScopedWidget;

    protected static function allowedRoles(): array
    {
        return ['manager', 'maintenance_manager'];
    }

    protected static function widgetModule(): ?string
    {
        return 'maintenance';
    }

    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    protected function getTableHeading(): ?string
    {
        return __('admin.widgets.open_maintenance.heading');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                fn (): Builder => MaintenanceRequest::query()
                    ->whereIn('status', MaintenanceRequest::OPEN_STATUSES)
                    ->with(['tenant', 'unit', 'assignee'])
                    ->orderByRaw("FIELD(priority, 'urgent', 'high', 'medium', 'low')")
                    ->orderBy('submitted_at')
            )
            ->columns([
                TextColumn::make('reference')
                    ->label(__('admin.widgets.open_maintenance.reference'))
                    ->fontFamily('mono')
                    ->size('xs')
                    ->url(fn ($record) => MaintenanceRequestResource::getUrl('edit', ['record' => $record])),
                TextColumn::make('title')
                    ->label(__('admin.widgets.open_maintenance.title'))
                    ->limit(40)
                    ->weight('medium'),
                TextColumn::make('tenant.name')
                    ->label(__('admin.widgets.open_maintenance.tenant')),
                TextColumn::make('unit.code')
                    ->label(__('admin.widgets.open_maintenance.unit'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('priority')
                    ->label(__('admin.widgets.open_maintenance.priority'))
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
                        default => 'gray',
                    }),
                TextColumn::make('target_resolution_at')
                    ->label(__('admin.widgets.open_maintenance.target'))
                    ->dateTime('d/m/Y H:i')
                    ->color(fn ($record): ?string => $record->isOverdue() ? 'danger' : null),
                TextColumn::make('assignee.name')
                    ->label(__('admin.widgets.open_maintenance.assigned_to'))
                    ->placeholder(__('admin.fields.unassigned')),
            ])
            ->emptyStateHeading(__('admin.widgets.open_maintenance.empty'))
            ->emptyStateIcon('heroicon-o-wrench-screwdriver')
            ->paginated([5, 10]);
    }
}
