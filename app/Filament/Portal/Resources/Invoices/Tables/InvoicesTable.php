<?php

namespace App\Filament\Portal\Resources\Invoices\Tables;

use App\Actions\Api\V1\Payments\RecordDemoPaymentAction;
use App\Models\Invoice;
use App\Services\InvoicePdfService;
use App\Services\Paymob\PaymobPaymentInitiator;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('lease.unit'))
            ->columns([
                TextColumn::make('number')
                    ->label(__('admin.tables.invoice.number'))
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->size('xs'),
                TextColumn::make('lease.unit.code')
                    ->label(__('admin.tables.invoice.unit'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('period_start')
                    ->label(__('admin.tables.invoice.period'))
                    ->formatStateUsing(fn ($record) => $record->period_start?->locale(app()->getLocale())->isoFormat('MMM YYYY') ?? '—'),
                TextColumn::make('total')
                    ->label(__('admin.tables.invoice.total'))
                    ->money('EGP')
                    ->sortable()
                    ->alignRight(),
                TextColumn::make('paid_amount')
                    ->label(__('admin.tables.invoice.paid'))
                    ->money('EGP')
                    ->color('success')
                    ->alignRight(),
                TextColumn::make('balance')
                    ->label(__('admin.tables.invoice.balance'))
                    ->money('EGP')
                    ->sortable()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success')
                    ->weight('bold')
                    ->alignRight(),
                TextColumn::make('due_date')
                    ->label(__('admin.tables.invoice.due_date'))
                    ->date('d/m/Y')
                    ->sortable()
                    ->color(function ($record) {
                        if (in_array($record->status, ['paid', 'cancelled'])) {
                            return null;
                        }

                        return $record->due_date?->isPast() ? 'danger' : null;
                    }),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.invoice.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'paid' => 'success',
                        'partially_paid' => 'warning',
                        'overdue' => 'danger',
                        'issued' => 'info',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => collect(__('admin.statuses.invoice'))->only(['issued', 'partially_paid', 'paid', 'overdue'])->all()),
                SelectFilter::make('unit_id')
                    ->label(__('admin.filters.unit'))
                    ->options(function (): array {
                        $tenant = \App\Support\Portal::tenant();
                        if (! $tenant) {
                            return [];
                        }

                        return $tenant->leases()
                            ->with('unit')
                            ->get()
                            ->pluck('unit.code', 'unit.id')
                            ->filter()
                            ->all();
                    })
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['value'] ?? null, fn (Builder $q, $unitId) => $q->whereHas('lease', fn (Builder $l) => $l->where('unit_id', $unitId)))),
                Filter::make('period')
                    ->label(__('admin.filters.period'))
                    ->schema([
                        DatePicker::make('period_from')
                            ->label(__('admin.filters.period_from'))
                            ->native(false),
                        DatePicker::make('period_until')
                            ->label(__('admin.filters.period_until'))
                            ->native(false),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['period_from'] ?? null, fn (Builder $q, $date) => $q->whereDate('period_start', '>=', $date))
                        ->when($data['period_until'] ?? null, fn (Builder $q, $date) => $q->whereDate('period_start', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['period_from'] ?? null) {
                            $indicators[] = __('admin.filters.period_from').': '.Carbon::parse($data['period_from'])->format('d/m/Y');
                        }
                        if ($data['period_until'] ?? null) {
                            $indicators[] = __('admin.filters.period_until').': '.Carbon::parse($data['period_until'])->format('d/m/Y');
                        }

                        return $indicators;
                    }),
                Filter::make('unpaid_only')
                    ->label(__('admin.filters.overdue_only'))
                    ->query(fn (Builder $query) => $query->where('balance', '>', 0)),
            ])
            ->filtersFormColumns(2)
            ->recordActions([
                ViewAction::make(),
                Action::make('downloadPdf')
                    ->label(__('admin.actions.pdf'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->action(function (Invoice $record) {
                        $svc = app(InvoicePdfService::class);
                        $pdf = $svc->build($record);

                        return response()->streamDownload(
                            fn () => print ($pdf),
                            $svc->filename($record),
                            ['Content-Type' => 'application/pdf'],
                        );
                    }),
                Action::make('payNow')
                    ->label(__('admin.actions.pay_now'))
                    ->icon('heroicon-o-credit-card')
                    ->color('primary')
                    ->visible(fn ($record) => config('integrations.paymob.enabled') && $record->balance > 0)
                    ->requiresConfirmation()
                    ->modalHeading(fn ($record) => __('admin.actions.pay_now').' · '.$record->number)
                    ->action(function (Invoice $record) {
                        try {
                            $session = app(PaymobPaymentInitiator::class)->start($record, \App\Models\Payment::CHANNEL_PORTAL);

                            return redirect()->away($session['iframe_url']);
                        } catch (\Throwable $e) {
                            Log::warning('Paymob Pay Now failed', [
                                'invoice_id' => $record->id,
                                'error' => $e->getMessage(),
                            ]);
                            Notification::make()
                                ->danger()
                                ->title(__('admin.notifications.pay_now_failed'))
                                ->body(__('admin.notifications.pay_now_failed_body'))
                                ->send();
                        }
                    }),
                // Demo payment — shown only while Paymob is disabled. Records a
                // successful payment through the real capture path (same as the
                // mobile pay-demo endpoint): invoice → paid, payment created,
                // tenant notified. Lets the portal demonstrate the full
                // post-payment flow without a live gateway.
                Action::make('payDemo')
                    ->label(__('admin.actions.pay_now'))
                    ->icon('heroicon-o-credit-card')
                    ->color('primary')
                    ->visible(fn (Invoice $record) => ! config('integrations.paymob.enabled')
                        && (float) $record->balance > 0
                        && ! in_array($record->status, ['cancelled', 'credited'], true))
                    ->requiresConfirmation()
                    ->modalHeading(fn (Invoice $record) => __('admin.actions.pay_now').' · '.$record->number)
                    ->modalDescription(fn (Invoice $record) => __('admin.actions.pay_demo_modal_body', [
                        'amount' => number_format((float) $record->balance, 2),
                    ]))
                    ->modalSubmitActionLabel(__('admin.actions.pay_now'))
                    ->action(function (Invoice $record) {
                        app(RecordDemoPaymentAction::class)->handle($record);

                        Notification::make()
                            ->success()
                            ->title(__('admin.notifications.payment_received_title'))
                            ->body(__('admin.actions.pay_demo_success', ['number' => $record->number]))
                            ->send();
                    }),
            ])
            ->defaultSort('issue_date', 'desc');
    }
}
