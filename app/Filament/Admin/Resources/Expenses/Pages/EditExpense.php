<?php

namespace App\Filament\Admin\Resources\Expenses\Pages;

use App\Filament\Admin\Resources\Expenses\ExpenseResource;
use App\Services\ExpenseService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditExpense extends EditRecord
{
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
            Action::make('cancel_expense')
                ->label(__('admin.actions.cancel_expense'))
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => $this->record->status === 'recorded'
                    && Auth::user()?->can('expenses.edit'))
                ->authorize(fn () => Auth::user()?->can('expenses.edit') ?? false)
                ->requiresConfirmation()
                ->modalDescription(__('admin.actions.cancel_expense_confirm'))
                ->action(function (): void {
                    // The accounting:sync-ledger sweep voids the ledger entry for a
                    // cancelled expense on its next run (LedgerPoster::sync).
                    app(ExpenseService::class)->cancel($this->record);
                    $this->refreshFormData(['status']);
                    Notification::make()
                        ->title(__('admin.notifications.expense_cancelled'))
                        ->success()
                        ->send();
                }),

            DeleteAction::make(),
        ];
    }
}
