<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Concerns\RoleScopedWidget;
use App\Models\Lease;
use App\Models\TenantSalesDeclaration;
use App\Support\TenantScope;
use Carbon\CarbonImmutable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class TopTenants extends TableWidget
{
    use RoleScopedWidget;

    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    protected function getTableHeading(): ?string
    {
        return __('admin.widgets.top_tenants.heading');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function (): Builder {
                return TenantScope::applyTo(Lease::query(), 'unit')
                    ->where('status', 'active')
                    ->with(['tenant', 'unit'])
                    ->orderByDesc('base_rent_monthly')
                    ->limit(10);
            })
            ->columns([
                TextColumn::make('tenant.name')
                    ->label(__('admin.widgets.top_tenants.tenant'))
                    ->weight('bold'),
                TextColumn::make('unit.code')
                    ->label(__('admin.widgets.top_tenants.unit'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('unit.category')
                    ->label(__('admin.widgets.top_tenants.category'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.enums.category.{$state}")),
                TextColumn::make('base_rent_monthly')
                    ->label(__('admin.widgets.top_tenants.monthly_rent'))
                    ->money('EGP')
                    ->alignRight(),
                TextColumn::make('service_charge_monthly')
                    ->label(__('admin.widgets.top_tenants.service_charge'))
                    ->money('EGP')
                    ->alignRight(),
                // Sales density = the prior month's declared sales / unit sqm.
                // Mall operators rank tenants by this, not rent — it's the
                // single best per-tenant performance indicator we have.
                TextColumn::make('sales_density')
                    ->label(__('admin.widgets.top_tenants.sales_density'))
                    ->state(fn (Lease $lease) => $this->salesDensityFor($lease))
                    ->formatStateUsing(fn ($state) => $state !== null
                        ? 'EGP '.number_format($state, 0).'/'.__('admin.widgets.top_tenants.per_sqm')
                        : '—')
                    ->color(fn ($state) => $state !== null ? 'success' : 'gray')
                    ->alignRight()
                    ->tooltip(__('admin.widgets.top_tenants.sales_density_tooltip')),
                TextColumn::make('expiry_date')
                    ->label(__('admin.widgets.top_tenants.lease_ends'))
                    ->date('d/m/Y'),
            ])
            ->paginated(false);
    }

    /**
     * Sales density for a lease = (declared sales in the most recently
     * completed full month) / unit sqm. Returns null if there's no
     * declaration for that period yet.
     */
    private function salesDensityFor(Lease $lease): ?float
    {
        // The declaration first, because the area has to be the area DURING the month it declares.
        $latest = TenantSalesDeclaration::query()
            ->where('lease_id', $lease->id)
            ->whereIn('status', ['locked', 'submitted'])
            ->orderByDesc('period_start')
            ->first();

        if (! $latest || $latest->declared_sales <= 0) {
            return null;
        }

        // Was `$lease->unit?->area_sqm`, which was wrong twice over: it read the MASTER unit alone,
        // understating a multi-unit lease by its whole non-master footprint and so overstating its
        // density (the trap Lease::totalAreaSqm()'s docblock names); and it read today's
        // measurement, dividing a past month's sales by an area that may have been remeasured
        // since. Both skewed the ranking this widget exists to produce.
        $sqm = $lease->totalAreaSqmForPeriod(
            CarbonImmutable::parse($latest->period_start),
            CarbonImmutable::parse($latest->period_end),
        );

        if ($sqm <= 0) {
            return null;
        }

        return round(((float) $latest->declared_sales) / $sqm, 0);
    }
}
