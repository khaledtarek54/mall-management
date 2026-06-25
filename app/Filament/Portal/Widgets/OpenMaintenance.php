<?php

namespace App\Filament\Portal\Widgets;

use App\Filament\Portal\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Models\MaintenanceRequest;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class OpenMaintenance extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected function getTableHeading(): ?string
    {
        return __('admin.widgets.portal_open_maintenance.heading');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                fn (): Builder => MaintenanceRequest::query()
                    ->where('tenant_id', \App\Support\Portal::tenantId())
                    ->whereIn('status', MaintenanceRequest::OPEN_STATUSES)
                    ->with('unit')
                    ->latest('submitted_at')
            )
            ->columns([
                TextColumn::make('reference')
                    ->label(__('admin.tables.maintenance.reference'))
                    ->fontFamily('mono')
                    ->size('xs')
                    ->url(fn ($record) => MaintenanceRequestResource::getUrl('view', ['record' => $record])),
                TextColumn::make('title')
                    ->label(__('admin.tables.maintenance.title'))
                    ->limit(40),
                TextColumn::make('unit.code')
                    ->label(__('admin.tables.maintenance.unit'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('priority')
                    ->label(__('admin.tables.maintenance.priority'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.enums.maintenance_priority.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'urgent' => 'danger',
                        'high' => 'warning',
                        'medium' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.maintenance_request.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'submitted' => 'info',
                        'acknowledged', 'awaiting_tenant' => 'warning',
                        'in_progress' => 'primary',
                        default => 'gray',
                    }),
                TextColumn::make('submitted_at')
                    ->label(__('admin.tables.maintenance.submitted'))
                    ->date('d/m/Y'),
            ])
            ->emptyStateHeading(__('admin.widgets.portal_open_maintenance.empty'))
            ->emptyStateIcon('heroicon-o-wrench-screwdriver')
            ->paginated(false);
    }
}
