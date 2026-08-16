<?php

namespace App\Filament\Admin\Resources\Leases\Schemas;

use App\Models\Lease;
use App\Models\Unit;
use App\Models\UnitOwnership;
use App\Services\MarketingLevyService;
use App\Settings\AccountingSettings;
use App\Settings\BillingSettings;
use App\Support\FormTab;
use App\Support\LeaseTerm;
use App\Support\PropertySettings;
use App\Support\TenantScope;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class LeaseForm
{
    public static function configure(Schema $schema): Schema
    {
        // Thirty fields across six concerns is a scroll, not a form. Each concern is now a tab —
        // one coherent group of settings per screen (operator decision 2026-08-08). Tabs are built
        // through App\Support\FormTab so each one carries a badge counting the validation errors
        // inside it: Filament v4 ships no error indicator on Tabs, and without one a required field
        // left blank on a tab you are not looking at refuses the form with nothing visible to fix.
        return $schema->columns(1)->components([
            Tabs::make('lease')
                ->columnSpanFull()
                ->persistTabInQueryString()
                ->tabs([
                    FormTab::make('admin.sections.lease_details', [
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
                                    // Eager-loaded so the encumbrance warning in the label below costs
                                    // no query per option (OP-03).
                                    $query->with('encumbrances')->when(
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
                            // It WARNS, it does not block (OP-03's acceptance): a landlord may
                            // legitimately let encumbered space — the option holder simply has to be
                            // dealt with first — and a hard block would send the operator round the
                            // system rather than to the conversation.
                            ->getOptionLabelFromRecordUsing(fn (Unit $unit) => sprintf(
                                '%s · %s%s',
                                $unit->code,
                                __("admin.statuses.unit.{$unit->status}"),
                                $unit->isEncumbered() ? ' · ⚠ '.__('admin.lease_options.encumbered') : '',
                            ))
                            ->searchable()
                            ->preload()
                            ->required()
                            // The master unit IS the lease's identity (`leases.unit_id`), so it is
                            // chosen once. Swapping it on an existing lease is a RELOCATION — a
                            // different commercial act with its own event type — and doing it here
                            // would move the tenancy silently while every invoice already raised
                            // still names the old shop. ADDITIONAL units stay editable, because
                            // expanding and contracting the premises is ordinary. Yardi locks the
                            // unit on an executed lease for the same reason.
                            ->disabled(fn (string $operation): bool => $operation === 'edit')
                            ->helperText(fn (Get $get, string $operation): ?string => match (true) {
                                $operation === 'edit' => __('admin.helpers.master_unit_locked'),
                                (bool) $get('show_occupied_units') => __('admin.helpers.unit_showing_all'),
                                default => __('admin.helpers.only_available_units'),
                            })
                            // ── The double-booking guard ─────────────────────────────────────────
                            // Restored to `unit_id` 2026-08-16. It had been moved onto
                            // `unit_ownership_id`, where `$value` is an OWNERSHIP id rather than a
                            // unit id, so `! $value` returned early on every ordinary lease and the
                            // rule could never fire. What kept it looking present is the option
                            // query, which hides occupied units — but `show_occupied_units` widens
                            // exactly that query, and behind it there was nothing left: the standard
                            // Create form would mint a SECOND active lease on a let unit. Nor does
                            // `CreateLease` run `LeaseCreationService`, so the unit row-lock never
                            // saw it either. Reproduced before the fix; pinned by
                            // `LeaseFormTightnessTest`.
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
                                    $unit = Unit::find($unitId);
                                    if ($unit && $unit->isActivelyLeased($record?->id)) {
                                        $fail(__('admin.validation.unit_has_active_lease'));
                                    }
                                },
                            ]),

                        // Yardi's lessee-under-owner record: when a unit has been SOLD and the owner
                        // lets it himself, the mall still keeps the tenancy on file — for access,
                        // violations, SLA and fit-out — and the OWNER stays liable for the
                        // assessment. Shown only when the chosen unit actually has an owner, so it
                        // is invisible on the ordinary lease, which is nearly all of them.
                        Select::make('unit_ownership_id')
                            ->label(__('admin.fields.unit_ownership'))
                            ->options(fn (Get $get): array => UnitOwnership::query()
                                ->where('unit_id', $get('unit_id'))
                                ->with('owner')
                                ->get()
                                ->mapWithKeys(fn (UnitOwnership $o) => [
                                    $o->id => $o->owner?->name.' · '.$o->reference,
                                ])
                                ->all())
                            ->visible(fn (Get $get): bool => $get('unit_id') !== null
                                && UnitOwnership::where('unit_id', $get('unit_id'))->exists())
                            // Locked with the unit it hangs off: who the lease sits under decides who
                            // is assessed, and re-pointing it on a live tenancy would re-address the
                            // liability behind invoices already issued.
                            ->disabled(fn (string $operation): bool => $operation === 'edit')
                            ->helperText(fn (string $operation): string => $operation === 'edit'
                                ? __('admin.helpers.locked_after_creation')
                                : __('admin.helpers.lease_under_ownership')),
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
                                    // Same encumbrance warning as the master picker (OP-03): an
                                    // expansion right is most often exercised over an ADJACENT unit,
                                    // which is exactly what gets added here.
                                    ->with('encumbrances')
                                    ->when($assetIds, fn ($q, $aids) => $q->whereIn('asset_id', $aids))
                                    ->where(function ($q) use ($record) {
                                        // WIDER than the master picker above, which allows a unit under
                                        // maintenance. The asymmetry is deliberate and pinned by
                                        // `MultiUnitLeaseFormScenarioTest`: additional units are added
                                        // to a lease that already exists, where offering space that is
                                        // out of service is far more likely a mis-click than a
                                        // negotiated expansion. Taking refurbished space as the MASTER
                                        // premises is an ordinary new deal — a fit-out — which is why
                                        // that picker is narrower here and wider there.
                                        $q->whereNotIn('status', ['occupied', 'reserved', 'maintenance']);
                                        if ($record) {
                                            $q->orWhereIn('id', $record->units()->pluck('units.id'));
                                        }
                                    })
                                    ->when($master, fn ($q, $m) => $q->where('id', '!=', $m))
                                    ->orderBy('code')
                                    ->get()
                                    ->mapWithKeys(fn (Unit $u) => [$u->id => sprintf(
                                        '%s · %s%s',
                                        $u->code,
                                        __("admin.statuses.unit.{$u->status}"),
                                        $u->isEncumbered($record?->id) ? ' · ⚠ '.__('admin.lease_options.encumbered') : '',
                                    )]);
                            })
                            // Live so a rate-priced lease re-derives its rent the moment the let area
                            // changes (LS-04) — the operator sees the money move as they pick space.
                            ->live(),
                        Select::make('tenant_id')
                            ->label(__('admin.resources.tenant.singular'))
                            // Scope the picker to tenants in the user's visible properties
                            // (+ unaffiliated tenants, which belong to no property yet).
                            ->relationship('tenant', 'name', modifyQueryUsing: function ($query) {
                                $ids = TenantScope::visibleAssetIds();
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
                            // The counterparty is the lease. Re-pointing it would hand one tenant's
                            // billing history, deposit and AR to another under the same contract
                            // reference — an ASSIGNMENT is a new lease, not an edit. Yardi refuses
                            // this on an executed lease for exactly that reason.
                            ->disabled(fn (string $operation): bool => $operation === 'edit')
                            ->helperText(fn (string $operation): ?string => $operation === 'edit'
                                ? __('admin.helpers.locked_after_creation')
                                : null)
                            ->createOptionForm([
                                TextInput::make('name')->label(__('admin.fields.brand_name'))->required(),
                                TextInput::make('phone')->label(__('admin.fields.phone'))->tel(),
                                TextInput::make('email')->label(__('admin.fields.email'))->email(),
                            ]),
                        // `renewed` and `terminated` are OUTCOMES of a service, not states to type.
                        // Selecting them here wrote the status and skipped everything the act means:
                        // terminating deactivates the charge schedule, credits unearned billing,
                        // cancels open invoices and settles the deposit; renewing writes the next
                        // lease and links the chain. A lease typed straight into `terminated` stops
                        // billing (nothing is billable outside `active`) while its schedule, its open
                        // AR and its deposit all stay exactly as they were — which reads as done and
                        // is not. Reached through the Terminate / Renew actions instead. A record
                        // already in one of them is still rendered, so the select is never blank.
                        Select::make('status')
                            ->label(__('admin.tables.common.status'))
                            ->options(fn (?Lease $record): array => collect(__('admin.statuses.lease'))
                                ->reject(fn ($label, $value) => in_array($value, ['renewed', 'terminated'], true)
                                    && $record?->status !== $value)
                                ->all())
                            ->default('draft')
                            ->required()
                            ->native(false),
                        Toggle::make('show_occupied_units')
                            ->label(__('admin.fields.show_occupied_units'))
                            ->helperText(__('admin.helpers.show_occupied_units'))
                            ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.show_occupied_units'))
                            ->live()
                            ->dehydrated(false)
                            ->default(false)
                            ->columnSpan(2),
                    ])->columns(3),

                    FormTab::make('admin.sections.term', [
                        // ── Commencement ⇄ term ⇄ expiry ──────────────────────────────────────────
                        // These were three INDEPENDENT inputs until 2026-08-12, so a lease could be
                        // saved as "36 months" spanning twelve — and `term_months` is not decoration:
                        // it is logged on the lease, copied by renewal, and read by the option-exercise
                        // service, so the disagreement propagated into the next contract.
                        //
                        // Now they derive both ways. Changing the commencement or the term recomputes
                        // the expiry; typing an expiry recomputes the TERM rather than contradicting
                        // it. Every field stays editable — the derived one is a starting point, which
                        // is how Yardi and MRI behave, and it is why the back-derivation matters: an
                        // operator who types a bespoke end date must not be left with a term that
                        // silently disagrees with it.
                        DatePicker::make('commencement_date')
                            ->label(__('admin.fields.commencement_date'))
                            ->required()
                            ->native(false)
                            // Free while the deal is still being negotiated; locked the moment the
                            // lease has been invoiced. The commencement anchors the first billable
                            // month, the billing cycle and every charge row's start date, so moving
                            // it after billing has begun re-dates a schedule that issued documents
                            // were already raised from.
                            ->disabled(fn (?Lease $record): bool => self::isInvoiced($record))
                            ->helperText(fn (?Lease $record): ?string => self::isInvoiced($record)
                                ? __('admin.helpers.locked_after_invoicing')
                                : null)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::deriveExpiry($get, $set)),
                        TextInput::make('term_months')
                            ->label(__('admin.fields.term_months'))
                            ->numeric()
                            ->default(fn () => max(1, (int) app(AccountingSettings::class)->default_lease_term_months))
                            ->required()
                            ->minValue(1)
                            ->maxValue(120)
                            ->helperText(__('admin.helpers.term_months'))
                            ->suffix(__('admin.fields.months'))
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::deriveExpiry($get, $set)),
                        DatePicker::make('expiry_date')
                            ->label(__('admin.fields.expiry_date'))
                            ->required()
                            ->after('commencement_date')
                            ->native(false)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::deriveTerm($get, $set))
                            ->helperText(__('admin.helpers.expiry_date_derived')),
                    ])->columns(3),

                    FormTab::make('admin.sections.financial_terms', [
                        // Rent fields are read-only on Edit. Operators change them
                        // through the "Change rent" record action so the matching
                        // Charge.amount stays in sync (audit M04 F-20 / D-13). On
                        // Create the LeaseObserver seeds the charges from these
                        // values, so they remain editable here.
                        // ── How the rent is priced (LS-04) ────────────────────────────────────────
                        // Commercial rent is negotiated per m² per year almost everywhere, and until
                        // now `units.area_sqm` priced nothing. Choosing `rate` makes the monthly figure
                        // DERIVED — which is what lets an expansion re-price the lease by itself, and
                        // lets two deals be compared on the only basis that makes them comparable.
                        Radio::make('rent_pricing_basis')
                            ->label(__('admin.fields.rent_pricing_basis'))
                            ->options(fn () => __('admin.enums.rent_pricing_basis'))
                            ->default(Lease::RENT_FLAT)
                            ->inline()
                            ->inlineLabel(false)
                            ->live()
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::deriveRentInto($get, $set))
                            ->disabled(fn (string $operation): bool => $operation === 'edit')
                            ->dehydrated()
                            ->helperText(__('admin.helpers.rent_pricing_basis'))
                            ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.rent_pricing_basis')),
                        TextInput::make('base_rent_rate_per_sqm_year')
                            ->label(__('admin.fields.base_rent_rate_per_sqm_year'))
                            ->prefix('EGP')
                            ->suffix('/m²/'.__('admin.fields.per_year_suffix'))
                            ->numeric()
                            ->minValue(0)
                            ->live(onBlur: true)
                            ->afterStateUpdated(fn (Get $get, Set $set) => self::deriveRentInto($get, $set))
                            ->required(fn (Get $get): bool => $get('rent_pricing_basis') === Lease::RENT_RATE)
                            ->visible(fn (Get $get): bool => $get('rent_pricing_basis') === Lease::RENT_RATE)
                            ->disabled(fn (string $operation): bool => $operation === 'edit')
                            ->dehydrated()
                            ->helperText(fn (Get $get): string => __('admin.helpers.base_rent_rate_per_sqm_year', [
                                'area' => number_format(self::formArea($get), 2),
                            ])),
                        TextInput::make('base_rent_monthly')
                            ->label(__('admin.fields.base_rent_monthly'))
                            ->prefix('EGP')
                            ->numeric()
                            ->required(fn (Get $get): bool => $get('rent_pricing_basis') !== Lease::RENT_RATE)
                            ->minValue(0)
                            // Read-only on Edit (rent changes go through the "Change rent" action so the
                            // schedule stays in step), and read-only on a rate-priced lease because it
                            // is derived — `Lease::deriveBaseRentFromRate()` is the authority either way.
                            ->disabled(fn (string $operation, Get $get): bool => $operation === 'edit'
                                || $get('rent_pricing_basis') === Lease::RENT_RATE)
                            ->dehydrated()
                            ->dehydrateStateUsing(fn ($state) => $state ?? 0)
                            ->helperText(fn (string $operation, Get $get): string => match (true) {
                                $operation === 'edit' => __('admin.helpers.base_rent_monthly_edit_lock'),
                                $get('rent_pricing_basis') === Lease::RENT_RATE => __('admin.helpers.base_rent_monthly_derived'),
                                default => __('admin.helpers.base_rent_monthly'),
                            })
                            ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.base_rent_monthly_edit_lock')),
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
                                : __('admin.helpers.service_charge_monthly'))
                            ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.service_charge_monthly_edit_lock')),
                        Toggle::make('has_marketing_levy')
                            ->label(__('admin.fields.has_marketing_levy'))
                            ->default(true)
                            ->live()
                            ->helperText(__('admin.helpers.has_marketing_levy'))
                            ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.has_marketing_levy')),
                        TextInput::make('marketing_levy_rate')
                            ->label(__('admin.fields.marketing_levy_rate'))
                            ->numeric()
                            ->suffix('%')
                            ->minValue(0)
                            ->maxValue(100)
                            ->step('0.01')
                            // Blank = use the mall's default rate (shown as the placeholder).
                            ->placeholder(fn () => number_format(app(MarketingLevyService::class)->ratePercent(), 2))
                            ->helperText(__('admin.helpers.marketing_levy_rate'))
                            ->visible(fn (Get $get) => (bool) $get('has_marketing_levy')),
                        // Replaced the old `fit_out_months` count: a lease says "rent commences 1
                        // April", not "three months of fit-out", and a whole-month integer could not
                        // express a mid-month start at all.
                        DatePicker::make('possession_date')
                            ->label(__('admin.fields.possession_date'))
                            ->native(false)
                            ->helperText(__('admin.helpers.possession_date'))
                            ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.possession_date')),
                        DatePicker::make('rent_commencement_date')
                            ->label(__('admin.fields.rent_commencement_date'))
                            ->native(false)
                            ->live()
                            // Earlier than commencement is not a grace period, and the model guards
                            // against it pulling the first billable month backwards; refused here too
                            // so the operator gets an inline error rather than a silent no-op.
                            ->afterOrEqual('commencement_date')
                            // Locked once invoiced, with `fit_out_scope`: together they decided what
                            // was abated on invoices already issued, and moving them afterwards
                            // makes the system disagree with its own documents.
                            ->disabled(fn (?Lease $record): bool => self::isInvoiced($record))
                            ->helperText(fn (?Lease $record): string => self::isInvoiced($record)
                                ? __('admin.helpers.locked_after_invoicing')
                                : __('admin.helpers.rent_commencement_date'))
                            ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.rent_commencement_date')),
                        Select::make('fit_out_scope')
                            ->label(__('admin.fields.fit_out_scope'))
                            ->options([
                                Lease::FIT_OUT_RENT_ONLY => __('admin.fit_out_scope.rent_only'),
                                Lease::FIT_OUT_GROSS => __('admin.fit_out_scope.gross'),
                            ])
                            ->native(false)
                            // The industry standard is net abatement: rent free, service charge still
                            // payable. Existing leases keep whatever they were billed under (the column
                            // default is gross); this is the default for NEW deals only.
                            ->default(Lease::FIT_OUT_RENT_ONLY)
                            ->visible(fn ($get) => filled($get('rent_commencement_date')))
                            ->disabled(fn (?Lease $record): bool => self::isInvoiced($record))
                            ->helperText(fn (?Lease $record): string => self::isInvoiced($record)
                                ? __('admin.helpers.locked_after_invoicing')
                                : __('admin.helpers.fit_out_scope'))
                            ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.fit_out_scope')),
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
                            ->disabled(fn (?Lease $record): bool => self::isInvoiced($record))
                            // The helper text reports the STATE (locked or not), which changes; the
                            // hint icon explains the FIELD, which does not.
                            ->helperText(fn (?Lease $record): string => self::isInvoiced($record)
                                ? __('admin.helpers.billing_frequency_locked')
                                : __('admin.helpers.billing_frequency'))
                            ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.billing_frequency')),
                        TextInput::make('security_deposit')
                            ->label(__('admin.fields.security_deposit'))
                            ->prefix('EGP')
                            ->numeric()
                            ->minValue(0)
                            ->default(0)
                            ->dehydrateStateUsing(fn ($state) => $state ?? 0)
                            ->helperText(__('admin.helpers.security_deposit')),
                        // ── Escalation: the TYPE is asked first, and it is the only field always on
                        // screen ─────────────────────────────────────────────────────────────────
                        // Every other field here belongs to exactly one type, so each appears only
                        // once that type is chosen, and `none` shows nothing at all. Before this the
                        // visibility was written as "not fixed_amount", which put a rate box and a
                        // collar on a lease that had just declared it never escalates — three inputs
                        // that could be filled in and would then be read by nothing. What the
                        // operator can see and what the contract states now match.
                        //
                        // The stale-value half of this is enforced in `Lease::saving`, not here: a
                        // field Filament has hidden is not dehydrated, so on an EDIT the old value
                        // simply survives in the column, invisible. The model clears the terms when
                        // no clause is configured, which also covers the importer and the API.
                        Select::make('escalation_type')
                            ->label(__('admin.fields.escalation_type'))
                            ->options(fn () => __('admin.enums.escalation_type'))
                            ->default('fixed_percent')
                            ->required() // NOT-NULL column — never dehydrate null
                            ->native(false)
                            ->live()
                            ->helperText(__('admin.helpers.escalation_type')),
                        TextInput::make('escalation_rate')
                            ->label(__('admin.fields.escalation_rate'))
                            ->numeric()
                            ->suffix('%')
                            ->minValue(0)
                            ->maxValue(100)
                            ->default(7)
                            ->dehydrateStateUsing(fn ($state) => $state ?? 0)
                            // The two rate-stated clauses. `cpi` keeps it because the rate is what
                            // arms the anniversary and what the collar is measured against.
                            ->visible(fn (Get $get) => in_array($get('escalation_type'), ['fixed_percent', 'cpi'], true))
                            ->required(fn (Get $get) => $get('escalation_type') === 'fixed_percent')
                            ->helperText(__('admin.helpers.escalation_rate')),
                        TextInput::make('escalation_amount')
                            ->label(__('admin.fields.escalation_amount'))
                            ->prefix('EGP')
                            ->numeric()
                            ->minValue(0)
                            ->visible(fn (Get $get) => $get('escalation_type') === 'fixed_amount')
                            ->required(fn (Get $get) => $get('escalation_type') === 'fixed_amount')
                            ->helperText(__('admin.helpers.escalation_amount'))
                            ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.escalation_amount')),
                        // The collar. Left blank on most leases — a bound of zero would read as "never
                        // increase", which is why these are nullable rather than defaulted. Shown only
                        // for the rate-stated types: a bound written in percent has no meaning against
                        // a step written in pounds, which is why `RentEscalationService::collar()`
                        // never applies it to `fixed_amount`.
                        TextInput::make('escalation_floor_rate')
                            ->label(__('admin.fields.escalation_floor_rate'))
                            ->numeric()
                            ->suffix('%')
                            ->minValue(0)
                            ->maxValue(100)
                            ->visible(fn (Get $get) => in_array($get('escalation_type'), ['fixed_percent', 'cpi'], true))
                            ->helperText(__('admin.helpers.escalation_floor_rate'))
                            ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.escalation_floor_rate')),
                        TextInput::make('escalation_ceiling_rate')
                            ->label(__('admin.fields.escalation_ceiling_rate'))
                            ->numeric()
                            ->suffix('%')
                            ->minValue(0)
                            ->maxValue(100)
                            // Caught here for an inline error, and again in the model so an import or an
                            // API write cannot get round it.
                            ->gte('escalation_floor_rate')
                            ->visible(fn (Get $get) => in_array($get('escalation_type'), ['fixed_percent', 'cpi'], true))
                            ->helperText(__('admin.helpers.escalation_ceiling_rate'))
                            ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.escalation_ceiling_rate')),
                        TextInput::make('payment_terms_days')
                            ->label(__('admin.fields.payment_terms_days'))
                            ->numeric()
                            ->minValue(0)
                            // The property's convention, falling back to the portfolio's — NOT a
                            // hard-coded 7. `payment_terms_days` is NOT NULL with a database default,
                            // so the `?? setting` that used to sit at eight billing call sites could
                            // never fire and the configured default reached nothing. ORIGINATION is
                            // where it belongs: a new lease starts from its mall's convention and then
                            // carries its own number, so changing the setting later cannot move the due
                            // date on receivables already raised. This is what Yardi does too.
                            ->default(fn () => PropertySettings::paymentTermsDays(TenantScope::currentAssetId()))
                            ->dehydrateStateUsing(fn ($state) => $state ?? PropertySettings::paymentTermsDays(TenantScope::currentAssetId()))
                            ->suffix(__('admin.fields.days')),
                        Toggle::make('security_deposit_received')
                            ->label(__('admin.fields.security_deposit_received'))
                            ->columnSpanFull(),
                        // Per-lease late-fee terms (MF-08). All three are OPTIONAL: blank means the
                        // portfolio default from Settings → Billing, which is what almost every lease
                        // uses. Only the negotiated ones get filled in, and the placeholder shows what
                        // they would otherwise inherit so the operator is never guessing.
                        TextInput::make('late_fee_percent')
                            ->label(__('admin.fields.late_fee_percent'))
                            ->helperText(__('admin.helpers.late_fee_override'))
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%')
                            ->placeholder(fn () => (string) app(BillingSettings::class)->late_fee_percent),
                        TextInput::make('late_fee_grace_days')
                            ->label(__('admin.fields.late_fee_grace_days'))
                            ->helperText(__('admin.helpers.late_fee_override'))
                            ->numeric()
                            ->minValue(0)
                            ->suffix(__('admin.fields.days'))
                            ->placeholder(fn () => (string) app(BillingSettings::class)->late_fee_grace_days),
                        TextInput::make('late_fee_minimum')
                            ->label(__('admin.fields.late_fee_minimum'))
                            ->helperText(__('admin.helpers.late_fee_override'))
                            ->numeric()
                            ->minValue(0)
                            ->prefix('EGP')
                            ->placeholder(fn () => (string) app(BillingSettings::class)->late_fee_minimum),
                    ])->columns(3),

                    FormTab::make('admin.sections.percentage_rent', [
                        Toggle::make('has_percentage_rent')
                            ->label(__('admin.sections.percentage_rent'))
                            ->live()
                            ->columnSpanFull(),
                        Select::make('percentage_rent_calculation_type')
                            ->label(__('admin.fields.percentage_rent_calculation_type'))
                            ->options(fn () => __('admin.enums.percentage_rent_calculation_type'))
                            ->default('artificial')
                            ->native(false)
                            ->live()
                            ->helperText(__('admin.helpers.percentage_rent_calculation_type'))
                            ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.percentage_rent_calculation_type'))
                            // A natural breakpoint IS the base rent: the tenant pays a percentage of
                            // sales less the rent already paid, so with no base rent the breakpoint
                            // is zero and the clause silently becomes "a percentage of EVERY pound
                            // of sales from the first one" — the most expensive possible reading,
                            // and one no operator intends. Refused rather than warned, because the
                            // resulting overage looks perfectly ordinary on the invoice.
                            ->rules([
                                fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get) {
                                    if ($value === 'natural_breakpoint' && (float) $get('base_rent_monthly') <= 0) {
                                        $fail(__('admin.validation.natural_breakpoint_needs_base_rent'));
                                    }
                                },
                            ])
                            ->visible(fn ($get) => (bool) $get('has_percentage_rent')),
                        // ANNUAL is the industry standard: percentage rent accrues on CUMULATIVE
                        // year-to-date sales against an annual breakpoint, settled up over the year
                        // (Yardi, and the basis PR-01 was built for). A monthly basis charges overage
                        // in a strong month that a weak one should have absorbed, so a seasonal tenant
                        // pays more across the year than their clause says.
                        //
                        // The COLUMN default stays `monthly` — every lease that exists keeps the basis
                        // it was billed on, because which one applies is a fact in each contract and
                        // not something to switch on a guess. Only NEW leases get the standard, the
                        // same split-default as `fit_out_scope`.
                        Select::make('percentage_rent_frequency')
                            ->label(__('admin.fields.percentage_rent_frequency'))
                            ->options(fn () => __('admin.enums.percentage_rent_frequency'))
                            ->default('annual')
                            ->native(false)
                            ->live()
                            ->helperText(__('admin.helpers.percentage_rent_frequency'))
                            ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.percentage_rent_frequency'))
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
                        Select::make('percentage_rent_deductible_types')
                            ->label(__('admin.fields.percentage_rent_deductible_types'))
                            ->multiple()
                            ->options(fn () => __('admin.enums.invoice_item_type'))
                            ->native(false)
                            // "Percentage rent payable to the extent it exceeds CAM and tax paid in the
                            // same period" — a common retail clause Atriom could not express at all.
                            ->helperText(__('admin.helpers.percentage_rent_deductible_types'))
                            ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.percentage_rent_deductible_types'))
                            ->visible(fn ($get) => (bool) $get('has_percentage_rent')),
                        TextInput::make('percentage_rent_rate')
                            ->label(__('admin.fields.percentage_rent_rate'))
                            ->suffix('%')
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            // The one figure the clause cannot do without. Left optional, a lease
                            // saved with the toggle on and this blank reads as configured on every
                            // screen and calculates an overage of 0.00 for the whole term — the
                            // failure this module keeps meeting: silent, not loud.
                            ->required(fn ($get) => (bool) $get('has_percentage_rent'))
                            ->helperText(__('admin.helpers.percentage_rent_rate'))
                            ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.percentage_rent_rate'))
                            ->visible(fn ($get) => (bool) $get('has_percentage_rent')),
                    ])->columns(3),

                    FormTab::make('admin.sections.documents', [
                        Textarea::make('notes')
                            ->label(__('admin.fields.notes'))
                            ->rows(3)
                            ->columnSpanFull(),
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
                    ])->columns(1),
                ]),
        ]);
    }

    /**
     * Has this lease already produced a document?
     *
     * The dividing line for the term fields. While a lease is only an agreement its dates are still
     * negotiable and an operator must be able to correct a typo; the moment an invoice exists those
     * same dates are what that invoice was derived from, and moving them makes the system disagree
     * with paper the tenant is holding. Null (a create) is never invoiced.
     *
     * Deliberately unmemoised: a static cache on a form class outlives the render in a long-lived
     * worker, and would answer "not invoiced" for a lease that had just been billed.
     */
    private static function isInvoiced(?Lease $record): bool
    {
        return $record !== null && $record->invoices()->exists();
    }

    /**
     * The area the form is currently letting — master unit plus any additional ones (LS-04).
     *
     * Read from the FORM rather than the saved lease so the derived rent tracks the space the
     * operator is picking right now, before anything is written.
     */
    private static function formArea(Get $get): float
    {
        $ids = array_filter(array_merge(
            [$get('unit_id')],
            (array) ($get('additional_unit_ids') ?? []),
        ));

        if ($ids === []) {
            return 0.0;
        }

        // Clamped, like every other query keyed on a client-supplied unit id here: this runs on
        // `afterStateUpdated`, BEFORE validation, so a posted id from another property would
        // otherwise have its area readable through the rent the form derives from it.
        return (float) Unit::whereIn('id', $ids)
            ->when(TenantScope::visibleAssetIds(), fn ($q, $assetIds) => $q->whereIn('asset_id', $assetIds))
            ->sum('area_sqm');
    }

    /** Show the monthly rent a per-m² rate implies, live, as the deal is typed. */
    /**
     * Recompute the expiry from the commencement and the term.
     *
     * Silent when either input is missing or half-typed — a live field mid-edit is not an error,
     * and blanking a required date the operator is about to fill would be worse than doing nothing.
     */
    private static function deriveExpiry(Get $get, Set $set): void
    {
        $expiry = LeaseTerm::expiryFrom($get('commencement_date'), $get('term_months'));

        if ($expiry !== null) {
            $set('expiry_date', $expiry);
        }
    }

    /**
     * Recompute the TERM from a hand-typed expiry — the direction that stops the pair disagreeing.
     *
     * `monthsBetween()` returns null unless the range is a whole number of months, and null leaves
     * the term untouched on purpose: an expiry aligned to a financial year or another tenant's
     * fit-out is a real negotiated date, and rounding it to a tidy term would restate the contract.
     * The operator then sees a term and an expiry that genuinely differ, which is the truth.
     */
    private static function deriveTerm(Get $get, Set $set): void
    {
        $months = LeaseTerm::monthsBetween($get('commencement_date'), $get('expiry_date'));

        if ($months !== null) {
            $set('term_months', $months);
        }
    }

    private static function deriveRentInto(Get $get, Set $set): void
    {
        if ($get('rent_pricing_basis') !== Lease::RENT_RATE) {
            return;
        }

        $area = self::formArea($get);
        $rate = (float) $get('base_rent_rate_per_sqm_year');

        if ($area > 0 && $rate > 0) {
            $set('base_rent_monthly', round($rate * $area / 12, 2));
        }
    }
}
