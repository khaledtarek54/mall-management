<?php

namespace App\Filament\Admin\Resources\Payrolls\Pages;

use App\Filament\Admin\Resources\Payrolls\PayrollResource;
use App\Services\PayrollService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditPayroll extends EditRecord
{
    protected static string $resource = PayrollResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Block re-homing the record into a property outside the user's visible set.
        PayrollResource::assertAssetInScope($data['asset_id'] ?? $this->record->asset_id);

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label(__('admin.actions.approve_payroll'))
                ->icon('heroicon-o-check-circle')
                ->color('success')
                // visible() only hides the button; authorize() enforces the gate
                // server-side (the documented Filament-authz gotcha — custom
                // actions default to ALLOWED, so gate every one explicitly).
                ->visible(fn () => $this->record->status === 'draft'
                    && Auth::user()?->can('payrolls.approve'))
                ->authorize(fn () => Auth::user()?->can('payrolls.approve') ?? false)
                ->requiresConfirmation()
                ->action(function (): void {
                    try {
                        app(PayrollService::class)->approve($this->record);
                    } catch (\DomainException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }
                    $this->refreshFormData(['status']);
                    Notification::make()
                        ->title(__('admin.notifications.payroll_approved'))
                        ->body($this->record->number)
                        ->success()
                        ->send();
                }),

            Action::make('cancel_payroll')
                ->label(__('admin.actions.cancel_payroll'))
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => in_array($this->record->status, ['draft', 'approved'], true)
                    && Auth::user()?->can('payrolls.edit'))
                ->authorize(fn () => Auth::user()?->can('payrolls.edit') ?? false)
                ->requiresConfirmation()
                ->modalDescription(__('admin.actions.cancel_payroll_confirm'))
                ->action(function (): void {
                    // The accounting:sync-ledger sweep voids the ledger entry for a
                    // cancelled run on its next run (PayrollJournalizer).
                    app(PayrollService::class)->cancel($this->record);
                    $this->refreshFormData(['status']);
                    Notification::make()
                        ->title(__('admin.notifications.payroll_cancelled'))
                        ->success()
                        ->send();
                }),

            DeleteAction::make(),
        ];
    }
}
