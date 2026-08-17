<?php

namespace App\Filament\Portal\Widgets;

use App\Filament\Portal\Resources\TenantRequests\TenantRequestResource;
use App\Models\TenantRequest;
use App\Support\Portal;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class OpenTenantRequests extends TableWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 'full';

    protected function getTableHeading(): ?string
    {
        return __('admin.widgets.portal_open_requests.heading');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                fn (): Builder => TenantRequest::query()
                    ->where('tenant_id', Portal::tenantId())
                    ->whereIn('status', TenantRequest::OPEN_STATUSES)
                    ->with('unit')
                    ->latest('submitted_at')
            )
            ->columns([
                TextColumn::make('reference')
                    ->label(__('admin.tables.requests.reference'))
                    ->fontFamily('mono')
                    ->size('xs')
                    ->url(fn ($record) => TenantRequestResource::getUrl('view', ['record' => $record])),
                TextColumn::make('title')
                    ->label(__('admin.tables.requests.title'))
                    ->limit(40),
                TextColumn::make('unit.code')
                    ->label(__('admin.tables.requests.unit'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('priority')
                    ->label(__('admin.tables.requests.priority'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.enums.work_priority.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'urgent' => 'danger',
                        'high' => 'warning',
                        'medium' => 'info',
                        default => 'gray',
                    }),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.tenant_request.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'submitted' => 'info',
                        'acknowledged', 'awaiting_tenant' => 'warning',
                        'in_progress' => 'primary',
                        default => 'gray',
                    }),
                TextColumn::make('submitted_at')
                    ->label(__('admin.tables.requests.submitted'))
                    ->date('d/m/Y'),
            ])
            ->emptyStateHeading(__('admin.widgets.portal_open_requests.empty'))
            ->emptyStateIcon('heroicon-o-wrench-screwdriver')
            ->paginated(false);
    }
}
