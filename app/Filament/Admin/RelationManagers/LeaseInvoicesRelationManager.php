<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Actions\OpenRecordAction;
use App\Filament\Admin\RelationManagers\Concerns\CountsItsRows;
use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Models\Invoice;
use App\Support\BadgeColors;
use App\Support\ResourceLink;
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
            // `isPayable()` nets prior write-offs per row; loaded once for the page, as the
            // portal's invoice table does, rather than one aggregate per row.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('writeOffs'))
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
                    ->color(BadgeColors::of('invoices.status')),
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
                    // *Outstanding*, the word the tenant's own invoices tab uses for the same
                    // query — it was labelled *Overdue only* here, which is a different question
                    // (`stillOwed()` includes an invoice not yet due).
                    ->label(__('admin.tenant_invoices.outstanding_only'))
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
                OpenRecordAction::make(InvoiceResource::class),

                Action::make('recordPayment')
                    ->label(__('admin.collections.record_payment'))
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    // Offered only where money may still land — `Invoice::isPayable()`, the ONE
                    // predicate (`InvoiceSettlement::accepts()` and the balance net of write-offs).
                    // This button restated it as `balance > 0` and a three-status denylist, which
                    // offered *Record payment* on a `credited` invoice and on one partly written
                    // off — a button that refuses the moment it is pressed is a worse answer than
                    // one that is not offered, which is the rule `billDeposit` follows too.
                    ->visible(fn (Invoice $record): bool => $record->isPayable()
                        && (auth()->user()?->can('payments.create') ?? false))
                    ->url(fn (Invoice $record): string => ResourceLink::create(PaymentResource::class, [
                        'invoice' => $record->getKey(),
                    ])),
            ])
            ->toolbarActions([])
            ->defaultSort('issue_date', 'desc')
            ->paginated([10, 25]);
    }
}
