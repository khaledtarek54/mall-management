<?php

namespace App\Filament\Portal\Resources\CreditNotes\Tables;

use App\Support\TenantVisibility;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * The tenant's credit notes. Read-only — every action a credit note has belongs to the operator.
 *
 * Column order answers the tenant's actual question in order: *which credit is this, what was it
 * for, which bill did it come off, how much, and how much of it is still mine.*
 */
class CreditNotesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // The invoice is shown on every row; without this the list is one query per row.
            ->modifyQueryUsing(fn ($query) => $query->with('invoice'))
            ->columns([
                TextColumn::make('number')
                    ->label(__('admin.tables.credit_note.number'))
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->size('xs'),
                TextColumn::make('issue_date')
                    ->label(__('admin.fields.issue_date'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('invoice.number')
                    ->label(__('admin.fields.invoice'))
                    // A standalone tenant-level credit has no invoice behind it, and that is a
                    // normal state rather than missing data.
                    ->placeholder('—')
                    ->fontFamily('mono')
                    ->size('xs'),
                TextColumn::make('reason')
                    ->label(__('admin.fields.credit_note_reason'))
                    ->formatStateUsing(fn (?string $state) => $state
                        ? __("admin.enums.credit_note_reason.{$state}")
                        : '—')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('total')
                    ->label(__('admin.tables.credit_note.total'))
                    ->money('EGP')
                    ->sortable()
                    ->alignRight()
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
                TextColumn::make('applied_amount')
                    ->label(__('admin.tables.credit_note.applied'))
                    ->money('EGP')
                    ->color('success')
                    ->alignRight()
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
                // What is still the tenant's to spend — the one figure they are looking for.
                TextColumn::make('balance')
                    ->label(__('admin.tables.credit_note.balance'))
                    ->money('EGP')
                    ->weight('bold')
                    ->color(fn ($state) => (float) $state > 0 ? 'warning' : 'gray')
                    ->alignRight()
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.credit_note.{$state}"))
                    ->color(fn (string $state) => match ($state) {
                        'applied' => 'success',
                        'issued' => 'warning',
                        'void' => 'danger',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    // Derived from the statuses a TENANT may be shown — `ValueSets` minus the
                    // hidden ones — never a hand-written list. Offering `draft` here would put a
                    // filter on the list that can only ever return nothing, and imply the tenant
                    // has drafts to go and look at. A new status becomes filterable by existing,
                    // which is the same rule the scope itself follows.
                    ->options(fn () => collect(TenantVisibility::visibleFor('credit_notes') ?? [])
                        ->mapWithKeys(fn (string $s) => [$s => __("admin.statuses.credit_note.{$s}")])
                        ->all()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('issue_date', 'desc')
            ->emptyStateIcon('heroicon-o-receipt-refund')
            ->emptyStateHeading(__('admin.empty.portal_credit_notes.heading'))
            ->emptyStateDescription(__('admin.empty.portal_credit_notes.description'));
    }
}
