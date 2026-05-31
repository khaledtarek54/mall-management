<?php

namespace App\Filament\Admin\Resources\Invoices\Tables;

use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Exports\InvoiceExporter;
use App\Models\Invoice;
use App\Models\Unit;
use App\Services\Eta\EtaSubmissionService;
use App\Services\InvoicePdfService;
use App\Services\MonthlyBillingService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['tenant', 'lease.unit']))
            ->columns([
                TextColumn::make('number')
                    ->label(__('admin.tables.invoice.number'))
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->size('xs'),
                TextColumn::make('tenant.name')
                    ->label(__('admin.tables.invoice.tenant'))
                    ->searchable()
                    ->weight('bold'),
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
                    ->sortable()
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
                        'disputed' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('eta_status')
                    ->label(__('admin.tables.invoice.eta'))
                    ->badge()
                    ->placeholder('—')
                    ->formatStateUsing(fn (?string $state) => $state ? __("admin.statuses.eta.{$state}") : null)
                    ->color(fn (?string $state): string => match ($state) {
                        'valid' => 'success',
                        'submitted' => 'info',
                        'invalid', 'rejected' => 'danger',
                        'cancelled' => 'gray',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => collect(__('admin.statuses.invoice'))->only(['draft', 'issued', 'partially_paid', 'paid', 'overdue'])->all()),
                SelectFilter::make('tenant_id')
                    ->label(__('admin.filters.tenant'))
                    ->relationship('tenant', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('unit_id')
                    ->label(__('admin.filters.unit'))
                    ->options(fn () => Unit::orderBy('code')->pluck('code', 'id'))
                    ->searchable()
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
                            $indicators[] = __('admin.filters.period_from') . ': ' . \Carbon\Carbon::parse($data['period_from'])->format('d/m/Y');
                        }
                        if ($data['period_until'] ?? null) {
                            $indicators[] = __('admin.filters.period_until') . ': ' . \Carbon\Carbon::parse($data['period_until'])->format('d/m/Y');
                        }
                        return $indicators;
                    }),
                Filter::make('due_date_range')
                    ->label(__('admin.tables.invoice.due_date'))
                    ->schema([
                        DatePicker::make('due_from')
                            ->label(__('admin.filters.due_from'))
                            ->native(false),
                        DatePicker::make('due_until')
                            ->label(__('admin.filters.due_until'))
                            ->native(false),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['due_from'] ?? null, fn (Builder $q, $date) => $q->whereDate('due_date', '>=', $date))
                        ->when($data['due_until'] ?? null, fn (Builder $q, $date) => $q->whereDate('due_date', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['due_from'] ?? null) {
                            $indicators[] = __('admin.filters.due_from') . ': ' . \Carbon\Carbon::parse($data['due_from'])->format('d/m/Y');
                        }
                        if ($data['due_until'] ?? null) {
                            $indicators[] = __('admin.filters.due_until') . ': ' . \Carbon\Carbon::parse($data['due_until'])->format('d/m/Y');
                        }
                        return $indicators;
                    }),
                Filter::make('overdue_only')
                    ->label(__('admin.filters.overdue_only'))
                    ->query(fn ($query) => $query->where('balance', '>', 0)->where('due_date', '<', now())),
                // ETA compliance filters. The dashboard EtaCompliance widget
                // tiles deep-link into these so each tile lands on a real,
                // filtered list rather than the unfiltered one (audit M08
                // F-33 / F-35 / D-24 / D-26).
                SelectFilter::make('eta_status')
                    ->label(__('admin.filters.eta_status'))
                    ->options(fn () => __('admin.statuses.eta'))
                    ->visible(fn () => \App\Support\Modules::enabled('eta')),
                Filter::make('needs_eta_attention')
                    ->label(__('admin.filters.needs_eta_attention'))
                    ->query(fn ($query) => $query->whereIn('eta_status', ['invalid', 'rejected']))
                    ->visible(fn () => \App\Support\Modules::enabled('eta')),
                Filter::make('eta_pending')
                    ->label(__('admin.filters.eta_pending'))
                    ->query(fn ($query) => $query->where(fn ($q) => $q
                        ->whereNull('eta_status')
                        ->orWhere('eta_status', 'pending')))
                    ->visible(fn () => \App\Support\Modules::enabled('eta')),
                TrashedFilter::make(),
            ])
            ->filtersFormColumns(2)
            ->headerActions([
                ExportAction::make()
                    ->exporter(InvoiceExporter::class)
                    ->label(__('admin.actions.export'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray'),
                Action::make('runMonthlyBilling')
                    ->label(__('admin.actions.run_monthly_billing'))
                    ->icon('heroicon-o-play')
                    ->color('primary')
                    ->visible(fn () => InvoiceResource::canCreate())
                    ->requiresConfirmation()
                    ->modalHeading(__('admin.actions.run_monthly_billing_modal_heading'))
                    ->modalDescription(fn () => __('admin.actions.run_monthly_billing_modal_description', ['period' => now()->locale(app()->getLocale())->isoFormat('MMMM YYYY')]))
                    ->action(function () {
                        $stats = app(MonthlyBillingService::class)->runForPeriod();

                        Notification::make()
                            ->title(__('admin.actions.monthly_billing_complete'))
                            ->body(__('admin.actions.monthly_billing_summary', [
                                'period' => $stats['period'],
                                'created' => $stats['created'],
                                'skipped' => $stats['skipped'],
                                'failed' => $stats['failed'],
                                'considered' => $stats['leases_considered'],
                            ]))
                            ->color($stats['failed'] > 0 ? 'warning' : 'success')
                            ->success()
                            ->send();
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn ($record) => InvoiceResource::canEdit($record)),
                Action::make('downloadPdf')
                    ->label(__('admin.actions.pdf'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->action(function (Invoice $record) {
                        $svc = app(InvoicePdfService::class);
                        $pdf = $svc->build($record);
                        return response()->streamDownload(
                            fn () => print($pdf),
                            $svc->filename($record),
                            ['Content-Type' => 'application/pdf'],
                        );
                    }),
                Action::make('sendWhatsApp')
                    ->label(__('admin.actions.send_whatsapp'))
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->color('success')
                    ->visible(fn ($record) => config('integrations.whatsapp.enabled') && in_array($record->status, ['issued', 'partially_paid', 'overdue']) && InvoiceResource::canEdit($record))
                    ->requiresConfirmation()
                    ->action(fn ($record) => Notification::make()
                        ->title(__('admin.actions.send_whatsapp'))
                        ->body($record->tenant->name)
                        ->success()
                        ->send()),
                Action::make('submitToEta')
                    ->label(__('admin.actions.submit_to_eta'))
                    ->icon('heroicon-o-paper-airplane')
                    ->color('primary')
                    ->visible(fn (Invoice $record) => \App\Support\Modules::enabled('eta') && $record->eta_status !== 'valid' && in_array($record->status, ['issued', 'partially_paid', 'paid', 'overdue']))
                    ->requiresConfirmation()
                    ->modalDescription(fn () => config('eta.mock')
                        ? __('admin.actions.submit_to_eta_modal_mock')
                        : __('admin.actions.submit_to_eta_modal_live'))
                    ->action(function (Invoice $record): void {
                        $updated = app(EtaSubmissionService::class)->submit($record);
                        Notification::make()
                            ->title(__('admin.notifications.eta_submitted'))
                            ->body(__('admin.notifications.eta_submitted_body', [
                                'status' => __("admin.statuses.eta.{$updated->eta_status}"),
                                'id' => $updated->eta_submission_id ?? '—',
                            ]))
                            ->color($updated->eta_status === 'valid' ? 'success' : 'warning')
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    ExportBulkAction::make()
                        ->exporter(InvoiceExporter::class)
                        ->label(__('admin.actions.export')),
                    BulkAction::make('downloadPdfBundle')
                        ->label(__('admin.actions.bulk_download_pdfs'))
                        ->icon('heroicon-o-archive-box-arrow-down')
                        ->color('gray')
                        ->action(function ($records) {
                            $svc = app(InvoicePdfService::class);
                            $tmp = tempnam(sys_get_temp_dir(), 'invoices_').'.zip';
                            $zip = new \ZipArchive;
                            $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
                            foreach ($records as $invoice) {
                                $zip->addFromString($svc->filename($invoice), $svc->build($invoice));
                            }
                            $zip->close();
                            return response()->download($tmp, 'invoices-'.now()->format('Ymd-His').'.zip')->deleteFileAfterSend();
                        }),
                    BulkAction::make('bulkSubmitToEta')
                        ->label(__('admin.actions.bulk_submit_to_eta'))
                        ->icon('heroicon-o-paper-airplane')
                        ->color('primary')
                        ->visible(fn () => \App\Support\Modules::enabled('eta'))
                        ->requiresConfirmation()
                        ->modalDescription(fn () => config('eta.mock')
                            ? __('admin.actions.submit_to_eta_modal_mock')
                            : __('admin.actions.submit_to_eta_modal_live'))
                        ->action(function ($records) {
                            $svc = app(EtaSubmissionService::class);
                            $submitted = 0;
                            $skipped = 0;
                            foreach ($records as $invoice) {
                                if ($invoice->eta_status === 'valid') {
                                    $skipped++;
                                    continue;
                                }
                                $svc->submit($invoice);
                                $submitted++;
                            }
                            Notification::make()
                                ->success()
                                ->title(__('admin.notifications.bulk_eta_complete'))
                                ->body(__('admin.notifications.bulk_eta_complete_body', [
                                    'submitted' => $submitted,
                                    'skipped' => $skipped,
                                ]))
                                ->send();
                        }),
                    DeleteBulkAction::make()
                        ->visible(fn () => InvoiceResource::canDeleteAny()),
                    ForceDeleteBulkAction::make()
                        ->visible(fn () => InvoiceResource::canForceDeleteAny()),
                    RestoreBulkAction::make()
                        ->visible(fn () => InvoiceResource::canRestoreAny()),
                ]),
            ])
            ->defaultSort('issue_date', 'desc')
            ->emptyStateIcon('heroicon-o-banknotes')
            ->emptyStateHeading(__('admin.empty.invoices.heading'))
            ->emptyStateDescription(__('admin.empty.invoices.description'))
            ->emptyStateActions([
                \Filament\Actions\CreateAction::make()
                    ->label(__('admin.empty.invoices.cta'))
                    ->icon('heroicon-o-plus'),
            ]);
    }
}
