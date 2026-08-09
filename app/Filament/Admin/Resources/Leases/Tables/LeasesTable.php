<?php

namespace App\Filament\Admin\Resources\Leases\Tables;

use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Filament\Exports\LeaseExporter;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\ConvertLeaseToHoldoverService;
use App\Services\ExerciseLeaseOptionService;
use App\Services\LeaseCreationService;
use App\Services\LeaseReliefService;
use App\Services\LeaseRenewalService;
use App\Services\LeaseRentChangeService;
use App\Services\LeaseSpaceChangeService;
use App\Services\MoveOutStatementService;
use App\Services\LeaseTerminationService;
use App\Services\SettleMoveOutService;
use App\Support\TenantScope;
use Carbon\Carbon;
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
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Wizard;
use Filament\Schemas\Components\Wizard\Step;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class LeasesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['unit', 'tenant']))
            ->columns([
                TextColumn::make('reference')
                    ->label(__('admin.tables.lease.reference'))
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->size('xs'),
                TextColumn::make('unit.code')
                    ->label(__('admin.tables.lease.unit'))
                    ->badge()
                    ->color('gray')
                    ->searchable()
                    // Surface multi-unit leases: list the additional (non-master) units.
                    ->description(function (Lease $record): ?string {
                        $extra = $record->units->reject(fn ($u) => $u->pivot->is_master);

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
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => collect(__('admin.statuses.lease'))->except('cancelled')->all()),
                SelectFilter::make('tenant_id')
                    ->label(__('admin.filters.tenant'))
                    ->relationship('tenant', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('unit_id')
                    ->label(__('admin.filters.unit'))
                    ->relationship('unit', 'code')
                    ->searchable()
                    ->preload(),
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
                // Holdover: active leases PAST their end date (billing has silently stopped).
                Filter::make('holdover')
                    ->label(__('admin.filters.holdover'))
                    ->query(fn (Builder $query) => $query->holdover()),
                TrashedFilter::make(),
            ])
            ->filtersFormColumns(2)
            ->headerActions([
                ExportAction::make()
                    ->exporter(LeaseExporter::class)
                    ->label(__('admin.actions.export'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray'),
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
                                    Select::make('tenant_id')
                                        ->label(__('admin.fields.pick_existing_tenant'))
                                        ->options(fn () => TenantScope::selectableTenantOptions())
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
                                        ->live()
                                        ->dehydrated(false)
                                        ->default(false)
                                        ->columnSpanFull(),
                                    Select::make('lease.unit_id')
                                        ->label(__('admin.fields.unit_label'))
                                        ->options(fn (Get $get) => Unit::with('asset')
                                            // Property isolation: never offer a unit outside the
                                            // user's visible set (null = super_admin/portfolio).
                                            ->when(
                                                TenantScope::visibleAssetIds(),
                                                fn ($q, $ids) => $q->whereIn('asset_id', $ids),
                                            )
                                            ->when(
                                                ! $get('lease.show_occupied_units'),
                                                fn ($q) => $q->where('status', 'vacant'),
                                            )
                                            ->get()
                                            ->mapWithKeys(fn (Unit $u) => [$u->id => sprintf(
                                                '%s · %s · %s',
                                                $u->fullName(),
                                                __("admin.enums.category.{$u->category}"),
                                                __("admin.statuses.unit.{$u->status}"),
                                            )]))
                                        ->searchable()
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
                Action::make('renew')
                    ->label(__('admin.actions.renew'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('success')
                    ->visible(fn ($record) => $record->status === 'active' && LeaseResource::canEdit($record) && auth()->user()?->can('leases.renew'))
                    ->authorize(fn () => auth()->user()?->can('leases.renew') ?? false)
                    ->modalHeading(fn (Lease $record) => __('admin.actions.renew_modal_heading', ['ref' => $record->reference]))
                    ->modalDescription(function (Lease $record): string {
                        $base = __('admin.actions.renew_modal_description', ['ends' => $record->expiry_date->format('d/m/Y')]);
                        $terms = app(ExerciseLeaseOptionService::class)->pendingRenewalTerms($record);

                        // Say WHERE the pre-filled numbers came from. A form that silently arrives
                        // with different figures than last time is one an operator stops trusting.
                        return $terms === null ? $base : $base.' '.__('admin.actions.renew_from_option', [
                            'basis' => __("admin.lease_options.rent_bases.{$terms['option']->rent_basis}"),
                        ]);
                    })
                    // Pre-filled from an EXERCISED option where there is one (story OP-04). The
                    // option already knows the contracted term and rent; before this the operator
                    // read them off the screen and re-typed them, which is how a five-year renewal
                    // at a contracted +10% gets billed at the old rent.
                    //
                    // A `market` or `cpi` basis yields no rent — a valuation and an index feed are
                    // not numbers this system may invent — so those fall back to the current rent
                    // for the operator to overwrite, exactly as the escalation sweep refuses CPI.
                    ->fillForm(function (Lease $record): array {
                        $terms = app(ExerciseLeaseOptionService::class)->pendingRenewalTerms($record);

                        return [
                            'new_term_months' => $terms['term_months'] ?? $record->term_months,
                            'new_rent' => $terms['rent'] ?? (float) $record->base_rent_monthly,
                            'new_service_charge' => (float) $record->service_charge_monthly,
                            'commencement_date' => ($terms['commencement'] ?? $record->expiry_date->copy()->addDay())
                                ->toDateString(),
                        ];
                    })
                    ->schema([
                        TextInput::make('new_term_months')
                            ->label(__('admin.fields.new_term_months'))
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                        TextInput::make('new_rent')
                            ->label(__('admin.fields.new_rent'))
                            ->prefix('EGP')
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                        TextInput::make('new_service_charge')
                            ->label(__('admin.fields.new_service_charge'))
                            ->prefix('EGP')
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                        DatePicker::make('commencement_date')
                            ->label(__('admin.fields.commencement_date'))
                            ->required(),
                    ])
                    ->action(function (Lease $record, array $data) {
                        try {
                            $renewal = app(LeaseRenewalService::class)->renew($record, $data);
                        } catch (\InvalidArgumentException $e) {
                            // A stale/concurrent modal (the lease changed state since it opened) hits the
                            // service status guard — surface it as a toast, not an uncaught Livewire 500.
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('admin.actions.lease_renewed'))
                            ->body(__('admin.actions.lease_renewed_body', [
                                'ref' => $renewal->reference,
                                'months' => $renewal->term_months,
                                'start' => $renewal->commencement_date->format('d/m/Y'),
                            ]))
                            ->success()
                            ->send();
                    }),
                Action::make('changeRent')
                    ->label(__('admin.actions.change_rent'))
                    ->icon('heroicon-o-currency-dollar')
                    ->color('warning')
                    ->visible(fn (Lease $record) => in_array($record->status, ['active', 'pending_approval'], true) && LeaseResource::canEdit($record))
                    ->authorize(fn () => auth()->user()?->can('leases.edit') ?? false)
                    ->modalHeading(fn (Lease $record) => __('admin.actions.change_rent_modal_heading', ['ref' => $record->reference]))
                    ->modalDescription(__('admin.actions.change_rent_modal_description'))
                    ->fillForm(fn (Lease $record) => [
                        'base_rent_monthly' => (float) $record->base_rent_monthly,
                        'service_charge_monthly' => (float) $record->service_charge_monthly,
                    ])
                    ->schema([
                        TextInput::make('base_rent_monthly')
                            ->label(__('admin.fields.base_rent_monthly'))
                            ->prefix('EGP')
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                        TextInput::make('service_charge_monthly')
                            ->label(__('admin.fields.service_charge_monthly'))
                            ->prefix('EGP')
                            ->numeric()
                            ->minValue(0)
                            ->required(),
                        // The date the new rent TAKES EFFECT. The schedule has supported this since
                        // phase 1 — nothing exposed it, so every operator change silently landed on
                        // "today" and a rent agreed in advance could not be entered in advance.
                        DatePicker::make('effective_from')
                            ->label(__('admin.actions.change_rent_effective_from'))
                            ->helperText(__('admin.actions.change_rent_effective_from_hint'))
                            ->default(now()->startOfMonth())
                            ->required(),
                        // Required, not optional: this is where story LE-01's "a rent change cannot
                        // happen without a reason" is enforced, because the form is the only path
                        // with a human present to give one.
                        Textarea::make('reason')
                            ->label(__('admin.actions.change_rent_reason'))
                            ->placeholder(__('admin.actions.change_rent_reason_placeholder'))
                            ->rows(2)
                            ->required(),
                        TextInput::make('document_reference')
                            ->label(__('admin.lease_events.document'))
                            ->helperText(__('admin.lease_events.document_hint'))
                            ->maxLength(255),
                    ])
                    ->action(function (Lease $record, array $data) {
                        try {
                            $updated = app(LeaseRentChangeService::class)->apply($record, $data);
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('admin.actions.rent_changed'))
                            ->body(__('admin.actions.rent_changed_body', [
                                'ref' => $updated->reference,
                                'rent' => 'EGP '.number_format((float) $updated->base_rent_monthly, 2),
                                'service' => 'EGP '.number_format((float) $updated->service_charge_monthly, 2),
                            ]))
                            ->success()
                            ->send();
                    }),
                // Temporary relief (LE-03) is deliberately its own action rather than a checkbox on
                // "Change Rent": a concession and a renegotiation are different deals with
                // different consequences, and the whole point of the story is that the system can
                // finally tell them apart.
                Action::make('grantRelief')
                    ->label(__('admin.actions.grant_relief'))
                    ->icon('heroicon-o-receipt-percent')
                    ->color('warning')
                    ->visible(fn (Lease $record) => in_array($record->status, ['active', 'pending_approval'], true) && LeaseResource::canEdit($record))
                    ->authorize(fn () => auth()->user()?->can('leases.edit') ?? false)
                    ->modalHeading(fn (Lease $record) => __('admin.actions.grant_relief_modal_heading', ['ref' => $record->reference]))
                    ->modalDescription(__('admin.actions.grant_relief_modal_description'))
                    ->schema([
                        Select::make('type')
                            ->label(__('admin.fields.type'))
                            ->options([
                                'base_rent' => __('admin.fields.base_rent_monthly'),
                                'service_charge' => __('admin.fields.service_charge_monthly'),
                            ])
                            ->default('base_rent')
                            ->required(),
                        Select::make('basis')
                            ->label(__('admin.actions.relief_basis'))
                            ->options([
                                'percent' => __('admin.actions.relief_basis_percent'),
                                'amount' => __('admin.actions.relief_basis_amount'),
                            ])
                            ->default('percent')
                            ->live()
                            ->required(),
                        TextInput::make('percent_off')
                            ->label(__('admin.actions.relief_percent_off'))
                            ->suffix('%')
                            ->numeric()
                            ->minValue(0.01)
                            ->maxValue(100)
                            ->visible(fn (Get $get) => $get('basis') === 'percent')
                            ->required(fn (Get $get) => $get('basis') === 'percent'),
                        TextInput::make('amount')
                            ->label(__('admin.actions.relief_amount'))
                            ->prefix('EGP')
                            ->numeric()
                            ->minValue(0)
                            ->visible(fn (Get $get) => $get('basis') === 'amount')
                            ->required(fn (Get $get) => $get('basis') === 'amount'),
                        DatePicker::make('from')
                            ->label(__('admin.actions.relief_from'))
                            ->default(now()->startOfMonth())
                            ->required(),
                        DatePicker::make('to')
                            ->label(__('admin.actions.relief_to'))
                            ->helperText(__('admin.actions.relief_to_hint'))
                            ->required()
                            ->after('from'),
                        Textarea::make('reason')
                            ->label(__('admin.actions.change_rent_reason'))
                            ->rows(2)
                            ->required(),
                        TextInput::make('document_reference')
                            ->label(__('admin.lease_events.document'))
                            ->helperText(__('admin.lease_events.document_hint'))
                            ->maxLength(255),
                    ])
                    ->action(function (Lease $record, array $data) {
                        // The basis selector is UI only — the service takes whichever of the two
                        // numbers was filled, so send exactly one and let it validate.
                        $payload = $data;
                        unset($payload['basis']);
                        if (($data['basis'] ?? 'percent') === 'percent') {
                            $payload['amount'] = null;
                        } else {
                            $payload['percent_off'] = null;
                        }

                        try {
                            $result = app(LeaseReliefService::class)->grant($record, $payload);
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        $reverts = $result['resumed'];

                        Notification::make()
                            ->title(__('admin.actions.relief_granted'))
                            ->body(__('admin.actions.relief_granted_body', [
                                'ref' => $record->reference,
                                'amount' => 'EGP '.number_format((float) ($result['relief'][0]->amount ?? 0), 2),
                                'until' => $result['relief'] ? end($result['relief'])->end_date->format('d/m/Y') : '—',
                                'reverts' => $reverts
                                    ? 'EGP '.number_format((float) $reverts->amount, 2)
                                    : __('admin.actions.relief_no_reversion'),
                            ]))
                            ->success()
                            ->send();
                    }),
                // The move-out final account (MF-03). Native Filament: the statement renders as
                // Placeholders inside the action's own schema, beside the one editable part (the
                // deductions), so the operator reads and settles in one place instead of pricing a
                // refund from three screens.
                Action::make('finalAccount')
                    ->label(__('admin.move_out.settle'))
                    ->icon('heroicon-o-clipboard-document-check')
                    ->color('primary')
                    ->visible(fn (Lease $record) => in_array($record->status, ['terminated', 'expired'], true)
                        && LeaseResource::canEdit($record))
                    ->authorize(fn () => auth()->user()?->can('leases.edit') ?? false)
                    ->modalHeading(fn (Lease $record) => __('admin.move_out.heading', ['ref' => $record->reference]))
                    ->modalDescription(__('admin.move_out.description'))
                    ->modalSubmitActionLabel(__('admin.move_out.settle'))
                    ->schema([
                        Placeholder::make('statement')
                            ->hiddenLabel()
                            ->columnSpanFull()
                            ->content(function (Lease $record) {
                                $s = app(MoveOutStatementService::class)->for($record);
                                $money = fn (float $v) => 'EGP '.number_format($v, 2);

                                $rows = [
                                    [__('admin.move_out.contractual_deposit'), $money((float) $s['contractual_deposit'])],
                                    [__('admin.move_out.deposit_held'), $money((float) $s['deposit_held'])],
                                ];

                                if ((float) $s['deposit_shortfall'] > 0) {
                                    $rows[] = [__('admin.move_out.deposit_shortfall'), $money((float) $s['deposit_shortfall'])];
                                }

                                $rows[] = [__('admin.move_out.open_ar'), $money((float) $s['open_ar'])];
                                $rows[] = [__('admin.move_out.tenant_credit'), $money((float) $s['tenant_credit'])];
                                $rows[] = [
                                    (float) $s['residual_debt'] > 0
                                        ? __('admin.move_out.residual_debt')
                                        : __('admin.move_out.net_to_tenant'),
                                    $money((float) $s['residual_debt'] > 0 ? (float) $s['residual_debt'] : (float) $s['net_to_tenant']),
                                ];

                                $lines = collect($rows)->map(fn (array $r) => "**{$r[0]}:** {$r[1]}")->join("  \n");

                                $pending = collect($s['pending_trueups'])->pluck('detail');
                                $lines .= "\n\n**".__('admin.move_out.pending').'**  '."\n"
                                    .($pending->isNotEmpty() ? '- '.$pending->join("\n- ") : __('admin.move_out.pending_none'));

                                $lines .= "\n\n_".__('admin.move_out.ar_note').'_';

                                return str($lines)->markdown()->toHtmlString();
                            }),
                        Repeater::make('deductions')
                            ->label(__('admin.move_out.deductions'))
                            ->addActionLabel(__('admin.move_out.add_deduction'))
                            ->schema([
                                TextInput::make('description')
                                    ->label(__('admin.move_out.deduction_description'))
                                    ->required(),
                                TextInput::make('amount')
                                    ->label(__('admin.move_out.deduction_amount'))
                                    ->prefix('EGP')
                                    ->numeric()
                                    ->minValue(0)
                                    ->required(),
                            ])
                            ->columns(2)
                            ->default([])
                            ->columnSpanFull(),
                        DatePicker::make('settlement_date')
                            ->label(__('admin.lease_events.effective'))
                            ->default(now())
                            ->required(),
                        TextInput::make('document_reference')
                            ->label(__('admin.lease_events.document'))
                            ->helperText(__('admin.lease_events.document_hint'))
                            ->maxLength(255),
                    ])
                    ->action(function (Lease $record, array $data) {
                        try {
                            $result = app(SettleMoveOutService::class)->settle($record, $data);
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('admin.move_out.settled'))
                            ->body(__('admin.move_out.settled_body', [
                                'ref' => $record->reference,
                                'refunded' => 'EGP '.number_format((float) ($result['refund']->amount ?? 0), 2),
                                'deducted' => 'EGP '.number_format((float) ($result['forfeit']->amount ?? 0), 2),
                            ]))
                            ->success()
                            ->send();
                    }),
                // Premises changes (LE-02). Space and money move on ONE date through one service —
                // the old way was "add a pivot row, then run Change Rent", two undated steps that
                // could disagree about when the deal changed.
                Action::make('changePremises')
                    ->label(__('admin.actions.change_premises'))
                    ->icon('heroicon-o-squares-plus')
                    ->color('info')
                    ->visible(fn (Lease $record) => in_array($record->status, ['active', 'pending_approval'], true) && LeaseResource::canEdit($record))
                    ->authorize(fn () => auth()->user()?->can('leases.edit') ?? false)
                    ->modalHeading(fn (Lease $record) => __('admin.actions.change_premises_modal_heading', ['ref' => $record->reference]))
                    ->modalDescription(__('admin.actions.change_premises_modal_description'))
                    ->schema([
                        Select::make('direction')
                            ->label(__('admin.actions.premises_direction'))
                            ->options([
                                'expand' => __('admin.actions.premises_expand'),
                                'contract' => __('admin.actions.premises_contract'),
                            ])
                            ->default('expand')
                            ->live()
                            ->required(),
                        Select::make('unit_ids')
                            ->label(__('admin.actions.premises_units'))
                            ->multiple()
                            ->required()
                            // Expanding offers vacant units in THIS lease's property only —
                            // scoped through TenantScope, never a bare Unit::all().
                            ->options(function (Get $get, Lease $record) {
                                if ($get('direction') === 'contract') {
                                    return $record->unitsOn(\Carbon\CarbonImmutable::now())
                                        ->reject(fn (Unit $u) => (int) $u->id === (int) $record->unit_id)
                                        ->pluck('code', 'id')
                                        ->all();
                                }

                                return Unit::query()
                                    ->whereIn('asset_id', TenantScope::visibleAssetIds())
                                    ->when($record->unit?->asset_id, fn ($q, $id) => $q->where('asset_id', $id))
                                    ->whereNotIn('id', $record->units->pluck('id'))
                                    ->orderBy('code')
                                    ->get()
                                    ->reject(fn (Unit $u) => $u->isActivelyLeased($record->id))
                                    ->pluck('code', 'id')
                                    ->all();
                            }),
                        DatePicker::make('effective_from')
                            ->label(__('admin.actions.premises_effective_from'))
                            ->helperText(__('admin.actions.premises_effective_from_hint'))
                            ->default(now()->addMonth()->startOfMonth())
                            ->required(),
                        TextInput::make('new_total_rent')
                            ->label(__('admin.actions.premises_new_total_rent'))
                            ->helperText(__('admin.actions.premises_new_total_rent_hint'))
                            ->prefix('EGP')
                            ->numeric()
                            ->minValue(0),
                        Textarea::make('reason')
                            ->label(__('admin.actions.change_rent_reason'))
                            ->rows(2)
                            ->required(),
                        TextInput::make('document_reference')
                            ->label(__('admin.lease_events.document'))
                            ->helperText(__('admin.lease_events.document_hint'))
                            ->maxLength(255),
                    ])
                    ->action(function (Lease $record, array $data) {
                        $service = app(LeaseSpaceChangeService::class);

                        try {
                            $updated = ($data['direction'] ?? 'expand') === 'contract'
                                ? $service->contract($record, $data)
                                : $service->expand($record, $data);
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('admin.actions.premises_changed'))
                            ->body(__('admin.actions.premises_changed_body', [
                                'ref' => $updated->reference,
                                'area' => number_format($updated->totalAreaSqmOn(\Carbon\CarbonImmutable::parse($data['effective_from'])), 0),
                                'from' => \Carbon\CarbonImmutable::parse($data['effective_from'])->startOfMonth()->format('d/m/Y'),
                            ]))
                            ->success()
                            ->send();
                    }),
                // Holdover conversion (LE-04). Visible only on a lease that has actually expired
                // and has not been converted — this is the action the ActionRequired card exists to
                // prompt, and until it existed the card was the end of the story.
                Action::make('convertToHoldover')
                    ->label(__('admin.actions.convert_to_holdover'))
                    ->icon('heroicon-o-clock')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->visible(fn (Lease $record) => $record->isHoldover() && ! $record->isConvertedHoldover() && LeaseResource::canEdit($record))
                    ->authorize(fn () => auth()->user()?->can('leases.edit') ?? false)
                    ->modalHeading(fn (Lease $record) => __('admin.actions.convert_to_holdover_modal_heading', ['ref' => $record->reference]))
                    ->modalDescription(__('admin.actions.convert_to_holdover_modal_description'))
                    ->fillForm(fn (Lease $record) => [
                        'rate_pct' => (float) app(\App\Settings\BillingSettings::class)->holdover_default_rate_pct,
                        // visible() already proved the lease expired, so expiry_date is present here.
                        'effective_from' => $record->expiry_date->copy()->addDay()->startOfMonth(),
                    ])
                    ->schema([
                        TextInput::make('rate_pct')
                            ->label(__('admin.actions.holdover_rate'))
                            ->helperText(__('admin.actions.holdover_rate_hint'))
                            ->suffix('%')
                            ->numeric()
                            ->minValue(1)
                            ->required(),
                        DatePicker::make('effective_from')
                            ->label(__('admin.actions.holdover_from'))
                            ->helperText(__('admin.actions.holdover_from_hint'))
                            ->required(),
                        Textarea::make('reason')
                            ->label(__('admin.actions.change_rent_reason'))
                            ->rows(2)
                            ->required(),
                        TextInput::make('document_reference')
                            ->label(__('admin.lease_events.document'))
                            ->helperText(__('admin.lease_events.document_hint'))
                            ->maxLength(255),
                    ])
                    ->action(function (Lease $record, array $data) {
                        try {
                            $updated = app(ConvertLeaseToHoldoverService::class)->convert($record, $data);
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('admin.actions.holdover_converted'))
                            ->body(__('admin.actions.holdover_converted_body', [
                                'ref' => $updated->reference,
                                'rent' => 'EGP '.number_format((float) $updated->base_rent_monthly, 2),
                                'from' => $updated->holdover_from->format('d/m/Y'),
                            ]))
                            ->success()
                            ->send();
                    }),
                Action::make('terminate')
                    ->label(__('admin.actions.terminate'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (Lease $record) => in_array($record->status, ['active', 'pending_approval'], true) && LeaseResource::canEdit($record) && auth()->user()?->can('leases.terminate'))
                    ->authorize(fn () => auth()->user()?->can('leases.terminate') ?? false)
                    ->modalHeading(fn (Lease $record) => __('admin.actions.terminate_modal_heading', ['ref' => $record->reference]))
                    ->modalDescription(fn (Lease $record) => __('admin.actions.terminate_modal_description', ['unit' => $record->unit?->code ?? '—']))
                    ->modalSubmitActionLabel(__('admin.actions.terminate_submit'))
                    ->fillForm([
                        'termination_date' => now()->toDateString(),
                        'cancel_open_invoices' => true,
                    ])
                    ->schema([
                        DatePicker::make('termination_date')
                            ->label(__('admin.actions.termination_date'))
                            ->required()
                            ->native(false),
                        Textarea::make('reason')
                            ->label(__('admin.actions.termination_reason'))
                            ->placeholder(__('admin.actions.termination_reason_placeholder'))
                            ->rows(2),
                        Toggle::make('cancel_open_invoices')
                            ->label(__('admin.actions.cancel_open_invoices'))
                            ->helperText(__('admin.actions.cancel_open_invoices_helper'))
                            ->default(true),
                    ])
                    ->action(function (Lease $record, array $data) {
                        try {
                            $terminated = app(LeaseTerminationService::class)->terminate($record, $data);
                        } catch (\InvalidArgumentException $e) {
                            Notification::make()->danger()->title($e->getMessage())->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('admin.actions.lease_terminated'))
                            ->body(__('admin.actions.lease_terminated_body', [
                                'ref' => $terminated->reference,
                                'date' => $terminated->expiry_date->format('d/m/Y'),
                                'unit' => $terminated->unit?->code ?? '—',
                            ]))
                            ->warning()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    ExportBulkAction::make()
                        ->exporter(LeaseExporter::class)
                        ->label(__('admin.actions.export')),
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
