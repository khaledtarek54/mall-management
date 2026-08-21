<?php

namespace App\Filament\Admin\Resources\Disbursements\Tables;

use App\Filament\Admin\Resources\Disbursements\DisbursementResource;
use App\Models\Disbursement;
use App\Models\PaymentMethod;
use App\Services\OwnerAccounting\DisbursementService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DisbursementsTable
{
    private static function notifyFailure(\Throwable $e): void
    {
        Notification::make()->title($e->getMessage())->danger()->send();
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['asset', 'payee']))
            ->columns([
                TextColumn::make('reference')->label(__('admin.disbursements.fields.reference'))->searchable()->sortable(),
                TextColumn::make('payee.name')->label(__('admin.disbursements.fields.owner'))->toggleable(),
                TextColumn::make('asset.name')->label(__('admin.disbursements.fields.property'))->toggleable(),
                TextColumn::make('amount')->label(__('admin.disbursements.fields.amount'))->money('EGP')->alignRight()->sortable()
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
                TextColumn::make('method')
                    ->label(__('admin.disbursements.fields.method'))
                    ->formatStateUsing(fn (?string $state) => PaymentMethod::labelFor($state, 'admin.disbursements.methods'))
                    ->toggleable(),
                TextColumn::make('status')
                    ->label(__('admin.disbursements.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.disbursements.statuses.{$state}"))
                    ->color(fn (string $state) => match ($state) {
                        Disbursement::STATUS_PAID => 'success',
                        Disbursement::STATUS_APPROVED => 'info',
                        Disbursement::STATUS_CANCELLED => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('paid_on')->label(__('admin.disbursements.fields.paid_on'))->date()->sortable()->toggleable(),
                TextColumn::make('external_reference')->label(__('admin.disbursements.fields.external_reference'))->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.disbursements.fields.status'))
                    ->options(fn () => collect(Disbursement::STATUSES)
                        ->mapWithKeys(fn (string $s) => [$s => __("admin.disbursements.statuses.{$s}")])->all()),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label(__('admin.disbursements.actions.approve'))
                    ->icon('heroicon-o-check-circle')->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (Disbursement $d) => $d->status === Disbursement::STATUS_SCHEDULED && DisbursementResource::canApprove())
                    ->authorize(fn (Disbursement $d) => DisbursementResource::canApprove())
                    ->action(function (Disbursement $record): void {
                        abort_unless(DisbursementResource::canApprove(), 403);
                        try {
                            app(DisbursementService::class)->approve($record, auth()->user());
                        } catch (\DomainException $e) {
                            self::notifyFailure($e);

                            return;
                        }
                        Notification::make()->title(__('admin.disbursements.notices.approved'))->success()->send();
                    }),

                Action::make('pay')
                    ->label(__('admin.disbursements.actions.pay'))
                    ->icon('heroicon-o-banknotes')->color('primary')
                    ->visible(fn (Disbursement $d) => $d->status === Disbursement::STATUS_APPROVED && DisbursementResource::canPay())
                    ->authorize(fn (Disbursement $d) => DisbursementResource::canPay())
                    ->schema([
                        DatePicker::make('paid_on')
                            ->label(__('admin.disbursements.fields.paid_on'))
                            ->default(fn () => now()->toDateString())
                            ->maxDate(fn () => now())
                            ->required(),
                        TextInput::make('external_reference')
                            ->label(__('admin.disbursements.fields.external_reference'))
                            ->maxLength(255),
                    ])
                    ->action(function (Disbursement $record, array $data): void {
                        abort_unless(DisbursementResource::canPay(), 403);
                        try {
                            app(DisbursementService::class)->markPaid($record, auth()->user(), $data['paid_on'], $data['external_reference'] ?? null);
                        } catch (\DomainException $e) {
                            self::notifyFailure($e);

                            return;
                        }
                        Notification::make()->title(__('admin.disbursements.notices.paid'))->success()->send();
                    }),

                Action::make('cancel')
                    ->label(__('admin.disbursements.actions.cancel'))
                    ->icon('heroicon-o-x-circle')->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (Disbursement $d) => ! $d->isPaid() && $d->status !== Disbursement::STATUS_CANCELLED && DisbursementResource::canCancel())
                    ->authorize(fn (Disbursement $d) => DisbursementResource::canCancel())
                    ->action(function (Disbursement $record): void {
                        abort_unless(DisbursementResource::canCancel(), 403);
                        try {
                            app(DisbursementService::class)->cancel($record, auth()->user());
                        } catch (\DomainException $e) {
                            self::notifyFailure($e);

                            return;
                        }
                        Notification::make()->title(__('admin.disbursements.notices.cancelled'))->success()->send();
                    }),
            ])
            ->emptyStateIcon('heroicon-o-arrow-up-tray')
            ->emptyStateHeading(__('admin.empty.disbursements.heading'))
            ->emptyStateDescription(__('admin.empty.disbursements.description'));
    }
}
