<?php

namespace App\Filament\Portal\Resources\Invoices\Tables;

use App\Actions\Api\V1\Payments\RecordDemoPaymentAction;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Unit;
use App\Services\InvoicePdfService;
use App\Services\Paymob\PaymobPaymentInitiator;
use App\Support\DemoPayments;
use App\Support\Filament\EntitySelectFilter;
use App\Support\Filament\PdfDownloadAction;
use App\Support\Portal;
use App\Support\StatusOptions;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // `writeOffs` is eager-loaded for the Pay button: `isPayable()` nets prior write-offs
            // out of what a tenant may be charged, and without this that is one aggregate per row
            // on the first page a tenant opens.
            ->modifyQueryUsing(fn ($query) => $query->with(['lease.unit', 'unitOwnership.unit', 'writeOffs']))
            ->columns([
                TextColumn::make('number')
                    ->label(__('admin.tables.invoice.number'))
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->size('xs'),
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
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    // Every status a tenant may be SHOWN — the value set minus TenantVisibility's
                    // hidden ones — never a hand-written list. The `->only()` this replaces offered
                    // 4 of the 8 (measured 2026-09-04): `disputed`, `cancelled`, `credited` and
                    // `written_off` each have an arm in the `status` column a few lines above, so
                    // the tenant could read the word and had no way to filter by it.
                    ->options(fn () => StatusOptions::forTenant('invoices')),
                EntitySelectFilter::make('unit_id')
                    ->label(__('admin.filters.unit'))
                    ->entity(Unit::class)
                    // The retailer's OWN space only. `visibleAssetIds()` is null in the portal (the
                    // authenticated party is a TenantUser, not a User), so this narrowing is the
                    // whole scope here rather than an addition to it.
                    ->modifyOptionsQuery(fn ($query) => $query->whereIn(
                        'id',
                        Portal::tenant()?->leases()->with('unit')->get()->pluck('unit.id')->filter() ?? [],
                    ))
                    ->query(fn (Builder $query, array $data): Builder => $query
                        // Either agreement — a unit owner reads his own assessments here too.
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
                // Two filters, because the tenant's dashboard shows two figures and they are not
                // the same set. This one is EVERYTHING STILL OWED — the set behind "Outstanding
                // balance", which is the stat that links here.
                //
                // It was labelled "Overdue Only" while running `whereCollectable()`, so the tenant
                // clicked an outstanding figure and landed on a list captioned Overdue that showed
                // every unpaid invoice: on the QA baseline, 108 rows under a word that describes 11
                // of them (SW-016). The key already said `unpaid_only`; only the label lied, and it
                // is the label the reader sees.
                //
                // `stillOwed()` rather than the bare `whereCollectable()` it ran before, so the
                // filter and `Tenant::outstandingBalance()` — which sums exactly this scope — cannot
                // describe different sets. On today's data the two agree (a cancelled or fully
                // credited invoice already carries a zero balance, and a draft is hidden by
                // `visibleToTenant()`), which is why nobody noticed; agreeing by accident is not
                // agreeing.
                Filter::make('unpaid_only')
                    ->label(__('admin.filters.unpaid_only'))
                    ->query(fn (Builder $query) => $query->stillOwed()),
                // …and this one is the OVERDUE subset, the set behind the "Overdue invoices" count.
                // `Invoice::scopeOverdue()` is the single definition the admin filter, the sidebar
                // badge, the dashboard card, the delinquency test and this share — never the raw
                // `status = 'overdue'` stamp, which the nightly sweep has not written yet on a
                // freshly-lapsed invoice and can never write on a `partially_paid` one.
                Filter::make('overdue_only')
                    ->label(__('admin.filters.overdue_only'))
                    ->query(fn (Builder $query) => $query->overdue()),
            ])
            ->filtersFormColumns(2)
            ->recordActions([
                ViewAction::make(),
                PdfDownloadAction::make('downloadPdf')
                    ->service(InvoicePdfService::class)
                    ->recipient(fn (Invoice $record) => $record->tenant),
                Action::make('paymentLink')
                    ->label(__('admin.actions.payment_link'))
                    ->icon('heroicon-o-link')
                    ->color('gray')
                    ->visible(fn ($record) => config('integrations.paymob.enabled') && $record->isPayable())
                    ->modalHeading(fn ($record) => __('admin.actions.payment_link').' · '.$record->number)
                    ->modalSubmitAction(false)
                    ->modalContent(fn (Invoice $record) => view('filament.payment-link-modal', ['invoice' => $record])),
                Action::make('payNow')
                    ->label(__('admin.actions.pay_now'))
                    ->icon('heroicon-o-credit-card')
                    ->color('primary')
                    // isPayable() (not just balance>0) — never open a live checkout for a
                    // cancelled/fully-credited invoice (matches the paymentLink action + every
                    // other capture entry point).
                    ->visible(fn ($record) => Portal::isAdmin() && config('integrations.paymob.enabled') && $record->isPayable())
                    ->requiresConfirmation()
                    ->modalHeading(fn ($record) => __('admin.actions.pay_now').' · '.$record->number)
                    ->action(function (Invoice $record) {
                        abort_unless(Portal::isAdmin() && $record->isPayable(), 403);
                        try {
                            $session = app(PaymobPaymentInitiator::class)->start($record, Payment::CHANNEL_PORTAL);

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
                    ->visible(fn (Invoice $record) => self::canPayDemo($record))
                    ->requiresConfirmation()
                    ->modalHeading(fn (Invoice $record) => __('admin.actions.pay_now').' · '.$record->number)
                    ->modalDescription(fn (Invoice $record) => __('admin.actions.pay_demo_modal_body', [
                        'amount' => number_format((float) $record->balance, 2),
                    ]))
                    ->modalSubmitActionLabel(__('admin.actions.pay_now'))
                    ->action(function (Invoice $record) {
                        // The FULL predicate, not just the read-only check: `visible()` is the UI,
                        // this is the gate. Before this it re-checked only Portal::isAdmin(), so a
                        // crafted dispatch reached the capture path whatever the environment said.
                        abort_unless(self::canPayDemo($record), 403);
                        app(RecordDemoPaymentAction::class)->handle($record);

                        Notification::make()
                            ->success()
                            ->title(__('admin.notifications.payment_received_title'))
                            ->body(__('admin.actions.pay_demo_success', ['number' => $record->number]))
                            ->send();
                    }),
            ])
            ->defaultSort('issue_date', 'desc')
            ->emptyStateIcon('heroicon-o-document-text')
            ->emptyStateHeading(__('admin.empty.portal_invoices.heading'))
            ->emptyStateDescription(__('admin.empty.portal_invoices.description'));
    }

    /**
     * May this invoice be settled by the demo shortcut, here, by this user?
     *
     * Named once so `visible()` and `action()` cannot drift — the pattern CLAUDE.md requires of
     * every write action. Whether the ENVIRONMENT permits the shortcut at all is not this class's
     * question: that belongs to App\Support\DemoPayments, which the API controller and the portal
     * View page ask too.
     */
    private static function canPayDemo(Invoice $record): bool
    {
        return Portal::isAdmin()
            && DemoPayments::enabled()
            && (float) $record->balance > 0
            && ! in_array($record->status, ['cancelled', 'credited', 'written_off'], true);
    }
}
