<?php

namespace App\Filament\Admin\Resources\Expenses\Pages;

use App\Filament\Actions\LedgerEntryAction;
use App\Filament\Actions\ReversalReasonField;
use App\Filament\Admin\Resources\Expenses\ExpenseResource;
use App\Services\ExpenseService;
use App\Support\Filament\AnnouncesLedgerRestatement;
use App\Support\Filament\RefreshesRecordState;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditExpense extends EditRecord
{
    use AnnouncesLedgerRestatement;
    use RefreshesRecordState;

    /**
     * Approval state is re-derived by the service, not typed on this form.
     *
     * @return array<int, string>
     */
    protected function derivedStatePaths(): array
    {
        return ['status'];
    }

    protected static string $resource = ExpenseResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Block re-homing the record into a property outside the user's visible set.
        ExpenseResource::assertAssetInScope($data['asset_id'] ?? $this->record->asset_id);

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
            Action::make('cancel_expense')
                ->label(__('admin.actions.cancel_expense'))
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => $this->record->status === 'recorded'
                    && Auth::user()?->can('expenses.edit'))
                ->authorize(fn () => Auth::user()?->can('expenses.edit') ?? false)
                ->requiresConfirmation()
                ->modalDescription(__('admin.actions.cancel_expense_confirm'))
                ->schema([ReversalReasonField::make()])
                ->action(function (array $data): void {
                    // The accounting:sync-ledger sweep voids the ledger entry for a
                    // cancelled expense on its next run (LedgerPoster::sync).
                    app(ExpenseService::class)->cancel($this->record, $data['reason'] ?? null);
                    $this->refreshFormData(['status']);
                    Notification::make()
                        ->title(__('admin.notifications.expense_cancelled'))
                        ->success()
                        ->send();
                }),
        ];
    }
}
