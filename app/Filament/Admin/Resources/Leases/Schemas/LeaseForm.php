<?php

namespace App\Filament\Admin\Resources\Leases\Schemas;

use App\Models\Lease;
use App\Models\Unit;
use App\Support\TenantScope;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class LeaseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.sections.lease_details'))
                ->columns(3)
                ->components([
                    TextInput::make('reference')
                        ->label(__('admin.fields.reference'))
                        ->default(fn () => Lease::generateReference('AW'))
                        ->disabled()
                        ->dehydrated(),
                    Select::make('unit_id')
                        ->label(__('admin.fields.master_unit'))
                        ->live()
                        ->relationship(
                            'unit',
                            'code',
                            modifyQueryUsing: function ($query, Get $get, ?Lease $record) {
                                $query->when(
                                    TenantScope::visibleAssetIds(),
                                    fn ($q, $assetIds) => $q->whereIn('asset_id', $assetIds),
                                );

                                if ($get('show_occupied_units')) {
                                    return $query;
                                }

                                return $query->where(function ($q) use ($record) {
                                    $q->whereNotIn('status', ['occupied', 'reserved']);

                                    if ($record?->unit_id) {
                                        $q->orWhere('id', $record->unit_id);
                                    }
                                });
                            },
                        )
                        ->getOptionLabelFromRecordUsing(fn (Unit $unit) => sprintf(
                            '%s · %s',
                            $unit->code,
                            __("admin.statuses.unit.{$unit->status}"),
                        ))
                        ->searchable()
                        ->preload()
                        ->required()
                        ->helperText(fn (Get $get): ?string => $get('show_occupied_units')
                            ? __('admin.helpers.unit_showing_all')
                            : __('admin.helpers.only_available_units'))
                        ->rules([
                            fn (Get $get, ?Lease $record): Closure => function (string $attribute, $value, Closure $fail) use ($get, $record) {
                                if ($get('status') !== 'active' || ! $value) {
                                    return;
                                }

                                // Clamp before querying. `$value` is a client-supplied unit_id,
                                // and this rule's pass/fail answers "is that unit occupied?" —
                                // so keyed raw it leaks occupancy for a property the user cannot
                                // see. A `unique` rule is not the only shape of this leak: any
                                // validation that queries on a client value tells through its
                                // outcome. Out of scope → skip: the unit_id `in` rule and
                                // LeaseResource::assertUnitAssetInScope() refuse the write anyway.
                                $unitId = TenantScope::clampUnitId($value);

                                if ($unitId === null) {
                                    return;
                                }

                                // Consult the lease_unit pivot (master OR additional), not just the
                                // denormalized leases.unit_id — else a unit already held as an
                                // additional unit in a multi-unit lease could be re-booked here.
                                $unit = \App\Models\Unit::find($unitId);
                                if ($unit && $unit->isActivelyLeased($record?->id)) {
                                    $fail(__('admin.validation.unit_has_active_lease'));
                                }
                            },
                        ]),
                    Select::make('additional_unit_ids')
                        ->label(__('admin.fields.additional_units'))
                        ->helperText(__('admin.fields.additional_units_helper'))
                        ->multiple()
                        ->searchable()
                        ->preload()
                        ->dehydrated(false)
                        ->options(function (Get $get, ?Lease $record) {
                            $assetIds = TenantScope::visibleAssetIds();
                            $master = $get('unit_id');

                            return Unit::query()
                                ->when($assetIds, fn ($q, $aids) => $q->whereIn('asset_id', $aids))
                                ->where(function ($q) use ($record) {
                                    $q->whereNotIn('status', ['occupied', 'reserved', 'maintenance']);
                                    if ($record) {
                                        $q->orWhereIn('id', $record->units()->pluck('units.id'));
                                    }
                                })
                                ->when($master, fn ($q, $m) => $q->where('id', '!=', $m))
                                ->orderBy('code')
                                ->get()
                                ->mapWithKeys(fn (Unit $u) => [$u->id => sprintf(
                                    '%s · %s',
                                    $u->code,
                                    __("admin.statuses.unit.{$u->status}"),
                                )]);
                        }),
                    Select::make('tenant_id')
                        ->label(__('admin.resources.tenant.singular'))
                        // Scope the picker to tenants in the user's visible properties
                        // (+ unaffiliated tenants, which belong to no property yet).
                        ->relationship('tenant', 'name', modifyQueryUsing: function ($query) {
                            $ids = \App\Support\TenantScope::visibleAssetIds();
                            if ($ids === null) {
                                return $query;
                            }

                            return $query->where(fn ($w) => $w
                                ->whereHas('leases.unit', fn ($u) => $u->whereIn('asset_id', $ids))
                                ->orWhereDoesntHave('leases'));
                        })
                        ->searchable()
                        ->preload()
                        ->required()
                        ->createOptionForm([
                            TextInput::make('name')->label(__('admin.fields.brand_name'))->required(),
                            TextInput::make('phone')->label(__('admin.fields.phone'))->tel(),
                            TextInput::make('email')->label(__('admin.fields.email'))->email(),
                        ]),
                    Select::make('status')
                        ->label(__('admin.tables.common.status'))
                        ->options(fn () => __('admin.statuses.lease'))
                        ->default('draft')
                        ->required()
                        ->native(false),
                    Toggle::make('show_occupied_units')
                        ->label(__('admin.fields.show_occupied_units'))
                        ->helperText(__('admin.helpers.show_occupied_units'))
                        ->live()
                        ->dehydrated(false)
                        ->default(false)
                        ->columnSpan(2),
                ]),

            Section::make(__('admin.sections.term'))
                ->columns(3)
                ->components([
                    DatePicker::make('commencement_date')
                        ->label(__('admin.fields.commencement_date'))
                        ->required()
                        ->native(false),
                    TextInput::make('term_months')
                        ->label(__('admin.fields.term_months'))
                        ->numeric()
                        ->default(36)
                        ->required()
                        ->minValue(1)
                        ->maxValue(120)
                        ->helperText(__('admin.helpers.term_months'))
                        ->suffix(__('admin.fields.months')),
                    DatePicker::make('expiry_date')
                        ->label(__('admin.fields.expiry_date'))
                        ->required()
                        ->after('commencement_date')
                        ->native(false),
                ]),

            Section::make(__('admin.sections.financial_terms'))
                ->columns(3)
                ->components([
                    // Rent fields are read-only on Edit. Operators change them
                    // through the "Change rent" record action so the matching
                    // Charge.amount stays in sync (audit M04 F-20 / D-13). On
                    // Create the LeaseObserver seeds the charges from these
                    // values, so they remain editable here.
                    TextInput::make('base_rent_monthly')
                        ->label(__('admin.fields.base_rent_monthly'))
                        ->prefix('EGP')
                        ->numeric()
                        ->required()
                        ->minValue(0)
                        ->disabled(fn (string $operation): bool => $operation === 'edit')
                        ->dehydrated()
                        ->helperText(fn (string $operation): string => $operation === 'edit'
                            ? __('admin.helpers.base_rent_monthly_edit_lock')
                            : __('admin.helpers.base_rent_monthly')),
                    TextInput::make('service_charge_monthly')
                        ->label(__('admin.fields.service_charge_monthly'))
                        ->prefix('EGP')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->dehydrateStateUsing(fn ($state) => $state ?? 0)
                        ->disabled(fn (string $operation): bool => $operation === 'edit')
                        ->dehydrated()
                        ->helperText(fn (string $operation): string => $operation === 'edit'
                            ? __('admin.helpers.service_charge_monthly_edit_lock')
                            : __('admin.helpers.service_charge_monthly')),
                    Toggle::make('has_marketing_levy')
                        ->label(__('admin.fields.has_marketing_levy'))
                        ->default(true)
                        ->live()
                        ->helperText(__('admin.helpers.has_marketing_levy')),
                    TextInput::make('marketing_levy_rate')
                        ->label(__('admin.fields.marketing_levy_rate'))
                        ->numeric()
                        ->suffix('%')
                        ->minValue(0)
                        ->maxValue(100)
                        ->step('0.01')
                        // Blank = use the mall's default rate (shown as the placeholder).
                        ->placeholder(fn () => number_format(app(\App\Services\MarketingLevyService::class)->ratePercent(), 2))
                        ->helperText(__('admin.helpers.marketing_levy_rate'))
                        ->visible(fn (\Filament\Schemas\Components\Utilities\Get $get) => (bool) $get('has_marketing_levy')),
                    TextInput::make('fit_out_months')
                        ->label(__('admin.fields.fit_out_months'))
                        ->numeric()
                        ->integer()
                        ->suffix(__('admin.fields.months'))
                        ->minValue(0)
                        ->maxValue(24)
                        ->default(0)
                        // NOT-NULL column — a blank field must send 0, never null.
                        ->dehydrateStateUsing(fn ($state) => $state ?? 0)
                        ->helperText(__('admin.helpers.fit_out_months')),
                    Select::make('billing_frequency')
                        ->label(__('admin.fields.billing_frequency'))
                        ->options([
                            'monthly' => __('admin.billing_frequency.monthly'),
                            'quarterly' => __('admin.billing_frequency.quarterly'),
                            'semiannual' => __('admin.billing_frequency.semiannual'),
                            'annual' => __('admin.billing_frequency.annual'),
                        ])
                        ->default('monthly')
                        ->selectablePlaceholder(false)
                        ->native(false)
                        // Lock once invoicing has started. Cycles are anchored to the commencement,
                        // so switching cadence mid-term could strand an unaligned month (billed on
                        // neither the old nor the new cadence). Set it before the first invoice.
                        ->disabled(fn (?Lease $record): bool => $record !== null && $record->invoices()->exists())
                        ->helperText(fn (?Lease $record): string => $record !== null && $record->invoices()->exists()
                            ? __('admin.helpers.billing_frequency_locked')
                            : __('admin.helpers.billing_frequency')),
                    TextInput::make('security_deposit')
                        ->label(__('admin.fields.security_deposit'))
                        ->prefix('EGP')
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->dehydrateStateUsing(fn ($state) => $state ?? 0)
                        ->helperText(__('admin.helpers.security_deposit')),
                    TextInput::make('escalation_rate')
                        ->label(__('admin.fields.escalation_rate'))
                        ->numeric()
                        ->suffix('%')
                        ->minValue(0)
                        ->maxValue(100)
                        ->default(7)
                        ->dehydrateStateUsing(fn ($state) => $state ?? 0)
                        ->helperText(__('admin.helpers.escalation_rate')),
                    Select::make('escalation_type')
                        ->label(__('admin.fields.escalation_type'))
                        ->options(fn () => __('admin.enums.escalation_type'))
                        ->default('fixed_percent')
                        ->required() // NOT-NULL column — never dehydrate null
                        ->native(false)
                        ->helperText(__('admin.helpers.escalation_type')),
                    TextInput::make('payment_terms_days')
                        ->label(__('admin.fields.payment_terms_days'))
                        ->numeric()
                        ->minValue(0)
                        ->default(7)
                        ->dehydrateStateUsing(fn ($state) => $state ?? 7)
                        ->suffix(__('admin.fields.days')),
                    Toggle::make('security_deposit_received')
                        ->label(__('admin.fields.security_deposit_received'))
                        ->columnSpanFull(),
                ]),

            Section::make(__('admin.sections.percentage_rent'))
                ->description(__('admin.sections.percentage_rent_description'))
                ->columns(3)
                ->collapsed()
                ->collapsible()
                ->components([
                    Toggle::make('has_percentage_rent')
                        ->live()
                        ->columnSpanFull(),
                    Select::make('percentage_rent_calculation_type')
                        ->label(__('admin.fields.percentage_rent_calculation_type'))
                        ->options(fn () => __('admin.enums.percentage_rent_calculation_type'))
                        ->default('artificial')
                        ->native(false)
                        ->live()
                        ->helperText(__('admin.helpers.percentage_rent_calculation_type'))
                        ->visible(fn ($get) => (bool) $get('has_percentage_rent')),
                    Select::make('percentage_rent_frequency')
                        ->label(__('admin.fields.percentage_rent_frequency'))
                        ->options(fn () => __('admin.enums.percentage_rent_frequency'))
                        ->default('monthly')
                        ->native(false)
                        ->live()
                        ->helperText(__('admin.helpers.percentage_rent_frequency'))
                        ->visible(fn ($get) => (bool) $get('has_percentage_rent')),
                    TextInput::make('percentage_rent_threshold')
                        // Label + helper switch so it is unmistakable that an ANNUAL lease's threshold is
                        // a WHOLE-YEAR figure — the single easiest thing to get wrong (a monthly figure
                        // here under-bills ~12×). Hidden for a natural breakpoint, where the breakpoint is
                        // derived from base rent and this field is unused.
                        ->label(fn ($get) => $get('percentage_rent_frequency') === 'annual'
                            ? __('admin.fields.percentage_rent_threshold_annual')
                            : __('admin.fields.percentage_rent_threshold'))
                        ->prefix('EGP')
                        ->numeric()
                        ->minValue(0)
                        ->live(onBlur: true)
                        ->required(fn ($get) => ($get('percentage_rent_calculation_type') ?? 'artificial') === 'artificial')
                        ->helperText(fn ($get) => $get('percentage_rent_frequency') === 'annual'
                            ? __('admin.helpers.percentage_rent_threshold_annual')
                            : __('admin.helpers.percentage_rent_threshold'))
                        // Soft nudge: an annual breakpoint below one month's base rent is almost certainly
                        // a monthly figure typed by mistake. Guidance, not a hard validation error.
                        ->hintColor('warning')
                        ->hint(fn ($get, $state) => $get('percentage_rent_frequency') === 'annual'
                            && is_numeric($state) && (float) $state > 0
                            && (float) $get('base_rent_monthly') > 0
                            && (float) $state < (float) $get('base_rent_monthly')
                                ? __('admin.helpers.percentage_rent_threshold_annual_warning')
                                : null)
                        ->visible(fn ($get) => (bool) $get('has_percentage_rent')
                            && ($get('percentage_rent_calculation_type') ?? 'artificial') === 'artificial'),
                    TextInput::make('percentage_rent_rate')
                        ->label(__('admin.fields.percentage_rent_rate'))
                        ->suffix('%')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->helperText(__('admin.helpers.percentage_rent_rate'))
                        ->visible(fn ($get) => (bool) $get('has_percentage_rent')),
                ]),

            Section::make(__('admin.sections.notes'))
                ->collapsed()
                ->collapsible()
                ->components([
                    Textarea::make('notes')
                        ->label(__('admin.fields.notes'))
                        ->rows(3)
                        ->columnSpanFull(),
                ]),

            Section::make(__('admin.sections.documents'))
                ->description(__('admin.sections.documents_description'))
                ->collapsible()
                ->components([
                    SpatieMediaLibraryFileUpload::make('documents')
                        ->label(__('admin.fields.documents'))
                        ->collection('documents')
                        ->multiple()
                        ->reorderable()
                        ->appendFiles()
                        ->downloadable()
                        ->openable()
                        ->preserveFilenames()
                        ->acceptedFileTypes(['application/pdf', 'image/*', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'])
                        ->maxSize(10240)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
