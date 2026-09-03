<?php

namespace App\Filament\Admin\Resources\Invoices\Tables;

use App\Filament\Actions\LedgerEntryAction;
use App\Filament\Actions\PostMonthAction;
use App\Filament\Admin\Pages\BillingRunPreview;
use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Exports\InvoiceExporter;
use App\Jobs\SubmitInvoiceToEta;
use App\Models\Invoice;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\BillUnitOwnershipsService;
use App\Services\InvoicePdfService;
use App\Services\MonthlyBillingService;
use App\Support\Exports;
use App\Support\Filament\EntitySelectFilter;
use App\Support\Filament\PdfDownloadAction;
use App\Support\Filament\TableGroup;
use App\Support\Modules;
use App\Support\Pdf\DocumentLocale;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ExportAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['tenant', 'lease.unit', 'unitOwnership.unit']))
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
                // Through whichever agreement raised it — a lease invoice holds the unit on the
                // lease, an owner assessment on the ownership. Reading `lease.unit.code` directly
                // rendered every owner assessment with a blank unit.
                TextColumn::make('unit_code')
                    ->label(__('admin.tables.invoice.unit'))
                    ->state(fn (Invoice $record): ?string => $record->unitCode())
                    ->placeholder('—')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('period_start')
                    ->label(__('admin.tables.invoice.period'))
                    ->formatStateUsing(fn ($record) => $record->period_start?->locale(app()->getLocale())->isoFormat('MMM YYYY') ?? '—'),
                TextColumn::make('total')
                    ->label(__('admin.tables.invoice.total'))
                    ->money('EGP')
                    ->sortable()
                    ->alignRight()
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
                TextColumn::make('paid_amount')
                    ->label(__('admin.tables.invoice.paid'))
                    ->money('EGP')
                    ->sortable()
                    ->color('success')
                    ->alignRight()
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
                TextColumn::make('balance')
                    ->label(__('admin.tables.invoice.balance'))
                    ->money('EGP')
                    ->sortable()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success')
                    ->weight('bold')
                    ->alignRight()
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
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
                // Module-gated like the ETA filters and actions below. It was the one ETA surface
                // that was NOT, so with the module off every invoice list still carried an "ETA
                // Status" column reading "—" on every row — a compliance posture the operator has
                // no way to act on, for a module (Modules::FROZEN) that has never been certified.
                TextColumn::make('eta_status')
                    ->label(__('admin.tables.invoice.eta'))
                    ->badge()
                    ->placeholder('—')
                    ->visible(fn () => Modules::enabled('eta'))
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
                EntitySelectFilter::make('tenant_id')
                    ->label(__('admin.filters.tenant'))
                    ->relationship('tenant')
                    ->entity(Tenant::class),
                EntitySelectFilter::make('unit_id')
                    ->label(__('admin.filters.unit'))
                    ->entity(Unit::class)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        // Through EITHER agreement — an owner assessment holds its unit on the
                        // ownership, so a lease-only clause returned nothing for an owner-occupied
                        // unit and read as "no invoices" rather than "this filter cannot see him".
                        ->when($data['value'] ?? null, fn (Builder $q, $unitId) => $q->forUnit((int) $unitId))),
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
                            $indicators[] = __('admin.filters.due_from').': '.Carbon::parse($data['due_from'])->format('d/m/Y');
                        }
                        if ($data['due_until'] ?? null) {
                            $indicators[] = __('admin.filters.due_until').': '.Carbon::parse($data['due_until'])->format('d/m/Y');
                        }

                        return $indicators;
                    }),
                Filter::make('overdue_only')
                    ->label(__('admin.filters.overdue_only'))
                    // The same definition as the sidebar badge and the dashboard card — a filter
                    // that returns more rows than the badge counts reads as a broken badge.
                    ->query(fn ($query) => $query->stillOwed()->where('due_date', '<', now())),
                // ETA compliance filters. The dashboard EtaCompliance widget
                // tiles deep-link into these so each tile lands on a real,
                // filtered list rather than the unfiltered one (audit M08
                // F-33 / F-35 / D-24 / D-26).
                SelectFilter::make('eta_status')
                    ->label(__('admin.filters.eta_status'))
                    ->options(fn () => __('admin.statuses.eta'))
                    ->visible(fn () => Modules::enabled('eta')),
                Filter::make('needs_eta_attention')
                    ->label(__('admin.filters.needs_eta_attention'))
                    ->query(fn ($query) => $query->whereIn('eta_status', ['invalid', 'rejected']))
                    ->visible(fn () => Modules::enabled('eta')),
                Filter::make('eta_pending')
                    ->label(__('admin.filters.eta_pending'))
                    ->query(fn ($query) => $query->where(fn ($q) => $q
                        ->whereNull('eta_status')
                        ->orWhere('eta_status', 'pending')))
                    ->visible(fn () => Modules::enabled('eta')),
                TrashedFilter::make(),
            ])
            ->filtersFormColumns(2)
            ->headerActions([
                ExportAction::make()
                    ->exporter(InvoiceExporter::class)
                    ->label(__('admin.actions.export'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->visible(fn (): bool => Exports::allowed(InvoiceResource::class))
                    ->authorize(fn (): bool => Exports::allowed(InvoiceResource::class)),
                // Preview first. Posting a month bills every active lease in the mall, and a
                // confirmation modal asks "are you sure" without showing what you are being sure
                // about — so this is the route an operator should take, and the blind run below
                // stays as the express path for someone who already knows.
                Action::make('previewMonthlyBilling')
                    ->label(__('admin.billing_preview.nav_label'))
                    ->icon('heroicon-o-clipboard-document-check')
                    ->color('gray')
                    ->url(fn (): string => BillingRunPreview::getUrl())
                    ->visible(fn () => BillingRunPreview::canAccess()),
                Action::make('runMonthlyBilling')
                    ->label(__('admin.actions.run_monthly_billing'))
                    ->icon('heroicon-o-play')
                    ->color('primary')
                    ->visible(fn () => InvoiceResource::canRunBilling())
                    ->authorize(fn () => InvoiceResource::canRunBilling())
                    ->requiresConfirmation()
                    ->modalHeading(__('admin.actions.run_monthly_billing_modal_heading'))
                    ->modalDescription(fn () => __('admin.actions.run_monthly_billing_modal_description', ['period' => now()->locale(app()->getLocale())->isoFormat('MMMM YYYY')]))
                    ->action(function () {
                        // action() is the real gate — mountAction() never checks visible(); viewer/owner
                        // hold invoices.view but not the billing right and must not trigger a run.
                        abort_unless(InvoiceResource::canRunBilling(), 403);
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
                // The OWNER side of the billing night (module 37), deliberately beside the lease run
                // rather than on the ownership register: it is the same act for the same role, and
                // `accounting` lives here while the register sits in Leasing. Both runs are also
                // scheduled (routes/console.php) — this is the manual re-run, same as its neighbour.
                Action::make('runOwnerAssessments')
                    ->label(__('admin.actions.run_owner_assessments'))
                    ->icon('heroicon-o-key')
                    ->color('primary')
                    ->visible(fn () => InvoiceResource::canRunBilling())
                    ->authorize(fn () => InvoiceResource::canRunBilling())
                    ->requiresConfirmation()
                    ->modalHeading(__('admin.actions.run_owner_assessments_modal_heading'))
                    ->modalDescription(fn () => __('admin.actions.run_owner_assessments_modal_description', ['period' => now()->locale(app()->getLocale())->isoFormat('MMMM YYYY')]))
                    ->action(function () {
                        abort_unless(InvoiceResource::canRunBilling(), 403);
                        $stats = app(BillUnitOwnershipsService::class)->runForPeriod();

                        Notification::make()
                            ->title(__('admin.actions.owner_assessments_complete'))
                            ->body(__('admin.actions.owner_assessments_summary', [
                                'period' => $stats['period'],
                                'created' => $stats['created'],
                                'skipped' => $stats['skipped'],
                                'failed' => $stats['failed'],
                                'considered' => $stats['considered'],
                            ]))
                            ->color($stats['failed'] > 0 ? 'warning' : 'success')
                            ->success()
                            ->send();
                    }),
            ])
            // Grouping is OFFERED, never applied by default — the operator picks it from the
            // toolbar. Tenant is the collections axis ("what does Cafe Crema owe in total"),
            // status the ageing one. No ->defaultGroup(): a list that silently arrives grouped
            // reads as broken to anyone who did not choose it.
            ->groups([
                Group::make('tenant.name')->label(__('admin.filters.tenant'))->collapsible(),
                TableGroup::byColumn($table, 'status'),
            ])
            ->recordActions([
                // Read the record without opening its edit form — less
                // friction, and no write surface for view-only roles. The
                // schema is the resource's own form rendered disabled, so it
                // cannot drift from the fields that actually exist.
                ViewAction::make()
                    ->visible(fn ($record) => InvoiceResource::canView($record))
                    ->authorize(fn ($record) => InvoiceResource::canView($record)),
                // ── The list FINDS; the record ACTS ─────────────────────────────────────
                // Defined once in App\Filament\Admin\Actions\InvoiceActions and composed onto this
                // record's own page, so opening the record is enough to act on it.
                EditAction::make()
                    ->visible(fn ($record) => InvoiceResource::canEdit($record)),
                PdfDownloadAction::make('downloadPdf')
                    ->service(InvoicePdfService::class)
                    ->recipient(fn (Invoice $record) => $record->tenant),
                PostMonthAction::make('invoices.edit'),
                LedgerEntryAction::make(),

                Action::make('paymentLink')
                    ->label(__('admin.actions.payment_link'))
                    ->icon('heroicon-o-link')
                    ->color('gray')
                    ->authorize(fn () => auth()->user()?->can('invoices.view') ?? false)
                    // Mirrors the revoke action below: available whenever a token exists, not only
                    // while payable with the gateway on. The URL is live regardless (minted on
                    // create, published by the mobile API), so the operator must be able to read
                    // what a client holds — the modal states when it can no longer collect.
                    ->visible(fn (Invoice $record) => filled($record->payment_link_token)
                        && (auth()->user()?->can('invoices.view') ?? false))
                    ->modalHeading(fn (Invoice $record) => __('admin.actions.payment_link').' · '.$record->number)
                    ->modalSubmitAction(false)
                    ->modalContent(fn (Invoice $record) => view('filament.payment-link-modal', ['invoice' => $record])),

            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    ExportBulkAction::make()
                        ->exporter(InvoiceExporter::class)
                        ->label(__('admin.actions.export'))
                        ->visible(fn (): bool => Exports::allowed(InvoiceResource::class))
                        ->authorize(fn (): bool => Exports::allowed(InvoiceResource::class)),
                    BulkAction::make('downloadPdfBundle')
                        ->label(__('admin.actions.bulk_download_pdfs'))
                        ->icon('heroicon-o-archive-box-arrow-down')
                        ->color('gray')
                        ->modalWidth('sm')
                        ->modalSubmitActionLabel(__('admin.actions.download'))
                        // ONE language for the whole bundle, and no per-recipient default: a
                        // selection spans tenants who do not read the same language, so there is no
                        // "the recipient's" to fall back to. The operator's own is the honest
                        // default and the picker is the answer.
                        ->schema([
                            Radio::make(PdfDownloadAction::LANGUAGE_FIELD)
                                ->label(__('admin.pdf.language'))
                                ->options(DocumentLocale::options())
                                ->default(fn (): string => DocumentLocale::resolve())
                                ->required()
                                ->in(array_keys(DocumentLocale::options())),
                        ])
                        ->action(function ($records, array $data) {
                            $svc = app(InvoicePdfService::class);
                            $locale = DocumentLocale::resolve($data[PdfDownloadAction::LANGUAGE_FIELD] ?? null);
                            $tmp = tempnam(sys_get_temp_dir(), 'invoices_').'.zip';
                            $zip = new \ZipArchive;
                            $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
                            foreach ($records as $invoice) {
                                $zip->addFromString($svc->filename($invoice), $svc->build($invoice, $locale));
                            }
                            $zip->close();

                            return response()->download($tmp, 'invoices-'.now()->format('Ymd-His').'.zip')->deleteFileAfterSend();
                        }),
                    BulkAction::make('bulkSubmitToEta')
                        ->label(__('admin.actions.bulk_submit_to_eta'))
                        ->icon('heroicon-o-paper-airplane')
                        ->color('primary')
                        ->visible(fn () => Modules::enabled('eta') && auth()->user()?->can('invoices.submit_to_eta'))
                        ->authorize(fn () => (auth()->user()?->can('invoices.submit_to_eta') ?? false) && Modules::enabled('eta'))
                        ->requiresConfirmation()
                        ->modalDescription(fn () => config('eta.mock')
                            ? __('admin.actions.submit_to_eta_modal_mock')
                            : __('admin.actions.submit_to_eta_modal_live'))
                        ->action(function ($records) {
                            abort_unless((auth()->user()?->can('invoices.submit_to_eta') ?? false) && Modules::enabled('eta'), 403);
                            // Queue each submission — a bulk of 20+ must never block the
                            // request (or time out) on a slow ETA gateway.
                            $queued = 0;
                            $skipped = 0;
                            foreach ($records as $invoice) {
                                if ($invoice->eta_status === 'valid') {
                                    $skipped++;

                                    continue;
                                }
                                SubmitInvoiceToEta::dispatch($invoice);
                                $queued++;
                            }
                            Notification::make()
                                ->success()
                                ->title(__('admin.notifications.bulk_eta_queued'))
                                ->body(__('admin.notifications.bulk_eta_queued_body', [
                                    'queued' => $queued,
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
                CreateAction::make()
                    ->label(__('admin.empty.invoices.cta'))
                    ->icon('heroicon-o-plus'),
            ]);
    }
}
