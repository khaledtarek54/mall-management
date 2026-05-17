<?php

namespace App\Filament\Admin\Widgets;

use App\Models\Lease;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class TopTenants extends TableWidget
{
    protected static ?int $sort = 6;

    protected int|string|array $columnSpan = 'full';

    protected function getTableHeading(): ?string
    {
        return __('admin.widgets.top_tenants.heading');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                fn (): Builder => Lease::query()
                    ->where('status', 'active')
                    ->with(['tenant', 'unit'])
                    ->orderByDesc('base_rent_monthly')
                    ->limit(10)
            )
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
                TextColumn::make('expiry_date')
                    ->label(__('admin.widgets.top_tenants.lease_ends'))
                    ->date('d/m/Y'),
            ])
            ->paginated(false);
    }
}
