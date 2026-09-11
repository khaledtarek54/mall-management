<?php

namespace App\Filament\Portal\Resources\Invoices\Tables;

use App\Filament\Portal\Actions\InvoiceActions;
use App\Models\Invoice;
use App\Models\Unit;
use App\Services\InvoicePdfService;
use App\Support\BadgeColors;
use App\Support\Filament\DateRangeFilter;
use App\Support\Filament\EntitySelectFilter;
use App\Support\Filament\PdfDownloadAction;
use App\Support\Portal;
use App\Support\StatusOptions;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                    // `isOverdue()` — the ONE definition (past due AND still owed). This restated
                    // it as "past due unless paid/cancelled", which coloured a written-off or fully
                    // credited invoice's due date red.
                    ->color(fn (Invoice $record): ?string => $record->isOverdue() ? 'danger' : null),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.invoice.{$state}"))
                    ->color(BadgeColors::of('invoices.status')),
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
                DateRangeFilter::make('period_start', __('admin.filters.period'), name: 'period'),
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
                // `status = 'overdue'` stamp. THERE IS NO NIGHTLY SWEEP (this comment said there
                // was until 2026-09-10): the stamp is written only as a side effect of touching one
                // invoice — a settlement, or a late fee — so on a freshly-lapsed invoice it may
                // never be written at all, and it can never be written on a `partially_paid` one.
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
                // *Pay now* / *Pay (demo)* — ONE definition with the invoice page's header, so
                // the list can never offer a payment the page refuses (it did: see the registry).
                ...InvoiceActions::all(),
            ])
            ->defaultSort('issue_date', 'desc')
            ->emptyStateIcon('heroicon-o-document-text')
            ->emptyStateHeading(__('admin.empty.portal_invoices.heading'))
            ->emptyStateDescription(__('admin.empty.portal_invoices.description'));
    }
}
