<?php

namespace App\Filament\Admin\Resources\PostDatedCheques\Tables;

use App\Filament\Admin\Resources\PostDatedCheques\PostDatedChequeResource;
use App\Models\PostDatedCheque;
use App\Services\PostDatedChequeService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PostDatedChequesTable
{
    private static function notifyFailure(\Throwable $e): void
    {
        Notification::make()->title($e->getMessage())->danger()->send();
    }

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
                EditAction::make()->visible(fn (PostDatedCheque $r) => $r->status === PostDatedCheque::STATUS_HELD && PostDatedChequeResource::canManage()),

                Action::make('deposit')
                    ->label(__('admin.post_dated_cheques.actions.deposit'))
                    ->icon('heroicon-o-arrow-up-tray')->color('info')
                    ->visible(fn (PostDatedCheque $r) => in_array($r->status, [PostDatedCheque::STATUS_HELD, PostDatedCheque::STATUS_BOUNCED], true) && PostDatedChequeResource::canManage())
                    ->authorize(fn (PostDatedCheque $r) => PostDatedChequeResource::canManage())
                    ->action(function (PostDatedCheque $record): void {
                        abort_unless(PostDatedChequeResource::canManage(), 403);
                        self::run(fn () => app(PostDatedChequeService::class)->deposit($record), 'deposited');
                    }),

                Action::make('clear')
                    ->label(__('admin.post_dated_cheques.actions.clear'))
                    ->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (PostDatedCheque $r) => in_array($r->status, [PostDatedCheque::STATUS_HELD, PostDatedCheque::STATUS_DEPOSITED], true) && PostDatedChequeResource::canClear())
                    ->authorize(fn (PostDatedCheque $r) => PostDatedChequeResource::canClear())
                    ->schema([
                        DatePicker::make('cleared_on')
                            ->label(__('admin.post_dated_cheques.fields.cleared_on'))
                            ->default(fn () => now()->toDateString())
                            ->maxDate(fn () => now())
                            ->required(),
                    ])
                    ->action(function (PostDatedCheque $record, array $data): void {
                        abort_unless(PostDatedChequeResource::canClear(), 403);
                        self::run(fn () => app(PostDatedChequeService::class)->clear($record, auth()->user(), $data['cleared_on']), 'cleared');
                    }),

                Action::make('bounce')
                    ->label(__('admin.post_dated_cheques.actions.bounce'))
                    ->icon('heroicon-o-x-circle')->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (PostDatedCheque $r) => in_array($r->status, [PostDatedCheque::STATUS_HELD, PostDatedCheque::STATUS_DEPOSITED], true) && PostDatedChequeResource::canManage())
                    ->authorize(fn (PostDatedCheque $r) => PostDatedChequeResource::canManage())
                    ->action(function (PostDatedCheque $record): void {
                        abort_unless(PostDatedChequeResource::canManage(), 403);
                        self::run(fn () => app(PostDatedChequeService::class)->bounce($record), 'bounced');
                    }),

                Action::make('cancel')
                    ->label(__('admin.post_dated_cheques.actions.cancel'))
                    ->icon('heroicon-o-trash')->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (PostDatedCheque $r) => ! in_array($r->status, [PostDatedCheque::STATUS_CLEARED, PostDatedCheque::STATUS_CANCELLED], true) && PostDatedChequeResource::canManage())
                    ->authorize(fn (PostDatedCheque $r) => PostDatedChequeResource::canManage())
                    ->action(function (PostDatedCheque $record): void {
                        abort_unless(PostDatedChequeResource::canManage(), 403);
                        self::run(fn () => app(PostDatedChequeService::class)->cancel($record), 'cancelled');
                    }),
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

    /** Run a lifecycle transition with the standard try/catch + success toast. */
    private static function run(callable $fn, string $noticeKey): void
    {
        try {
            $fn();
        } catch (\DomainException $e) {
            self::notifyFailure($e);

            return;
        }
        Notification::make()->title(__("admin.post_dated_cheques.notices.{$noticeKey}"))->success()->send();
    }
}
