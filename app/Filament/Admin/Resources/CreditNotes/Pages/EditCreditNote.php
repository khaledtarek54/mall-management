<?php

namespace App\Filament\Admin\Resources\CreditNotes\Pages;

use App\Filament\Admin\Resources\CreditNotes\CreditNoteResource;
use App\Models\Invoice;
use App\Services\CreditNoteService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditCreditNote extends EditRecord
{
    protected static string $resource = CreditNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('issue')
                ->label(__('admin.actions.issue_credit_note'))
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->visible(fn () => $this->record->status === 'draft'
                    && Auth::user()?->can('credit_notes.issue'))
                ->authorize(fn () => Auth::user()?->can('credit_notes.issue') ?? false)
                ->requiresConfirmation()
                ->action(function (): void {
                    app(CreditNoteService::class)->issue($this->record);
                    $this->refreshFormData(['status', 'balance']);
                    Notification::make()
                        ->title(__('admin.notifications.credit_note_issued'))
                        ->body($this->record->number)
                        ->success()
                        ->send();
                }),

            Action::make('apply')
                ->label(__('admin.actions.apply_to_invoice'))
                ->icon('heroicon-o-arrow-right-circle')
                ->color('primary')
                ->visible(fn () => $this->record->hasBalance() && $this->record->status !== 'void'
                    && Auth::user()?->can('credit_notes.apply'))
                ->authorize(fn () => Auth::user()?->can('credit_notes.apply') ?? false)
                ->schema([
                    Select::make('invoice_id')
                        ->label(__('admin.fields.invoice'))
                        ->options(fn () => Invoice::query()
                            ->where('tenant_id', $this->record->tenant_id)
                            ->where('balance', '>', 0)
                            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
                            ->orderByDesc('issue_date')
                            ->get()
                            ->mapWithKeys(fn ($i) => [$i->id => $i->number . ' — EGP ' . number_format((float) $i->balance, 2) . ' ' . __('admin.tables.invoice.balance')])
                            ->all())
                        ->required()
                        ->searchable(),
                    TextInput::make('amount')
                        ->label(__('admin.fields.amount'))
                        ->prefix('EGP')
                        ->numeric()
                        ->minValue(0.01)
                        ->maxValue(fn () => (float) $this->record->balance)
                        ->default(fn () => (float) $this->record->balance)
                        ->helperText(__('admin.actions.apply_amount_helper', ['max' => number_format((float) $this->record->balance, 2)])),
                ])
                ->action(function (array $data): void {
                    $invoice = Invoice::findOrFail($data['invoice_id']);
                    $applied = app(CreditNoteService::class)
                        ->applyToInvoice($this->record, $invoice, (float) $data['amount']);

                    if ($applied <= 0) {
                        Notification::make()
                            ->title(__('admin.notifications.credit_note_apply_failed'))
                            ->warning()
                            ->send();
                        return;
                    }

                    $this->refreshFormData(['status', 'applied_amount', 'balance']);

                    Notification::make()
                        ->title(__('admin.notifications.credit_note_applied'))
                        ->body(__('admin.notifications.credit_note_applied_body', [
                            'amount' => number_format($applied, 2),
                            'invoice' => $invoice->number,
                        ]))
                        ->success()
                        ->send();
                }),

            Action::make('void')
                ->label(__('admin.actions.void_credit_note'))
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => in_array($this->record->status, ['draft', 'issued']) && (float) $this->record->applied_amount <= 0
                    && Auth::user()?->can('credit_notes.void'))
                ->authorize(fn () => Auth::user()?->can('credit_notes.void') ?? false)
                ->requiresConfirmation()
                ->modalDescription(__('admin.actions.void_credit_note_confirm'))
                ->action(function (): void {
                    try {
                        app(CreditNoteService::class)->void($this->record);
                        $this->refreshFormData(['status', 'balance']);
                        Notification::make()
                            ->title(__('admin.notifications.credit_note_voided'))
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
