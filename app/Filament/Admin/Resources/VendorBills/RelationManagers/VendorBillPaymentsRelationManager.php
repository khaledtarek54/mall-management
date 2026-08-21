<?php

namespace App\Filament\Admin\Resources\VendorBills\RelationManagers;

use App\Models\PaymentMethod;
use App\Models\VendorBill;
use App\Models\VendorBillPayment;
use App\Services\VoidVendorBillPaymentService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The payments settling this bill. Payments are RECORDED via the "Record payment" header action
 * (lock-safe, capped at the balance), so this manager creates and edits nothing — but it does own
 * the one correction: **voiding** a payment keyed by mistake.
 *
 * A voided row stays visible, struck through and reasoned, because that is the difference between
 * voiding and deleting: the register still shows the cheque that was cancelled.
 */
class VendorBillPaymentsRelationManager extends RelationManager
{
    protected static string $relationship = 'payments';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.relation_managers.vendor_bill_payments');
    }

    /**
     * The ONE predicate behind both halves of the void gate. Named once so the UI and the
     * authorization cannot drift apart — `visible()` is not an authorization gate, so both are
     * required and both must say the same thing.
     */
    private static function canVoid(VendorBillPayment $payment): bool
    {
        return ! $payment->isVoided() && (Auth::user()?->can('vendor_bills.void_payment') ?? false);
    }

    public function table(Table $table): Table
    {
        return $table
            // No search box: VendorBillPayment carries no `search_text` blob (it is not a
            // record anyone hunts for by name) and this table marks no column
            // searchable. Without this, TableDefaults' blob search would still render
            // the box — and a search box that always returns nothing is worse than
            // none, because it reads as "no such row". See App\Support\SearchPolicy.
            ->searchable(false)
            ->columns([
                TextColumn::make('reference')
                    ->label(__('admin.fields.reference'))
                    ->placeholder('—'),
                TextColumn::make('amount')
                    ->label(__('admin.fields.amount'))
                    ->money('EGP')
                    ->weight('bold')
                    // A voided payment settles nothing, so it must not read like one that did.
                    ->color(fn (VendorBillPayment $record) => $record->isVoided() ? 'gray' : null)
                    ->description(fn (VendorBillPayment $record) => $record->isVoided()
                        ? __('admin.fields.voided').($record->void_reason ? ' — '.$record->void_reason : '')
                        : null),
                TextColumn::make('method')
                    ->label(__('admin.fields.method'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => PaymentMethod::labelFor($state, 'admin.enums.vendor_bill_payment_method'))
                    ->color('gray'),
                TextColumn::make('payment_date')
                    ->label(__('admin.fields.payment_date'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('voided_at')
                    ->label(__('admin.fields.voided_at'))
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->color('danger')
                    ->toggleable(),
                TextColumn::make('notes')
                    ->label(__('admin.fields.notes'))
                    ->placeholder('—')
                    ->limit(60),
            ])
            ->headerActions([])
            ->recordActions([
                Action::make('void_payment')
                    ->label(__('admin.actions.void_vendor_payment'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (VendorBillPayment $record) => self::canVoid($record))
                    // The real gate. `visible()` states the intent; this enforces it — and the two
                    // read the same predicate so a change to one cannot silently widen the other.
                    ->authorize(fn (VendorBillPayment $record) => self::canVoid($record))
                    ->requiresConfirmation()
                    ->modalDescription(fn (VendorBillPayment $record) => __(
                        'admin.actions.void_vendor_payment_confirm',
                        ['amount' => number_format((float) $record->amount, 2).' EGP'],
                    ))
                    ->schema([
                        Textarea::make('reason')
                            ->label(__('admin.fields.void_reason'))
                            ->required()
                            ->maxLength(500),
                    ])
                    ->action(function (VendorBillPayment $record, array $data): void {
                        abort_unless(self::canVoid($record), 403);

                        app(VoidVendorBillPaymentService::class)->void($record, $data['reason'] ?? null);

                        // Re-read the bill rather than using the loaded relation: the void
                        // recomputed it via saveQuietly, so an in-memory copy still holds the
                        // balance from before the reversal and the toast would report the
                        // opposite of what happened.
                        $balance = (float) VendorBill::whereKey($record->vendor_bill_id)->value('balance');

                        Notification::make()
                            ->success()
                            ->title(__('admin.actions.void_vendor_payment_done', [
                                'balance' => number_format($balance, 2).' EGP',
                            ]))
                            ->send();
                    }),
            ])
            ->toolbarActions([])
            ->defaultSort('payment_date', 'desc');
    }
}
