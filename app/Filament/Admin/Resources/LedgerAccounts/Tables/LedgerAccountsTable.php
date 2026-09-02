<?php

namespace App\Filament\Admin\Resources\LedgerAccounts\Tables;

use App\Filament\Admin\Resources\LedgerAccounts\LedgerAccountResource;
use App\Support\CashFlowSection;
use App\Support\StatementSection;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class LedgerAccountsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label(__('admin.tables.ledger_account.code'))
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->size('xs'),
                TextColumn::make('name_ar')
                    ->label(__('admin.tables.ledger_account.name_ar'))
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('name_en')
                    ->label(__('admin.tables.ledger_account.name_en'))
                    ->searchable()
                    ->color('gray'),
                TextColumn::make('type')
                    ->label(__('admin.fields.account_type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.enums.ledger_account_type.{$state}"))
                    ->color(fn (string $state) => match ($state) {
                        'asset' => 'info',
                        'liability' => 'warning',
                        'equity' => 'primary',
                        'revenue' => 'success',
                        'expense' => 'danger',
                        default => 'gray',
                    }),
                // Which section of the cash-flow statement this account's movement lands in
                // (EG-28). On screen because an accountant onboarding a chart needs to SEE what is
                // still unclassified — the form alone would mean opening every account to find out.
                TextColumn::make('cash_flow_section')
                    ->label(__('admin.fields.cash_flow_section'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state) => __("admin.enums.cash_flow_section.{$state}"))
                    // Revenue and expense net into income by TYPE and never carry a section, so a
                    // dash there is correct rather than a gap. `—` for everything else means the
                    // operating floor is being used.
                    ->placeholder('—')
                    ->toggleable(),
                // And its answer for the income statement. Same reasoning: an accountant onboarding
                // a chart has to be able to SEE which revenue and expense accounts are still
                // unclassified, and the form alone would mean opening each one to find out.
                TextColumn::make('statement_section')
                    ->label(__('admin.fields.statement_section'))
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state) => __("admin.enums.statement_section.{$state}"))
                    // A dash is correct on a balance-sheet account (no result to place) and means
                    // the operating floor on a revenue or expense one.
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('normal_balance')
                    ->label(__('admin.fields.normal_balance'))
                    ->formatStateUsing(fn (string $state) => __("admin.enums.normal_balance.{$state}"))
                    ->color('gray')
                    ->toggleable(),
                IconColumn::make('is_postable')
                    ->label(__('admin.fields.is_postable'))
                    ->boolean(),
                IconColumn::make('is_active')
                    ->label(__('admin.fields.is_active'))
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('admin.fields.account_type'))
                    ->options(fn () => __('admin.enums.ledger_account_type')),
                // The question an accountant actually asks when a new chart lands: what have I not
                // classified yet? Balance-sheet accounts only — revenue and expense never carry one.
                SelectFilter::make('cash_flow_section')
                    ->label(__('admin.fields.cash_flow_section'))
                    ->options(fn (): array => collect(CashFlowSection::SECTIONS)
                        ->mapWithKeys(fn (string $x): array => [$x => __('admin.enums.cash_flow_section.'.$x)])
                        ->all() + ['__none' => __('admin.enums.cash_flow_section_unset')])
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        null, '' => $query,
                        '__none' => $query->whereNull('cash_flow_section')
                            ->whereNotIn('type', ['revenue', 'expense']),
                        default => $query->where('cash_flow_section', $data['value']),
                    }),
                // The same question for the other statement — and the one that matters more on
                // a fresh import, because an unclassified account here silently carries a financing
                // cost above the NOI line.
                SelectFilter::make('statement_section')
                    ->label(__('admin.fields.statement_section'))
                    ->options(fn (): array => collect(StatementSection::SECTIONS)
                        ->mapWithKeys(fn (string $x): array => [$x => __('admin.enums.statement_section.'.$x)])
                        ->all() + ['__none' => __('admin.enums.statement_section_unset')])
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        null, '' => $query,
                        // Only revenue and expense can BE unclassified here — the mirror of the
                        // cash-flow filter's exclusion, in the opposite direction.
                        '__none' => $query->whereNull('statement_section')
                            ->whereIn('type', ['revenue', 'expense']),
                        default => $query->where('statement_section', $data['value']),
                    }),
                TernaryFilter::make('is_postable')
                    ->label(__('admin.fields.is_postable')),
                TernaryFilter::make('is_active')
                    ->label(__('admin.fields.is_active')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                // Read the record without opening its edit form — less
                // friction, and no write surface for view-only roles. The
                // schema is the resource's own form rendered disabled, so it
                // cannot drift from the fields that actually exist.
                ViewAction::make()
                    ->visible(fn ($record) => LedgerAccountResource::canView($record))
                    ->authorize(fn ($record) => LedgerAccountResource::canView($record)),
                EditAction::make()
                    ->visible(fn ($record) => LedgerAccountResource::canEdit($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () => LedgerAccountResource::canDeleteAny()),
                ]),
            ])
            ->defaultSort('code')
            ->paginated([25, 50, 100, 'all'])
            ->emptyStateIcon('heroicon-o-rectangle-stack')
            ->emptyStateHeading(__('admin.empty.ledger_accounts.heading'))
            ->emptyStateDescription(__('admin.empty.ledger_accounts.description'));
    }
}
