<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use App\Support\Filament\PropertyLink;
use App\Support\TenantScope;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * What this tenant OWES — the other half of the money picture.
 *
 * The tenant page showed payments and not invoices, so it answered "what have they paid?" while the
 * question an operator actually opens a tenant to ask — "what do they owe, and how late is it?" —
 * had to be taken to the invoice register and filtered. An asymmetry rather than an oversight of
 * kind: the money-in side was surfaced and the money-out side was not.
 *
 * Property-isolated the same way the payments table is: a tenant may trade in several malls, and an
 * operator scoped to one must not see the other's AR. Scoped through `lease.unit.asset_id`, which is
 * how every tenant-to-property answer in the system is derived.
 *
 * Read-only, with a link out. Invoices are raised by the billing run or the invoice screen, where
 * the VAT rules, the charge schedule and the posting-date guard all apply — a create button here
 * would be a second, thinner way to make money.
 */
class TenantInvoicesRelationManager extends RelationManager
{
    protected static string $relationship = 'invoices';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.navigation.invoices');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->when(
                TenantScope::visibleAssetIds(),
                // The invoice's OWN property column, not the lease chain: an owner's assessment has
                // no lease, so the old hop dropped every one of them from the party's invoices tab —
                // their صيانة was billed, overdue and absent from the screen that lists what they owe.
                fn ($q, $ids) => $q->whereIn('asset_id', $ids),
            ))
            ->columns([
                TextColumn::make('number')
                    ->label(__('admin.tables.invoice.number'))
                    ->fontFamily('mono')
                    ->size('xs')
                    ->searchable(),

                TextColumn::make('issue_date')
                    ->label(__('admin.tables.invoice.issue_date'))
                    ->date('d/m/Y')
                    ->sortable(),

                TextColumn::make('due_date')
                    ->label(__('admin.tables.invoice.due_date'))
                    ->date('d/m/Y')
                    ->sortable()
                    // How late, not merely when — the number an operator is chasing.
                    ->description(fn (Invoice $record) => $record->balance > 0 && $record->due_date->isPast()
                        ? __('admin.tenant_invoices.days_overdue', ['days' => (int) $record->due_date->diffInDays(now())])
                        : null),

                TextColumn::make('total')
                    ->label(__('admin.tables.invoice.total'))
                    ->money('EGP'),

                TextColumn::make('balance')
                    ->label(__('admin.tables.invoice.balance'))
                    ->money('EGP')
                    ->weight('bold')
                    ->color(fn (Invoice $record) => $record->balance > 0 ? 'danger' : 'success'),

                TextColumn::make('status')
                    ->label(__('admin.filters.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.invoice.{$state}"))
                    ->color(fn (string $state) => match ($state) {
                        'paid' => 'success',
                        'overdue' => 'danger',
                        'cancelled' => 'gray',
                        default => 'warning',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.invoice')),
                Filter::make('outstanding')
                    ->label(__('admin.tenant_invoices.outstanding_only'))
                    // Still OWED, not merely carrying a balance: a write-off leaves the balance
                    // standing by design, so the raw column shows forgiven money as outstanding.
                    ->query(fn (Builder $query): Builder => $query->stillOwed())
                    ->toggle(),
            ])
            ->recordActions([
                Action::make('open')
                    ->label(__('admin.actions.open'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    // The property comes from the ROW, and on THIS tab that is belt and braces: it
                    // narrows with `TenantScope::visibleAssetIds()`, which answers the SELECTED
                    // property for any real tenant — super_admin included — so a row from another
                    // mall cannot reach the screen today. It is written the same way as the
                    // violations and sales tabs, which genuinely do span malls, because the answer
                    // to "which mall is this row in" should not depend on a scoping decision made
                    // in a different file, and because the gate requires it rather than keeping an
                    // exemption list of the tabs that happen to be narrow this week.
                    ->url(fn (Invoice $record): ?string => PropertyLink::to(InvoiceResource::class, $record))
                    // A ROW WITH NO PROPERTY GETS NO BUTTON, and one in a mall this operator cannot
                    // enter gets none either — `PropertyLink::to()` answers null for both, and an
                    // *Open* that goes nowhere is worse than no *Open*.
                    ->visible(fn (Invoice $record): bool => InvoiceResource::canEdit($record)
                        && PropertyLink::to(InvoiceResource::class, $record) !== null),
            ])
            ->defaultSort('issue_date', 'desc')
            ->emptyStateIcon('heroicon-o-document-currency-dollar')
            ->emptyStateHeading(__('admin.tenant_invoices.empty_heading'))
            ->emptyStateDescription(__('admin.tenant_invoices.empty_description'));
    }
}
