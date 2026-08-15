<?php

namespace App\Filament\Admin\Widgets;

use App\Enums\TenantRequestType;
use App\Filament\Admin\Concerns\RoleScopedWidget;
use App\Filament\Admin\Resources\TenantRequests\TenantRequestResource;
use App\Models\TenantRequest;
use App\Support\TenantScope;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class OpenTenantRequests extends TableWidget
{
    use RoleScopedWidget;

    protected static function widgetModule(): ?string
    {
        return 'requests';
    }

    protected static ?int $sort = 10;

    protected int|string|array $columnSpan = 'full';

    protected function getTableHeading(): ?string
    {
        return __('admin.widgets.open_requests.heading');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function (): Builder {
                return TenantScope::applyTo(TenantRequest::query(), 'unit')
                    ->whereIn('status', TenantRequest::OPEN_STATUSES)
                    ->with(['tenant', 'unit', 'assignee'])
                    ->orderByRaw("FIELD(priority, 'urgent', 'high', 'medium', 'low')")
                    ->orderBy('submitted_at');
            })
            ->columns([
                TextColumn::make('reference')
                    ->label(__('admin.widgets.open_requests.reference'))
                    ->fontFamily('mono')
                    ->size('xs')
                    ->url(fn ($record) => TenantRequestResource::getUrl('edit', ['record' => $record])),
                TextColumn::make('title')
                    ->label(__('admin.widgets.open_requests.title'))
                    ->limit(40)
                    ->weight('medium'),
                TextColumn::make('tenant.name')
                    ->label(__('admin.widgets.open_requests.tenant')),
                TextColumn::make('unit.code')
                    ->label(__('admin.widgets.open_requests.unit'))
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
                TextColumn::make('priority')
                    ->label(__('admin.widgets.open_requests.priority'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.enums.work_priority.{$state}"))
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
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.tenant_request.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'submitted' => 'info',
                        'acknowledged' => 'warning',
                        'in_progress' => 'primary',
                        'awaiting_tenant' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('target_resolution_at')
                    ->label(__('admin.widgets.open_requests.target'))
                    ->dateTime('d/m/Y H:i')
                    ->color(fn ($record): ?string => $record->isOverdue() ? 'danger' : null),
                TextColumn::make('assignee.name')
                    ->label(__('admin.widgets.open_requests.assigned_to'))
                    ->placeholder(__('admin.fields.unassigned')),
            ])
            ->emptyStateHeading(__('admin.widgets.open_requests.empty'))
            ->emptyStateIcon('heroicon-o-wrench-screwdriver')
            ->paginated([5, 10]);
    }
}
