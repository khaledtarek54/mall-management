<?php

namespace App\Filament\Admin\Resources\DepositTransactions\Pages;

use App\Filament\Actions\LedgerEntryAction;
use App\Filament\Actions\ReversalReasonField;
use App\Filament\Admin\Resources\DepositTransactions\DepositTransactionResource;
use App\Models\Lease;
use App\Services\DepositService;
use App\Support\Filament\AnnouncesLedgerRestatement;
use App\Support\Filament\RefreshesRecordState;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditDepositTransaction extends EditRecord
{
    use AnnouncesLedgerRestatement;
    use RefreshesRecordState;

    /**
     * Refund / forfeit move the movement's state from elsewhere on the screen.
     *
     * @return array<int, string>
     */
    protected function derivedStatePaths(): array
    {
        return ['status'];
    }

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
            // **The ledger panel, on the screen where the edit happens.** The factory has existed
            // since CHANGE-IMPACT-PLAN §6.1 and was mounted on five LIST tables only — which is
            // where you audit, not where you act. An operator about to retype a figure could not
            // see what the document had already done to the books without leaving the page.
            LedgerEntryAction::make(),
            Action::make('cancel_deposit')
                ->label(__('admin.actions.cancel_deposit'))
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => $this->record->status === 'recorded'
                    && Auth::user()?->can('deposit_transactions.edit'))
                ->authorize(fn () => Auth::user()?->can('deposit_transactions.edit') ?? false)
                ->requiresConfirmation()
                ->modalDescription(__('admin.actions.cancel_deposit_confirm'))
                ->schema([ReversalReasonField::make()])
                ->action(function (array $data): void {
                    // The accounting:sync-ledger sweep voids the ledger entry for a
                    // cancelled deposit on its next run.
                    app(DepositService::class)->cancel($this->record, $data['reason'] ?? null);
                    $this->refreshFormData(['status']);
                    Notification::make()
                        ->title(__('admin.notifications.deposit_cancelled'))
                        ->success()
                        ->send();
                }),
        ];
    }
}
