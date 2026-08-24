<?php

namespace App\Filament\Admin\Resources\Leases\Tables;

use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Filament\Exports\LeaseExporter;
use App\Models\Lease;
use App\Models\RentableItem;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\LeaseCreationService;
use App\Support\Exports;
use App\Support\Filament\CustomFieldsTable;
use App\Support\Filament\EntitySelect;
use App\Support\Filament\EntitySelectFilter;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
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
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class LeasesTable
{
    /**
     * Items this lease could take: same property, free on the day, not withdrawn.
     *
     * @return array<int, string>
     */
    private static function lettableItemOptions(Lease $record): array
    {
        $assetId = $record->unit?->asset_id;

        if (! $assetId) {
            return [];
        }

        return RentableItem::query()
            ->where('asset_id', $assetId)
            ->where('status', '!=', RentableItem::STATUS_OUT_OF_SERVICE)
            ->orderBy('code')
            ->get()
            // Filtered in PHP rather than SQL: "held on a date" is a date-ranged predicate over the
            // pivot that the model already owns, and duplicating it as a subquery is how the two
            // drift apart.
            ->reject(fn (RentableItem $i) => $i->isHeldOn(null, ignore: ['type' => 'lease', 'id' => (int) $record->id]))
            ->mapWithKeys(fn (RentableItem $i) => [
                $i->id => $i->label().' · EGP '.number_format((float) $i->monthly_rate, 2),
            ])
            ->all();
    }

    /** @return array<int, string> */
    private static function heldItemOptions(Lease $record): array
    {
        // The negotiated rate comes from the pivot table directly: the relation carries no declared
        // pivot type to read through, and this is one query either way.
        $rates = DB::table('rentable_item_holdings')
            ->where('holder_type', 'lease')
            ->where('holder_id', $record->id)
            ->whereNull('effective_to')
            ->pluck('monthly_rate', 'rentable_item_id');

        return $record->rentableItems()
            ->wherePivotNull('effective_to')
            ->get()
            ->mapWithKeys(fn (RentableItem $i) => [
                $i->id => $i->label().' · EGP '.number_format((float) ($rates[$i->id] ?? 0), 2),
            ])
            ->all();
    }

    public static function configure(Table $table): Table
    {
        return $table
            // `units` is eager-loaded for the multi-unit description below, which walked the pivot
            // per row: measured at 25 extra queries on a 25-row page, one per lease.
            //
            // `depositApplications` + `depositBillings` are what the deposit-shortfall column needs.
            // `Lease::depositHeld()` sums both, and both used to be queries it built ITSELF — a
            // `DepositApplication::where('lease_id', …)` and an `Invoice::query()` — which no
            // `with()` could reach, so the column cost two more queries per row. The method now
            // prefers the loaded relation exactly as it already did for `deposits`, and
            // `DepositHeldIsTheSameFigureLoadedOrNotTest` proves the two paths cannot disagree —
            // which matters because that figure also backs the refund guard.
            ->modifyQueryUsing(fn ($query) => $query->with([
                'unit', 'tenant', 'units',
                'deposits', 'depositApplications', 'depositBillings',
            ]))
            ->columns([
                TextColumn::make('reference')
                    ->label(__('admin.tables.lease.reference'))
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->size('xs')
                    // The lease TYPE axis, which Yardi keeps separate from status. Derived from
                    // `previous_lease_id`, never stored — see Lease::leaseType(). Shown as a
                    // description on the reference because it qualifies the identity of the lease
                    // ("AW-0042, a renewal"), not its state.
                    ->description(fn (Lease $record) => $record->isRenewal()
                        ? __('admin.lease_types.renewal')
                        : null),
                TextColumn::make('unit.code')
                    ->label(__('admin.tables.lease.unit'))
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    // Surface multi-unit leases: list the additional (non-master) units.
                    ->description(function (Lease $record): ?string {
                        // Pivot read through getAttribute(), matching Lease::pivotWindow() — with
                        // `units()` now carrying its generic, `$u` is a typed Unit, and `->pivot`
                        // is a dynamic relation attribute no static analysis can see on it.
                        $extra = $record->units->reject(fn (Unit $u) => $u->getAttribute('pivot')?->getAttribute('is_master'));

                        return $extra->isNotEmpty() ? '+ '.$extra->pluck('code')->join(', ') : null;
                    }),
                TextColumn::make('tenant.name')
                    ->label(__('admin.tables.lease.tenant'))
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('base_rent_monthly')
                    ->label(__('admin.tables.lease.rent'))
                    ->money('EGP')
                    ->sortable()
                    ->alignRight()
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
                // **The deposit, as a SHORTFALL rather than as a contract figure.** The list showed
                // neither: "who still owes me a deposit?" could only be answered by opening every
                // lease in turn, and the one number that matters — agreed MINUS actually held — was
                // computed nowhere an operator could see it. Toggleable-on by default because it is
                // exposure, not reference data; hidden it would be back to where it was.
                TextColumn::make('deposit_shortfall')
                    ->label(__('admin.tables.lease.deposit_shortfall'))
                    // `depositHeld()` is TWO queries that cannot be eager-loaded — it sums
                    // `deposit_applications` by lease id and re-derives settled deposit BILLINGS
                    // through `InvoiceItemSettlement`, both as their own queries rather than as
                    // relations. This column used to call it twice per row (once here, once in the
                    // description), which measured at 100 queries on a 25-row page for one column.
                    //
                    // Computed once and stashed on the record for the description to read. NOT
                    // memoised on the model: `depositHeld()` also backs the refund GUARD in
                    // LeaseActions ("you cannot refund more than is held"), and a money guard must
                    // never read a figure cached from earlier in the request. A render-only
                    // attribute on a row that is never saved cannot reach it.
                    ->getStateUsing(function (Lease $record): float {
                        $record->setAttribute('deposit_held_for_display', $record->depositHeld());

                        return $record->depositShortfall();
                    })
                    ->money('EGP')
                    ->alignRight()
                    ->toggleable()
                    ->color(fn ($state) => $state > 0 ? 'danger' : 'success')
                    ->icon(fn ($state) => $state > 0 ? Heroicon::OutlinedExclamationTriangle : null)
                    // Held AND agreed underneath, so the shortfall is never a figure to take on
                    // trust — the operator can see the subtraction that produced it.
                    // Reads what getStateUsing() just computed — Filament resolves the state
                    // before the description for the same record instance. Falls back to asking
                    // the model if that ever stops being true, so the figure can be stale-free
                    // wrong-order-proof rather than silently blank.
                    ->description(fn (Lease $record) => __('admin.tables.lease.deposit_held_of', [
                        'held' => number_format(
                            $record->getAttribute('deposit_held_for_display') ?? $record->depositHeld(),
                            2,
                        ),
                        'agreed' => number_format((float) $record->security_deposit, 2),
                    ])),
                TextColumn::make('commencement_date')
                    ->label(__('admin.tables.lease.start'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('expiry_date')
                    ->label(__('admin.tables.lease.ends'))
                    ->date('d/m/Y')
                    ->sortable()
                    ->color(function ($record) {
                        $days = (int) now()->diffInDays($record->expiry_date, false);
                        if ($days < 30) {
                            return 'danger';
                        }
                        if ($days < 90) {
                            return 'warning';
                        }

                        return null;
                    }),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.lease.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'pending_approval' => 'warning',
                        'renewed' => 'info',
                        'terminated', 'cancelled' => 'danger',
                        default => 'gray',
                    }),

                // The operator's own fields (D-7). Hidden until asked for, so a list
                // nobody customised is unchanged.
                ...CustomFieldsTable::columns('lease'),
            ])
            ->filters([
                // "How much of the book is renewals" is a leasing question the rent roll could not
                // answer, even though the data was there.
                SelectFilter::make('lease_type')
                    ->label(__('admin.filters.lease_type'))
                    ->options(fn () => [
                        Lease::TYPE_NEW => __('admin.lease_types.new'),
                        Lease::TYPE_RENEWAL => __('admin.lease_types.renewal'),
                    ])
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        Lease::TYPE_RENEWAL => $query->whereNotNull('previous_lease_id'),
                        Lease::TYPE_NEW => $query->whereNull('previous_lease_id'),
                        default => $query,
                    }),
                // An option nobody recorded is an option nothing will ever remind anyone about —
                // `leases:scan-option-windows` reads these rows and nothing else. The lease's own
                // panel says so when it is empty; this is the same fact asked of the PORTFOLIO, so
                // "which contracts have not been abstracted yet" is one click rather than a
                // question nobody can put to the system.
                // The question an operator actually asks, as one click.
                Filter::make('deposit_outstanding')
                    ->label(__('admin.filters.deposit_outstanding'))
                    ->query(fn ($query) => $query
                        ->whereIn('status', ['active', 'pending_approval'])
                        ->whereRaw('COALESCE(security_deposit, 0) > (
                            COALESCE((select sum(case when type = \'receipt\' then amount else -amount end)
                                      from deposit_transactions
                                      where deposit_transactions.lease_id = leases.id
                                        and deposit_transactions.status = \'recorded\'
                                        and deposit_transactions.deleted_at is null), 0)
                            - COALESCE((select sum(amount) from deposit_applications
                                        where deposit_applications.lease_id = leases.id), 0)
                        )')),
                Filter::make('without_options')
                    ->label(__('admin.filters.without_options'))
                    ->toggle()
                    ->query(fn ($query) => $query->whereDoesntHave('options')),
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => collect(__('admin.statuses.lease'))->except('cancelled')->all()),
                EntitySelectFilter::make('tenant_id')
                    ->label(__('admin.filters.tenant'))
                    ->relationship('tenant')
                    ->entity(Tenant::class),
                EntitySelectFilter::make('unit_id')
                    ->label(__('admin.filters.unit'))
                    ->relationship('unit')
                    ->entity(Unit::class),
                // The percentage-rent basis is a term in each contract, and the system cannot know
                // which applies. This makes "show me every lease still on the monthly basis" one
                // click, so the clause review is a filter rather than a database query.
                SelectFilter::make('percentage_rent_frequency')
                    ->label(__('admin.filters.percentage_rent_basis'))
                    ->options(fn () => __('admin.enums.percentage_rent_frequency'))
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['value'] ?? null, fn (Builder $q, $v) => $q
                            ->where('has_percentage_rent', true)
                            ->where('percentage_rent_frequency', $v))),
                Filter::make('commencement_range')
                    ->label(__('admin.tables.lease.start'))
                    ->schema([
                        DatePicker::make('commencement_from')
                            ->label(__('admin.filters.commencement_from'))
                            ->native(false),
                        DatePicker::make('commencement_until')
                            ->label(__('admin.filters.commencement_until'))
                            ->native(false),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['commencement_from'] ?? null, fn (Builder $q, $date) => $q->whereDate('commencement_date', '>=', $date))
                        ->when($data['commencement_until'] ?? null, fn (Builder $q, $date) => $q->whereDate('commencement_date', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['commencement_from'] ?? null) {
                            $indicators[] = __('admin.filters.commencement_from').': '.Carbon::parse($data['commencement_from'])->format('d/m/Y');
                        }
                        if ($data['commencement_until'] ?? null) {
                            $indicators[] = __('admin.filters.commencement_until').': '.Carbon::parse($data['commencement_until'])->format('d/m/Y');
                        }

                        return $indicators;
                    }),
                Filter::make('expiry_range')
                    ->label(__('admin.tables.lease.ends'))
                    ->schema([
                        DatePicker::make('expiry_from')
                            ->label(__('admin.filters.expiry_from'))
                            ->native(false),
                        DatePicker::make('expiry_until')
                            ->label(__('admin.filters.expiry_until'))
                            ->native(false),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['expiry_from'] ?? null, fn (Builder $q, $date) => $q->whereDate('expiry_date', '>=', $date))
                        ->when($data['expiry_until'] ?? null, fn (Builder $q, $date) => $q->whereDate('expiry_date', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['expiry_from'] ?? null) {
                            $indicators[] = __('admin.filters.expiry_from').': '.Carbon::parse($data['expiry_from'])->format('d/m/Y');
                        }
                        if ($data['expiry_until'] ?? null) {
                            $indicators[] = __('admin.filters.expiry_until').': '.Carbon::parse($data['expiry_until'])->format('d/m/Y');
                        }

                        return $indicators;
                    }),
                Filter::make('expiring_soon')
                    ->label(__('admin.filters.expiring_soon'))
                    ->query(fn (Builder $query) => $query->where('status', 'active')->whereBetween('expiry_date', [now(), now()->addDays(90)])),
                // An option whose notice window CLOSES soon — the deadline that cannot be
                // recovered once missed. `leases:scan-option-windows` notifies on it; this is where
                // the notification (and the dashboard's work-list) lands.
                Filter::make('option_closing')
                    ->label(__('admin.filters.option_closing'))
                    ->query(fn (Builder $query) => $query->whereHas('options', fn ($q) => $q
                        ->where('status', 'open')
                        ->whereNotNull('latest_notice_date')
                        ->whereBetween('latest_notice_date', [now()->startOfDay(), now()->addDays(90)->endOfDay()]))),
                // Holdover: active leases PAST their end date (billing has silently stopped).
                Filter::make('holdover')
                    ->label(__('admin.filters.holdover'))
                    ->query(fn (Builder $query) => $query->holdover()),
                // Percentage-rent tenants who have not reported LAST month's sales, so their
                // overage cannot be billed. The dashboard card counted them and then dropped the
                // operator on the unfiltered declarations list, which is the one place the missing
                // ones by definition are not. Shares Lease::scopeOwingSalesDeclaration() with that
                // card so the count and the list cannot describe different leases.
                Filter::make('missing_sales')
                    ->label(__('admin.filters.missing_sales'))
                    ->query(fn (Builder $query) => $query->owingSalesDeclaration(
                        CarbonImmutable::now()->subMonthNoOverflow()->startOfMonth(),
                    )),
                TrashedFilter::make(),

                ...CustomFieldsTable::filters('lease'),
            ])
            ->filtersFormColumns(2)
            ->headerActions([
                ExportAction::make()
                    ->exporter(LeaseExporter::class)
                    ->label(__('admin.actions.export'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->visible(fn (): bool => Exports::allowed(LeaseResource::class))
                    ->authorize(fn (): bool => Exports::allowed(LeaseResource::class)),
                Action::make('quickLease')
                    ->label(__('admin.actions.quick_new_lease'))
                    ->icon('heroicon-o-bolt')
                    ->color('primary')
                    ->visible(fn () => LeaseResource::canCreate())
                    // Gated in BOTH, per the project invariant: visible() is the UI, authorize() is
                    // the gate. quickLease creates a Lease + its Charges + optionally a whole
                    // Tenant, and its action() guarded only property isolation, not permission.
                    ->authorize(fn () => LeaseResource::canCreate())
                    ->modalHeading(__('admin.actions.quick_new_lease_modal_heading'))
                    ->modalSubmitActionLabel(__('admin.actions.quick_new_lease_submit'))
                    ->modalWidth('4xl')
                    ->fillForm([
                        'tenant_mode' => 'new',
                        'tenant' => ['type' => 'company'],
                        'lease' => [
                            'commencement_date' => now()->toDateString(),
                            'term_months' => 36,
                            'service_charge_monthly' => 0,
                            'payment_terms_days' => 7,
                            'escalation_rate' => 7,
                        ],
                    ])
                    ->schema([
                        Wizard::make([
                            Step::make(__('admin.resources.tenant.singular'))
                                ->icon('heroicon-o-user')
                                ->columns(2)
                                ->schema([
                                    Select::make('tenant_mode')
                                        ->label(__('admin.fields.tenant_mode'))
                                        ->options([
                                            'new' => __('admin.fields.tenant_mode_new'),
                                            'existing' => __('admin.fields.tenant_mode_existing'),
                                        ])
                                        ->default('new')
                                        ->required()
                                        ->live()
                                        ->columnSpanFull(),
                                    EntitySelect::make('tenant_id')
                                        ->label(__('admin.fields.pick_existing_tenant'))
                                        ->entity(Tenant::class)
                                        ->searchable()
                                        ->required()
                                        ->visible(fn (Get $get) => $get('tenant_mode') === 'existing')
                                        ->columnSpanFull(),
                                    TextInput::make('tenant.name')
                                        ->label(__('admin.fields.brand_name'))
                                        ->required()
                                        ->maxLength(100)
                                        ->visible(fn (Get $get) => $get('tenant_mode') === 'new'),
                                    TextInput::make('tenant.legal_name')
                                        ->label(__('admin.fields.legal_name'))
                                        ->maxLength(150)
                                        ->visible(fn (Get $get) => $get('tenant_mode') === 'new'),
                                    Select::make('tenant.type')
                                        ->label(__('admin.fields.type'))
                                        ->options([
                                            'individual' => __('admin.fields.individual'),
                                            'company' => __('admin.fields.company'),
                                        ])
                                        ->default('company')
                                        ->required()
                                        ->native(false)
                                        ->visible(fn (Get $get) => $get('tenant_mode') === 'new'),
                                    TextInput::make('tenant.email')
                                        ->label(__('admin.fields.email'))
                                        ->email()
                                        ->visible(fn (Get $get) => $get('tenant_mode') === 'new'),
                                    TextInput::make('tenant.phone')
                                        ->label(__('admin.fields.phone'))
                                        ->tel()
                                        ->visible(fn (Get $get) => $get('tenant_mode') === 'new'),
                                    TextInput::make('tenant.contact_person')
                                        ->label(__('admin.fields.contact_person'))
                                        ->visible(fn (Get $get) => $get('tenant_mode') === 'new'),
                                ]),
                            Step::make(__('admin.resources.lease.singular'))
                                ->icon('heroicon-o-document-text')
                                ->columns(2)
                                ->schema([
                                    Toggle::make('lease.show_occupied_units')
                                        ->label(__('admin.fields.show_occupied_units'))
                                        ->helperText(__('admin.helpers.show_occupied_units'))
                                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.show_occupied_units'))
                                        ->live()
                                        ->dehydrated(false)
                                        ->default(false)
                                        ->columnSpanFull(),
                                    EntitySelect::make('lease.unit_id')
                                        ->label(__('admin.fields.unit_label'))
                                        ->entity(Unit::class)
                                        ->preload()
                                        // Property isolation is OptionDisplay's; what stays is this
                                        // screen's own rule — vacant space unless the operator asks
                                        // to see the rest.
                                        ->modifyOptionsQuery(fn ($query, Get $get) => $query->when(
                                            ! $get('lease.show_occupied_units'),
                                            fn ($q) => $q->where('status', 'vacant'),
                                        ))
                                        ->required()
                                        ->columnSpanFull()
                                        ->helperText(fn (Get $get): string => $get('lease.show_occupied_units')
                                            ? __('admin.helpers.unit_showing_all')
                                            : __('admin.helpers.only_available_units')),
                                    DatePicker::make('lease.commencement_date')
                                        ->label(__('admin.fields.commencement_date'))
                                        ->required()
                                        ->native(false),
                                    TextInput::make('lease.term_months')
                                        ->label(__('admin.fields.term_months'))
                                        ->numeric()
                                        ->minValue(1)
                                        ->required()
                                        ->suffix(__('admin.fields.months')),
                                    TextInput::make('lease.base_rent_monthly')
                                        ->label(__('admin.fields.base_rent_monthly'))
                                        ->prefix('EGP')
                                        ->numeric()
                                        ->minValue(0)
                                        ->required(),
                                    TextInput::make('lease.service_charge_monthly')
                                        ->label(__('admin.fields.service_charge_monthly'))
                                        ->prefix('EGP')
                                        ->numeric()
                                        ->minValue(0)
                                        ->helperText(__('admin.fields.service_charge_helper')),
                                    TextInput::make('lease.security_deposit')
                                        ->label(__('admin.fields.security_deposit'))
                                        ->prefix('EGP')
                                        ->numeric()
                                        ->minValue(0)
                                        ->helperText(__('admin.fields.security_deposit_helper')),
                                    TextInput::make('lease.payment_terms_days')
                                        ->label(__('admin.fields.payment_terms_days'))
                                        ->numeric()
                                        ->minValue(0)
                                        ->suffix(__('admin.fields.days')),
                                ]),
                        ])
                            ->skippable(false)
                            ->columnSpanFull(),
                    ])
                    ->action(function (array $data) {
                        // Server-side property-isolation guard — the picker is scoped, but
                        // re-validate the submitted unit so a tampered id can't create a
                        // lease (+ charges + tenant) in a property outside the user's set.
                        LeaseResource::assertUnitAssetInScope($data['lease']['unit_id'] ?? null);

                        $lease = app(LeaseCreationService::class)->create($data);

                        Notification::make()
                            ->title(__('admin.actions.lease_created'))
                            ->body(__('admin.actions.lease_created_body', [
                                'ref' => $lease->reference,
                                'tenant' => $lease->tenant->name,
                                'unit' => $lease->unit->code,
                                'rent' => number_format((float) $lease->base_rent_monthly, 2),
                            ]))
                            ->success()
                            ->send();
                    }),
            ])
            ->recordActions([
                // Read the record without opening its edit form — less
                // friction, and no write surface for view-only roles. The
                // schema is the resource's own form rendered disabled, so it
                // cannot drift from the fields that actually exist.
                ViewAction::make()
                    ->visible(fn ($record) => LeaseResource::canView($record))
                    ->authorize(fn ($record) => LeaseResource::canView($record)),
                EditAction::make()
                    ->visible(fn ($record) => LeaseResource::canEdit($record)),
                // ── The list FINDS; the record ACTS ───────────────────────────────────────
                // Nine commercial actions used to hang off every row here while the lease's own
                // page carried one, so an operator who opened a lease had to go back to the list to
                // do anything to it — backwards from the record-hub architecture this project took
                // from Yardi (docs/benchmarks/yardi/08), and a row of nine equally-weighted verbs
                // reads as noise rather than as choices. They live on the lease page now, grouped,
                // and are defined once in App\Filament\Admin\Actions\LeaseActions so the two
                // surfaces cannot drift the way they already had.
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    ExportBulkAction::make()
                        ->exporter(LeaseExporter::class)
                        ->label(__('admin.actions.export'))
                        ->visible(fn (): bool => Exports::allowed(LeaseResource::class))
                        ->authorize(fn (): bool => Exports::allowed(LeaseResource::class)),
                    DeleteBulkAction::make()
                        ->visible(fn () => LeaseResource::canDeleteAny()),
                    ForceDeleteBulkAction::make()
                        ->visible(fn () => LeaseResource::canForceDeleteAny()),
                    RestoreBulkAction::make()
                        ->visible(fn () => LeaseResource::canRestoreAny()),
                ]),
            ])
            ->defaultSort('commencement_date', 'desc')
            ->emptyStateIcon('heroicon-o-document-text')
            ->emptyStateHeading(__('admin.empty.leases.heading'))
            ->emptyStateDescription(__('admin.empty.leases.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('admin.empty.leases.cta'))
                    ->icon('heroicon-o-plus'),
            ]);
    }
}
