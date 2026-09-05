<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Models\PaymentMethod;
use App\Support\TenantScope;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TenantPaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.navigation.payments');
    }

    public function table(Table $table): Table
    {
        return $table
            // Property isolation: a payment is visible only if its money is in one of the operator's
            // visible properties (a shared counterparty may pay across malls).
            //
            // Through `Payment::scopeInProperties()`, the ONE definition. This read `invoices.lease
            // .unit` — a chain from before unit owners existed — while `Tenant::creditBalance()` and
            // `TenantBalances` beside it had already been corrected to the invoice's OWN `asset_id`.
            // Measured on `mall_management_qa` 2026-09-03: 42 of 42 owner assessments carry
            // `lease_id` NULL, so a receipt settling one appeared on the Payments REGISTER (which
            // scopes from `#[PropertyOwned(via: 'invoices')]`) and vanished from the counterparty's
            // own Payments tab — and so did every unallocated receipt, i.e. every cleared series
            // cheque. `->when()` is gone with it: the scope answers the null case itself, so a
            // caller cannot get the "unrestricted" branch subtly wrong.
            ->modifyQueryUsing(fn ($query) => $query->inProperties(TenantScope::visibleAssetIds()))
            ->columns([
                TextColumn::make('reference')
                    ->label(__('admin.tables.payment.reference'))
                    ->fontFamily('mono')
                    ->size('xs'),
                TextColumn::make('payment_date')
                    ->label(__('admin.tables.payment.date'))
                    ->date('d/m/Y'),
                TextColumn::make('amount')
                    ->label(__('admin.tables.payment.amount'))
                    ->money('EGP')
                    ->color('success')
                    ->weight('bold')
                    ->alignRight(),
                TextColumn::make('method')
                    ->label(__('admin.tables.payment.method'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => PaymentMethod::labelFor($state))
                    ->color('info'),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.payment.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'captured', 'reconciled', 'settled' => 'success',
                        'initiated', 'authorized' => 'warning',
                        'failed', 'bounced', 'refunded', 'voided' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('method')
                    ->label(__('admin.tables.payment.method'))
                    ->options(fn () => PaymentMethod::filterOptionsFor('payments.method')),
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.payment')),
                Filter::make('payment_date_range')
                    ->label(__('admin.tables.payment.date'))
                    ->schema([
                        DatePicker::make('payment_from')
                            ->label(__('admin.filters.payment_from'))
                            ->native(false),
                        DatePicker::make('payment_until')
                            ->label(__('admin.filters.payment_until'))
                            ->native(false),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['payment_from'] ?? null, fn (Builder $q, $date) => $q->whereDate('payment_date', '>=', $date))
                        ->when($data['payment_until'] ?? null, fn (Builder $q, $date) => $q->whereDate('payment_date', '<=', $date))),
            ])
            ->filtersFormColumns(2)
            // NO HEADER ACTION. *Record payment* used to live here — a `->url()` link into
            // PaymentResource's create form, which Filament's read-only-under-a-`ViewRecord` rule
            // cannot deny because a link is not an action, so the tenant's READ-ONLY page offered
            // it. It is now `TenantActions::recordPayment()`, in the record's header on both the
            // View and the Edit page: an act belongs to the RECORD and appears by PERMISSION, not
            // by which page you opened or which tab you are looking at (Yardi's shape —
            // docs/benchmarks/yardi/08). A tab LISTS what is attached to the record.
            ->recordActions([])
            ->toolbarActions([])
            ->defaultSort('payment_date', 'desc')
            ->paginated([10, 25]);
    }
}
