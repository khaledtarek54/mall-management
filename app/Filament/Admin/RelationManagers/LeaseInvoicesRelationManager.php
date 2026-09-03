<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Admin\RelationManagers\Concerns\CountsItsRows;
use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Models\Invoice;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class LeaseInvoicesRelationManager extends RelationManager
{
    use CountsItsRows;

    protected static string $relationship = 'invoices';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.navigation.invoices');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label(__('admin.tables.invoice.number'))
                    ->fontFamily('mono')
                    ->size('xs'),
                TextColumn::make('period_start')
                    ->label(__('admin.tables.invoice.period'))
                    ->formatStateUsing(fn ($record) => $record->period_start?->locale(app()->getLocale())->isoFormat('MMM YYYY') ?? '—'),
                TextColumn::make('total')
                    ->label(__('admin.tables.invoice.total'))
                    ->money('EGP')
                    ->alignRight(),
                TextColumn::make('paid_amount')
                    ->label(__('admin.tables.invoice.paid'))
                    ->money('EGP')
                    ->color('success')
                    ->alignRight(),
                TextColumn::make('balance')
                    ->label(__('admin.tables.invoice.balance'))
                    ->money('EGP')
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success')
                    ->weight('bold')
                    ->alignRight(),
                TextColumn::make('due_date')
                    ->label(__('admin.tables.invoice.due_date'))
                    ->date('d/m/Y'),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.invoice.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'paid' => 'success',
                        'partially_paid' => 'warning',
                        'overdue' => 'danger',
                        'issued' => 'info',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.invoice')),
                Filter::make('period')
                    ->label(__('admin.filters.period'))
                    ->schema([
                        DatePicker::make('period_from')
                            ->label(__('admin.filters.period_from'))
                            ->native(false),
                        DatePicker::make('period_until')
                            ->label(__('admin.filters.period_until'))
                            ->native(false),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['period_from'] ?? null, fn (Builder $q, $date) => $q->whereDate('period_start', '>=', $date))
                        ->when($data['period_until'] ?? null, fn (Builder $q, $date) => $q->whereDate('period_start', '<=', $date))),
                Filter::make('unpaid_only')
                    ->label(__('admin.filters.overdue_only'))
                    // Still OWED, not merely carrying a balance — see the tenant twin.
                    ->query(fn (Builder $query) => $query->stillOwed()),
            ])
            ->filtersFormColumns(2)
            ->headerActions([])
            // ── A LIST YOU CANNOT ACT ON IS A DEAD END ──────────────────────────────────────────
            //
            // This tab had NO actions at all — not even a way to open the document. An operator
            // looking at the invoice they wanted to settle had to leave the lease, open the
            // Payments resource and find the same document by number, which is the six-screen
            // loop UX5-03 removed from the collections worklist and never removed from here. The
            // Billing forecast tab beside it has linked to the invoice since it shipped.
            //
            // Both link to the REAL screens rather than opening thinner copies here, for the
            // reason the tenant hub's own record-payment action states: the payment form owns the
            // posting-date guard, the property scope, the over-allocation backstop and the
            // orphaned-receipt refusal, and a second form would own none of them.
            ->recordActions([
                Action::make('open')
                    ->label(__('admin.actions.open'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Invoice $record): string => InvoiceResource::getUrl('edit', ['record' => $record])),

                Action::make('recordPayment')
                    ->label(__('admin.collections.record_payment'))
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    // Offered only where there is something to settle. A DRAFT has not been
                    // raised, and a settled or cancelled document has nothing to receive against —
                    // an action that refuses the moment it is pressed is a worse answer than one
                    // that is not offered, which is the rule `billDeposit` follows too.
                    ->visible(fn (Invoice $record): bool => (float) $record->balance > 0
                        && ! in_array($record->status, ['draft', 'cancelled', 'written_off'], true)
                        && (auth()->user()?->can('payments.create') ?? false))
                    ->url(fn (Invoice $record): string => PaymentResource::getUrl('create', [
                        'invoice' => $record->getKey(),
                    ])),
            ])
            ->toolbarActions([])
            ->defaultSort('issue_date', 'desc')
            ->paginated([10, 25]);
    }
}
