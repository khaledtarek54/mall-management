<?php

namespace App\Filament\Admin\Resources\VendorBills\Pages;

use App\Filament\Admin\Resources\VendorBills\VendorBillResource;
use App\Services\VendorBillService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class EditVendorBill extends EditRecord
{
    protected static string $resource = VendorBillResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label(__('admin.actions.approve_bill'))
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => $this->record->status === 'draft'
                    && Auth::user()?->can('vendor_bills.approve'))
                ->requiresConfirmation()
                ->action(function (): void {
                    app(VendorBillService::class)->approve($this->record);
                    $this->refreshFormData(['status']);
                    Notification::make()
                        ->title(__('admin.notifications.vendor_bill_approved'))
                        ->body($this->record->number)
                        ->success()
                        ->send();
                }),

            Action::make('record_payment')
                ->label(__('admin.actions.record_payment'))
                ->icon('heroicon-o-banknotes')
                ->color('primary')
                ->visible(fn () => $this->record->isPostable()
                    && (float) $this->record->balance > 0
                    && Auth::user()?->can('vendor_bills.pay'))
                ->schema([
                    TextInput::make('amount')
                        ->label(__('admin.fields.amount'))
                        ->prefix('EGP')
                        ->numeric()
                        ->minValue(0.01)
                        ->maxValue(fn () => (float) $this->record->balance)
                        ->default(fn () => (float) $this->record->balance)
                        ->required(),
                    Select::make('method')
                        ->label(__('admin.fields.method'))
                        ->options(fn () => __('admin.enums.vendor_bill_payment_method'))
                        ->default('bank_transfer')
                        ->native(false)
                        ->required(),
                    DatePicker::make('payment_date')
                        ->label(__('admin.fields.payment_date'))
                        ->default(now())
                        ->native(false)
                        ->required(),
                    Textarea::make('notes')
                        ->label(__('admin.fields.notes'))
                        ->rows(2),
                ])
                ->action(function (array $data): void {
                    $paid = app(VendorBillService::class)->recordPayment(
                        $this->record,
                        (float) $data['amount'],
                        $data['method'],
                        Carbon::parse($data['payment_date']),
                        $data['notes'] ?? null,
                    );

                    // recordPayment re-loads + mutates a locked instance, so the
                    // page's $this->record is stale — refresh it before re-filling
                    // the form (the documented stale-instance gotcha).
                    $this->record->refresh();
                    $this->refreshFormData(['status', 'paid_amount', 'balance']);

                    if ($paid <= 0) {
                        Notification::make()
                            ->title(__('admin.notifications.vendor_bill_paid'))
                            ->warning()
                            ->send();
                        return;
                    }

                    Notification::make()
                        ->title(__('admin.notifications.vendor_bill_paid'))
                        ->body('EGP ' . number_format($paid, 2))
                        ->success()
                        ->send();
                }),

            Action::make('cancel_bill')
                ->label(__('admin.actions.cancel_bill'))
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => in_array($this->record->status, ['draft', 'approved'], true)
                    && (float) $this->record->paid_amount <= 0
                    && Auth::user()?->can('vendor_bills.edit'))
                ->requiresConfirmation()
                ->action(function (): void {
                    try {
                        app(VendorBillService::class)->cancel($this->record);
                        $this->refreshFormData(['status', 'balance']);
                        Notification::make()
                            ->title(__('admin.notifications.vendor_bill_cancelled'))
                            ->success()
                            ->send();
                    } catch (\DomainException $e) {
                        Notification::make()
                            ->title($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),

            DeleteAction::make(),
        ];
    }
}
