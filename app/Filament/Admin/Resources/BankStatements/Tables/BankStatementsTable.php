<?php

namespace App\Filament\Admin\Resources\BankStatements\Tables;

use App\Filament\Admin\Resources\BankStatements\BankStatementResource;
use App\Models\Asset;
use App\Models\BankAccount;
use App\Models\BankStatement;
use App\Support\Filament\EntitySelectFilter;
use Carbon\Carbon;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BankStatementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->searchable(false)
            ->modifyQueryUsing(fn ($query) => $query->with('bankAccount.asset')->withCount('lines'))
            ->columns([
                TextColumn::make('bankAccount.name')
                    ->label(__('admin.resources.bank_account.singular'))
                    ->weight('bold')
                    ->sortable()
                    ->description(function (BankStatement $record) {
                        $account = $record->getRelationValue('bankAccount');

                        $asset = $account instanceof BankAccount ? $account->getRelationValue('asset') : null;

                        return $asset instanceof Asset ? $asset->name : null;
                    }),
                // The displayed value is a formatted range, so name the real columns to
                // sort on — sorting the literal string "01/07/2026 – 31/07/2026" would
                // order by day-of-month.
                TextColumn::make('period')
                    ->label(__('admin.fields.period'))
                    ->sortable(['period_start', 'period_end'])
                    ->getStateUsing(fn (BankStatement $record) => $record->label()),
                TextColumn::make('lines_count')
                    ->label(__('admin.fields.statement_lines'))
                    ->badge()
                    ->sortable()
                    ->color('gray'),
                TextColumn::make('closing_balance')
                    ->label(__('admin.fields.closing_balance'))
                    ->money('EGP')
                    ->sortable()
                    ->alignRight(),
                // The cheapest signal that a file was truncated, half-mapped, or had its signs read
                // backwards: does the BANK's own arithmetic hold? It says nothing about the books,
                // and everything about whether this statement was ingested faithfully — which is the
                // precondition for matching anything against it.
                // The ageing, where it is actually seen: an operator scanning the statement list
                // should not have to open each one to find the questions nobody has asked.
                TextColumn::make('aged_unmatched')
                    ->label(__('admin.bank.aged_unmatched'))
                    ->badge()
                    ->getStateUsing(function (BankStatement $record) {
                        $count = $record->agedUnmatchedCount();

                        return $count > 0 ? __('admin.bank.aged_count', ['count' => $count]) : null;
                    })
                    ->color('danger')
                    ->placeholder('—')
                    ->tooltip(__('admin.bank.aged_unmatched_hint')),
                TextColumn::make('consistency')
                    ->label(__('admin.fields.statement_consistent'))
                    ->badge()
                    ->getStateUsing(fn (BankStatement $record) => $record->isSelfConsistent()
                        ? __('admin.bank.consistent')
                        : __('admin.bank.inconsistent'))
                    ->color(fn (BankStatement $record) => $record->isSelfConsistent() ? 'success' : 'danger')
                    ->tooltip(__('admin.helpers.statement_consistent')),
            ])
            // The register had no filters at all: once a mall has a year of statements
            // across two or three accounts, "the July CIB one" was a scroll, not a query.
            ->filters([
                // Property-scoped: the options are the accounts this user can already see,
                // so the filter can never name an account from another mall.
                EntitySelectFilter::make('bank_account_id')
                    ->label(__('admin.resources.bank_account.singular'))
                    ->entity(BankAccount::class),
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
                    // A statement OVERLAPPING the window, not one contained by it — asking
                    // for "July" must return the statement that runs 25 Jun – 24 Jul.
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['period_from'] ?? null, fn (Builder $q, $date) => $q->whereDate('period_end', '>=', $date))
                        ->when($data['period_until'] ?? null, fn (Builder $q, $date) => $q->whereDate('period_start', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['period_from'] ?? null) {
                            $indicators[] = __('admin.filters.period_from').': '.Carbon::parse($data['period_from'])->format('d/m/Y');
                        }
                        if ($data['period_until'] ?? null) {
                            $indicators[] = __('admin.filters.period_until').': '.Carbon::parse($data['period_until'])->format('d/m/Y');
                        }

                        return $indicators;
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()->visible(fn (BankStatement $record) => BankStatementResource::canEdit($record)),
            ])
            ->defaultSort('period_end', 'desc')
            ->emptyStateIcon('heroicon-o-document-check')
            ->emptyStateHeading(__('admin.empty.bank_statements.heading'))
            ->emptyStateDescription(__('admin.empty.bank_statements.description'));
    }
}
