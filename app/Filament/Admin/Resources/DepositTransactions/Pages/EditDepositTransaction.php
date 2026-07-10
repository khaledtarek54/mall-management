<?php

namespace App\Filament\Admin\Resources\DepositTransactions\Pages;

use App\Filament\Admin\Resources\DepositTransactions\DepositTransactionResource;
use App\Models\Lease;
use App\Services\DepositService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditDepositTransaction extends EditRecord
{
    protected static string $resource = DepositTransactionResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Block re-homing via a tampered lease_id (asset is derived from the lease).
        DepositTransactionResource::assertAssetInScope(
            Lease::with('unit')->find($data['lease_id'] ?? $this->record->lease_id)?->unit?->asset_id
        );

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('cancel_deposit')
                ->label(__('admin.actions.cancel_deposit'))
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => $this->record->status === 'recorded'
                    && Auth::user()?->can('deposit_transactions.edit'))
                ->authorize(fn () => Auth::user()?->can('deposit_transactions.edit') ?? false)
                ->requiresConfirmation()
                ->modalDescription(__('admin.actions.cancel_deposit_confirm'))
                ->action(function (): void {
                    // The accounting:sync-ledger sweep voids the ledger entry for a
                    // cancelled deposit on its next run.
                    app(DepositService::class)->cancel($this->record);
                    $this->refreshFormData(['status']);
                    Notification::make()
                        ->title(__('admin.notifications.deposit_cancelled'))
                        ->success()
                        ->send();
                }),

            DeleteAction::make(),
        ];
    }
}
