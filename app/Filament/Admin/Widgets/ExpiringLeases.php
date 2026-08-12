<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Concerns\RoleScopedWidget;
use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Models\Lease;
use App\Support\TenantScope;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class ExpiringLeases extends TableWidget
{
    use RoleScopedWidget;

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    protected function getTableHeading(): ?string
    {
        return __('admin.widgets.expiring_leases.heading');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function (): Builder {
                return TenantScope::applyTo(Lease::query(), 'unit')
                    ->where('status', 'active')
                    ->whereDate('expiry_date', '<=', now()->addDays(90))
                    ->whereDate('expiry_date', '>=', now())
                    ->with(['tenant', 'unit'])
                    ->orderBy('expiry_date');
            })
            ->columns([
                TextColumn::make('tenant.name')
                    ->label(__('admin.widgets.expiring_leases.tenant'))
                    ->weight('bold'),
                TextColumn::make('unit.code')
                    ->label(__('admin.widgets.expiring_leases.unit'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('reference')
                    ->label(__('admin.widgets.expiring_leases.lease'))
                    ->fontFamily('mono')
                    ->size('xs'),
                TextColumn::make('expiry_date')
                    ->label(__('admin.widgets.expiring_leases.expires'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('days_remaining')
                    ->label(__('admin.widgets.expiring_leases.days_remaining'))
                    ->badge()
                    ->state(fn (Lease $record): int => max(0, (int) now()->startOfDay()->diffInDays($record->expiry_date, false)))
                    ->formatStateUsing(fn (int $state) => __('admin.widgets.expiring_leases.days', ['count' => $state]))
                    ->color(fn (int $state): string => match (true) {
                        $state < 30 => 'danger',
                        $state < 60 => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('base_rent_monthly')
                    ->label(__('admin.widgets.expiring_leases.monthly_value'))
                    ->money('EGP')
                    ->alignRight(),
            ])
            // Click the row to open the lease. Null for a user who cannot edit it, so the
            // row is simply not a link rather than a link to a 403.
            // Tenant passed explicitly. `getUrl()` otherwise reads the AMBIENT Filament tenant
            // and throws UrlGenerationException when there isn't one — which is not merely a
            // test artefact: any render outside a property context (a widget probed directly, a
            // future digest job) would 500 the whole dashboard rather than omit one link.
            ->recordUrl(function ($record) {
                $tenant = Filament::getTenant();

                return ($tenant && LeaseResource::canEdit($record))
                    ? LeaseResource::getUrl('edit', ['record' => $record], tenant: $tenant)
                    : null;
            })
            ->emptyStateHeading(__('admin.widgets.expiring_leases.empty'))
            ->emptyStateIcon('heroicon-o-calendar-days')
            ->paginated(false);
    }
}
