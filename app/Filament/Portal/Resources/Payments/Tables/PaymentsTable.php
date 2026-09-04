<?php

namespace App\Filament\Portal\Resources\Payments\Tables;

use App\Models\PaymentMethod;
use App\Support\StatusOptions;
use Carbon\Carbon;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class PaymentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                // Sortable so a tenant can order their receipts by reference. Search is NOT set
                // here on purpose: App\Support\TableDefaults already gives every table in both
                // panels a blob search, and `reference` is in Payment::searchTextSources() — a
                // second, column-level searchable would be a duplicate of a working mechanism.
                TextColumn::make('reference')
                    ->label(__('admin.tables.payment.reference'))
                    ->sortable()
                    ->fontFamily('mono')
                    ->size('xs'),
                TextColumn::make('payment_date')
                    ->label(__('admin.tables.payment.date'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('amount')
                    ->label(__('admin.tables.payment.amount'))
                    ->money('EGP')
                    ->sortable()
                    ->weight('bold')
                    ->color('success')
                    ->alignRight()
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
                TextColumn::make('method')
                    ->label(__('admin.tables.payment.method'))
                    ->badge()
                    // The catalogue, not a lang key: an operator-added rail has no key and would render
                    // raw on the very screen whose filter lists it.
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
                    // The catalogue, not a hand-kept `->only()` list — see the admin twin.
                    ->options(fn () => PaymentMethod::filterOptionsFor('payments.method')),
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    // Derived, exactly like the rail filter above it. The `->only()` this replaces
                    // offered 5 of the 9 the column accepts (measured 2026-09-04): `initiated`,
                    // `authorized`, `bounced` and `voided` were unfilterable — and `voided` is the
                    // 2026-08-28 status that says money was NOT returned, which is the one thing a
                    // tenant reading this list most needs to be able to pick out. `payments` is in
                    // no TenantVisibility::HIDDEN entry, so this is the full accepted set.
                    ->options(fn () => StatusOptions::forTenant('payments')),
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
                        ->when($data['payment_until'] ?? null, fn (Builder $q, $date) => $q->whereDate('payment_date', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['payment_from'] ?? null) {
                            $indicators[] = __('admin.filters.payment_from').': '.Carbon::parse($data['payment_from'])->format('d/m/Y');
                        }
                        if ($data['payment_until'] ?? null) {
                            $indicators[] = __('admin.filters.payment_until').': '.Carbon::parse($data['payment_until'])->format('d/m/Y');
                        }

                        return $indicators;
                    }),
            ])
            ->filtersFormColumns(2)
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('payment_date', 'desc')
            ->emptyStateIcon('heroicon-o-banknotes')
            ->emptyStateHeading(__('admin.empty.portal_payments.heading'))
            ->emptyStateDescription(__('admin.empty.portal_payments.description'));
    }
}
