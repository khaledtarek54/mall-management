<?php

namespace App\Filament\Admin\Resources\Tenants\Tables;

use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\Exports\TenantExporter;
use App\Models\Tenant;
use App\Services\TenantStatementPdfService;
use App\Support\Exports;
use App\Support\Filament\CustomFieldsTable;
use App\Support\Filament\PdfDownloadAction;
use App\Support\TenantBalances;
use App\Support\TenantScope;
use Carbon\Carbon;
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
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;

class TenantsTable
{
    /**
     * The three money figures for one row, computed ONCE for the whole page.
     *
     * Reads the ids Filament has already loaded and asks `TenantBalances` for the SET, so the
     * first row computes for all of them and every later row hits the per-request memo. Asking
     * per row instead would memoise 25 sets of one and batch nothing.
     *
     * Measured 2026-09-01: this list was issuing about five aggregate queries PER ROW — the four
     * settlement channels plus an invoice `exists()` — and was the second-slowest screen in the
     * panel. `TenantBalances` does not restate the rule; it runs the model's own filters and SQL
     * over a set, pinned by `TenantBalancesMatchThePerRowMethodsTest`.
     *
     * @return array{outstanding: float, credit: float, delinquent: bool}
     */
    private static function balances(Tenant $record, mixed $livewire): array
    {
        $ids = [$record->getKey()];

        if ($livewire instanceof HasTable) {
            $loaded = $livewire->getTableRecords();

            if ($loaded !== null) {
                // getTableRecords() may hand back a PAGINATOR, and collect() on one yields its
                // metadata (current_page, total, …) alongside the rows — ints, which is a fatal on
                // ->getKey(). Filter to actual records rather than trusting the shape.
                $keys = collect($loaded instanceof Paginator ? $loaded->items() : $loaded)
                    ->filter(fn ($r): bool => $r instanceof Tenant)
                    ->map(fn (Tenant $r) => $r->getKey())
                    ->all();

                if ($keys !== []) {
                    $ids = $keys;
                }
            }
        }

        return app(TenantBalances::class)->for($ids, TenantScope::visibleAssetIds())[$record->getKey()]
            ?? ['outstanding' => 0.0, 'credit' => 0.0, 'delinquent' => false];
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label(__('admin.fields.tenant_code'))
                    ->searchable()
                    ->sortable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->color('gray')
                    ->placeholder('—'),
                TextColumn::make('name')
                    ->label(__('admin.tables.tenant.name'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('active_leases_count')
                    ->label(__('admin.widgets.account_balance.active_leases'))
                    ->counts('activeLeases')
                    ->badge()
                    ->color('success'),
                TextColumn::make('phone')
                    ->label(__('admin.tables.tenant.phone'))
                    ->searchable()
                    ->icon('heroicon-m-phone'),
                TextColumn::make('whatsapp')
                    ->label(__('admin.fields.whatsapp'))
                    ->icon('heroicon-m-chat-bubble-left-right')
                    ->color('success')
                    ->toggleable(),
                TextColumn::make('email')
                    ->label(__('admin.tables.tenant.email'))
                    ->searchable()
                    ->icon('heroicon-m-envelope')
                    ->copyable()
                    ->toggleable(),
                TextColumn::make('contact_person')
                    ->label(__('admin.fields.contact_person'))
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.tenant.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'inactive' => 'gray',
                        'blacklisted' => 'danger',
                        default => 'gray',
                    }),
                // Delinquency = at least one invoice with balance > 0 past
                // its due_date (Tenant::isDelinquent). Surfaces the tested
                // model method in the table so operators can spot defaulters
                // at a glance (audit M02 F-11 / D-9).
                TextColumn::make('is_delinquent')
                    ->label(__('admin.tables.tenant.delinquent'))
                    ->badge()
                    // Scope to visible properties — a shared tenant's mall-B overdue must not colour
                    // the badge for a mall-A-only operator (cross-property AR leak).
                    ->state(fn (Tenant $record, $livewire): string => self::balances($record, $livewire)['delinquent'] ? 'delinquent' : 'current')
                    ->color(fn (string $state): string => $state === 'delinquent' ? 'danger' : 'success')
                    ->icon(fn (string $state): string => $state === 'delinquent' ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                    ->formatStateUsing(fn (string $state) => __("admin.tables.tenant.delinquency_state.{$state}"))
                    // HOW MUCH, not just whether. A red "Delinquent" badge with no figure makes
                    // EGP 500 and EGP 500,000 look identical, which is no use to whoever is deciding
                    // who to call first — and it is the dead-end number the UX rules warn about.
                    // The tenant could already see this on the portal and through the API; the
                    // operator's own list was the one place it was missing.
                    //
                    // Scoped with `visibleAssetIds()` exactly like the badge above: a shared
                    // tenant's mall-B arrears must not surface to a mall-A-only operator.
                    ->description(function (Tenant $record, $livewire): ?string {
                        $outstanding = self::balances($record, $livewire)['outstanding'];

                        return $outstanding > 0
                            ? 'EGP '.number_format($outstanding, 2)
                            : null;
                    })
                    ->toggleable(),
                // Credit on account = money paid but not yet applied to an invoice (an overpayment /
                // on-account remainder booked to Unearned Revenue). Property-scoped like delinquency.
                TextColumn::make('credit_on_account')
                    ->label(__('admin.tables.tenant.credit_on_account'))
                    ->badge()
                    ->state(fn (Tenant $record, $livewire): float => self::balances($record, $livewire)['credit'])
                    ->formatStateUsing(fn ($state): string => (float) $state > 0 ? 'EGP '.number_format((float) $state, 2) : '—')
                    ->color(fn ($state): string => (float) $state > 0 ? 'success' : 'gray')
                    ->icon(fn ($state): ?string => (float) $state > 0 ? 'heroicon-m-gift' : null)
                    ->toggleable(),

                // The operator's own fields (D-7). Hidden until asked for, so a list
                // nobody customised is unchanged.
                ...CustomFieldsTable::columns('tenant'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.tenant')),
                SelectFilter::make('type')
                    ->label(__('admin.fields.type'))
                    ->options(fn () => [
                        'individual' => __('admin.fields.individual'),
                        'company' => __('admin.fields.company'),
                    ]),
                TernaryFilter::make('has_active_lease')
                    ->label(__('admin.widgets.account_balance.active_leases'))
                    ->queries(
                        true: fn (Builder $query) => $query->whereHas('activeLeases'),
                        false: fn (Builder $query) => $query->whereDoesntHave('activeLeases'),
                        blank: fn (Builder $query) => $query,
                    ),
                TernaryFilter::make('is_delinquent')
                    ->label(__('admin.tables.tenant.delinquent'))
                    ->queries(
                        // Scoped to visible properties — same reason as the badge above.
                        //
                        // Off `invoices.asset_id`, NOT through `lease.unit`: an owner assessment has
                        // no lease (module 37 bills the ownership), so the old inference dropped it
                        // and a unit owner in arrears was invisible to the one filter built to find
                        // arrears. The column has been authoritative since the 2026-08-15
                        // denormalisation that moved Invoice off the `lease.unit` chain for exactly
                        // this reason. Only a RESTRICTED user was affected, which is why it survived:
                        // `visibleAssetIds()` is null for super_admin, so the person who could have
                        // noticed never saw it.
                        // `whereCollectable()`, matching the badge beside it, which reads
                        // `isDelinquent()`. Filtering to *Delinquent* used to return tenants whose
                        // own badge said *Current*, because only one of the two nets write-offs.
                        // `overdue()` — the ONE definition SW-016 named, replacing the 7th and 8th
                        // hand-written copies of its pair. The comment above already demands this
                        // filter match `isDelinquent()`, which reads the scope; two spellings of
                        // one demand is how they drift.
                        true: fn (Builder $query) => $query->whereHas('invoices', fn (Builder $q) => $q
                            ->overdue()
                            ->when(TenantScope::visibleAssetIds(), fn (Builder $i, $ids) => $i->whereIn('asset_id', $ids))),
                        false: fn (Builder $query) => $query->whereDoesntHave('invoices', fn (Builder $q) => $q
                            ->overdue()
                            ->when(TenantScope::visibleAssetIds(), fn (Builder $i, $ids) => $i->whereIn('asset_id', $ids))),
                        blank: fn (Builder $query) => $query,
                    ),
                Filter::make('created_range')
                    ->label(__('admin.users.created'))
                    ->schema([
                        DatePicker::make('created_from')
                            ->label(__('admin.filters.created_from'))
                            ->native(false),
                        DatePicker::make('created_until')
                            ->label(__('admin.filters.created_until'))
                            ->native(false),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['created_from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['created_until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['created_from'] ?? null) {
                            $indicators[] = __('admin.filters.created_from').': '.Carbon::parse($data['created_from'])->format('d/m/Y');
                        }
                        if ($data['created_until'] ?? null) {
                            $indicators[] = __('admin.filters.created_until').': '.Carbon::parse($data['created_until'])->format('d/m/Y');
                        }

                        return $indicators;
                    }),
                TrashedFilter::make(),

                ...CustomFieldsTable::filters('tenant'),
            ])
            ->filtersFormColumns(2)
            ->headerActions([
                ExportAction::make()
                    ->exporter(TenantExporter::class)
                    ->label(__('admin.actions.export'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->visible(fn (): bool => Exports::allowed(TenantResource::class))
                    ->authorize(fn (): bool => Exports::allowed(TenantResource::class)),
            ])
            ->recordActions([
                // Read the record without opening its edit form — less
                // friction, and no write surface for view-only roles. The
                // schema is the resource's own form rendered disabled, so it
                // cannot drift from the fields that actually exist.
                ViewAction::make()
                    ->visible(fn ($record) => TenantResource::canView($record))
                    ->authorize(fn ($record) => TenantResource::canView($record)),
                EditAction::make()
                    ->visible(fn ($record) => TenantResource::canEdit($record)),
                PdfDownloadAction::make('statement')
                    ->label(__('admin.statement.action_label'))
                    ->icon(Heroicon::OutlinedDocumentArrowDown)
                    // The statement is ABOUT this tenant and goes TO them, so their own language is
                    // the default.
                    ->recipient(fn (Tenant $record) => $record)
                    // Admin surface: scope to visible properties so a restricted operator's
                    // statement of a shared tenant excludes malls they can't see.
                    ->document(fn (Tenant $record, string $locale): string => app(TenantStatementPdfService::class)
                        ->build($record, TenantScope::visibleAssetIds(), null, null, $locale))
                    ->filename(fn (Tenant $record): string => app(TenantStatementPdfService::class)->filename($record))
                    // Statement is tenant financial data — gate server-side (was ungated).
                    ->authorize(fn () => auth()->user()?->can('tenants.view') ?? false),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    ExportBulkAction::make()
                        ->exporter(TenantExporter::class)
                        ->label(__('admin.actions.export'))
                        ->visible(fn (): bool => Exports::allowed(TenantResource::class))
                        ->authorize(fn (): bool => Exports::allowed(TenantResource::class)),
                    DeleteBulkAction::make()
                        ->visible(fn () => TenantResource::canDeleteAny()),
                    ForceDeleteBulkAction::make()
                        ->visible(fn () => TenantResource::canForceDeleteAny()),
                    RestoreBulkAction::make()
                        ->visible(fn () => TenantResource::canRestoreAny()),
                ]),
            ])
            ->defaultSort('name')
            ->emptyStateIcon('heroicon-o-users')
            ->emptyStateHeading(__('admin.empty.tenants.heading'))
            ->emptyStateDescription(__('admin.empty.tenants.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('admin.empty.tenants.cta'))
                    ->icon('heroicon-o-plus'),
            ]);
    }
}
