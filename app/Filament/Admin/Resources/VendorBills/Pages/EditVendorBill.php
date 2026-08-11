<?php

namespace App\Filament\Admin\Resources\VendorBills\Pages;

use App\Filament\Admin\Resources\VendorBills\VendorBillResource;
use App\Services\VendorBillService;
use App\Support\PostingDate;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class EditVendorBill extends EditRecord
{
    protected static string $resource = VendorBillResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Block re-homing the record into a property outside the user's visible set.
        VendorBillResource::assertAssetInScope($data['asset_id'] ?? $this->record->asset_id);

        // Guard a CHANGED bill_date the same way create does (F-89/F-93 class: a closed-period
        // date commits the row but its GL post silently fails). An UNCHANGED date is skipped —
        // a bill legitimately posted before a month closed must stay editable for its other
        // fields without re-validating the now-sealed period. (When the bill is locked the
        // field is disabled and never submitted, so this only fires on a real edit.)
        $submitted = $data['bill_date'] ?? null;
        if ($submitted !== null
            && Carbon::parse($submitted)->toDateString() !== $this->record->bill_date?->toDateString()) {
            try {
                PostingDate::assertOpen($submitted, __('admin.fields.bill_date'));
            } catch (\DomainException $e) {
                throw ValidationException::withMessages(['data.bill_date' => $e->getMessage()]);
            }
        }

        // Editing a bill ONTO a reference another live bill already holds is the same duplicate
        // as creating one. Rendered on the field, mirroring the bill_date guard above.
        try {
            (clone $this->record)->forceFill($data)->assertVendorReferenceNotAlreadyBilled();
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['data.reference' => $e->getMessage()]);
        }

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('approve')
                ->label(__('admin.actions.approve_bill'))
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => $this->record->status === 'draft'
                    && Auth::user()?->can('vendor_bills.approve'))
                ->authorize(fn () => Auth::user()?->can('vendor_bills.approve') ?? false)
                ->requiresConfirmation()
                ->action(function (): void {
                    try {
                        app(VendorBillService::class)->approve($this->record);
                    } catch (\DomainException $e) {
                        // A closed bill_date period refuses recognition — surface it, don't 500.
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }
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
                ->authorize(fn () => Auth::user()?->can('vendor_bills.pay') ?? false)
                ->schema([
                    TextInput::make('amount')
                        ->label(__('admin.fields.amount'))
                        ->helperText(__('admin.vendors.wht.gross_hint'))
                        ->prefix('EGP')
                        ->numeric()
                        ->minValue(0.01)
                        ->maxValue(fn () => (float) $this->record->balance)
                        ->default(fn () => (float) $this->record->balance)
                        ->live(onBlur: true)
                        ->required(),
                    // Withholding is statutory, so the operator must SEE what the bank will
                    // actually pay before they commit — a gross figure alone hides the fact that
                    // the vendor receives less and the difference is owed to the ETA.
                    Placeholder::make('wht_breakdown')
                        ->label(__('admin.vendors.wht.breakdown'))
                        ->visible(fn () => \App\Support\WithholdingTax::rateFor($this->record->vendor) > 0)
                        ->content(function (Get $get) {
                            $gross = (float) ($get('amount') ?: 0);
                            $withheld = \App\Support\WithholdingTax::on($gross, $this->record->vendor);

                            return __('admin.vendors.wht.breakdown_text', [
                                'gross' => number_format($gross, 2),
                                'rate' => rtrim(rtrim(number_format(
                                    \App\Support\WithholdingTax::rateFor($this->record->vendor), 2), '0'), '.'),
                                'withheld' => number_format($withheld, 2),
                                'net' => number_format($gross - $withheld, 2),
                            ]);
                        }),
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
                    try {
                        $paid = app(VendorBillService::class)->recordPayment(
                            $this->record,
                            (float) $data['amount'],
                            $data['method'],
                            Carbon::parse($data['payment_date']),
                            $data['notes'] ?? null,
                        );
                    } catch (\DomainException $e) {
                        // A back-dated payment into a closed period is refused (would strand the GL) —
                        // surface it as a toast, not a 500.
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

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

                    // Feedback must state what actually left the bank. Reporting only the gross
                    // would let the operator reconcile against a figure the statement never shows.
                    $withheld = (float) ($this->record->payments()->latest('id')->value('withholding_amount') ?? 0);

                    Notification::make()
                        ->title(__('admin.notifications.vendor_bill_paid'))
                        ->body($withheld > 0
                            ? __('admin.vendors.wht.paid_body', [
                                'net' => number_format($paid - $withheld, 2),
                                'withheld' => number_format($withheld, 2),
                                'gross' => number_format($paid, 2),
                            ])
                            : 'EGP '.number_format($paid, 2))
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
                ->authorize(fn () => Auth::user()?->can('vendor_bills.edit') ?? false)
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
        ];
    }
}
