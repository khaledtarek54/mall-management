<?php

namespace App\Filament\Admin\Resources\PostDatedCheques\Tables;

use App\Filament\Admin\Resources\PostDatedCheques\PostDatedChequeResource;
use App\Models\PostDatedCheque;
use App\Support\Filament\BankAccountColumn;
use App\Support\Filament\BankAccountFilter;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PostDatedChequesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['tenant', 'asset']))
            ->columns([
                TextColumn::make('reference')->label(__('admin.post_dated_cheques.fields.reference'))->searchable()->sortable(),
                TextColumn::make('tenant.name')->label(__('admin.post_dated_cheques.fields.tenant'))->searchable()->toggleable(),
                TextColumn::make('asset.name')->label(__('admin.post_dated_cheques.fields.property'))->toggleable(),
                TextColumn::make('cheque_number')->label(__('admin.post_dated_cheques.fields.cheque_number'))->searchable(),
                TextColumn::make('amount')->label(__('admin.post_dated_cheques.fields.amount'))->money('EGP')->alignRight()->sortable()
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
                TextColumn::make('cheque_date')->label(__('admin.post_dated_cheques.fields.cheque_date'))->date()->sortable(),
                // "Which bank are we waiting on, and since when?" — the question a lodgement
                // register exists to answer, and until 2026-09-02 it could answer neither. Toggled
                // off by default: it is blank for every cheque still in the drawer, which is most
                // of them on a healthy register.
                BankAccountColumn::make()
                    ->label(__('admin.post_dated_cheques.fields.bank_account'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('deposited_on')
                    ->label(__('admin.post_dated_cheques.fields.deposited_on'))
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->label(__('admin.post_dated_cheques.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.post_dated_cheques.statuses.{$state}"))
                    ->color(fn (string $state) => match ($state) {
                        PostDatedCheque::STATUS_CLEARED => 'success',
                        PostDatedCheque::STATUS_DEPOSITED => 'info',
                        PostDatedCheque::STATUS_BOUNCED => 'danger',
                        PostDatedCheque::STATUS_CANCELLED => 'gray',
                        default => 'warning',
                    }),
            ])
            ->defaultSort('cheque_date', 'asc')
            ->filters([
                BankAccountFilter::make()
                    ->label(__('admin.post_dated_cheques.fields.bank_account')),
                SelectFilter::make('status')
                    ->label(__('admin.post_dated_cheques.fields.status'))
                    ->options(fn () => collect(PostDatedCheque::STATUSES)
                        ->mapWithKeys(fn (string $s) => [$s => __("admin.post_dated_cheques.statuses.{$s}")])->all()),
                Filter::make('matured')
                    ->label(__('admin.post_dated_cheques.filters.matured'))
                    // Shared scope with the nightly scan + the Action Required card, so all three
                    // agree on what "matured & uncleared" means.
                    ->query(fn ($query) => $query->maturedUncleared()),
            ])
            ->recordActions([
                // Read the record without opening its edit form — less
                // friction, and no write surface for view-only roles. The
                // schema is the resource's own form rendered disabled, so it
                // cannot drift from the fields that actually exist.
                ViewAction::make()
                    ->visible(fn ($record) => PostDatedChequeResource::canView($record))
                    ->authorize(fn ($record) => PostDatedChequeResource::canView($record)),
                // ── The list FINDS; the record ACTS ─────────────────────────────────────
                // Defined once in App\Filament\Admin\Actions\PostDatedChequeActions and composed onto this
                // record's own page, so opening the record is enough to act on it.
                EditAction::make()->visible(fn (PostDatedCheque $r) => $r->status === PostDatedCheque::STATUS_HELD && PostDatedChequeResource::canManage()),

            ])
            ->emptyStateIcon('heroicon-o-credit-card')
            ->emptyStateHeading(__('admin.empty.post_dated_cheques.heading'))
            ->emptyStateDescription(__('admin.empty.post_dated_cheques.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('admin.empty.post_dated_cheques.cta'))
                    ->icon('heroicon-o-plus')
                    ->visible(fn () => PostDatedChequeResource::canCreate()),
            ]);
    }
}
