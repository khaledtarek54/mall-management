<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Concerns\RoleScopedWidget;
use App\Models\Payment;
use App\Support\TenantScope;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class RecentPayments extends TableWidget
{
    use RoleScopedWidget;

    protected static function allowedRoles(): array
    {
        return ['manager', 'viewer', 'accounting'];
    }

    protected static ?int $sort = 9;

    protected int|string|array $columnSpan = 'full';

    protected function getTableHeading(): ?string
    {
        return __('admin.widgets.recent_payments.heading');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(function (): Builder {
                return TenantScope::applyTo(Payment::query(), 'invoices.lease.unit')
                    ->whereIn('status', \App\Models\Payment::RECEIVED_STATUSES)
                    ->with('tenant')
                    ->latest('payment_date')
                    ->latest('id')
                    ->limit(8);
            })
            ->columns([
                TextColumn::make('payment_date')
                    ->label(__('admin.widgets.recent_payments.when'))
                    ->date('d/m/Y'),
                TextColumn::make('tenant.name')
                    ->label(__('admin.widgets.recent_payments.tenant'))
                    ->weight('bold')
                    ->limit(28),
                TextColumn::make('reference')
                    ->label(__('admin.widgets.recent_payments.reference'))
                    ->fontFamily('mono')
                    ->size('xs')
                    ->color('gray'),
                TextColumn::make('method')
                    ->label(__('admin.widgets.recent_payments.method'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state) => __("admin.enums.method.{$state}")),
                TextColumn::make('amount')
                    ->label(__('admin.widgets.recent_payments.amount'))
                    ->money('EGP')
                    ->alignRight()
                    ->weight('bold')
                    ->color('success'),
            ])
            ->emptyStateHeading(__('admin.widgets.recent_payments.empty'))
            ->emptyStateIcon('heroicon-o-banknotes')
            ->paginated(false);
    }
}
