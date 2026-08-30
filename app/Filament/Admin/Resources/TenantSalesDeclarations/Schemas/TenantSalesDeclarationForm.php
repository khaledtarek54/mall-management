<?php

namespace App\Filament\Admin\Resources\TenantSalesDeclarations\Schemas;

use App\Models\Lease;
use App\Models\TenantSalesDeclaration;
use App\Services\PercentageRentCalculationService;
use App\Support\Filament\EntitySelect;
use App\Support\SalesExclusions;
use App\Support\Search\OptionDisplay;
use App\Support\Search\RecordOption;
use App\Support\TenantScope;
use App\Support\Vat;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\Rules\Unique;

class TenantSalesDeclarationForm
{
    /**
     * Recompute the two DERIVED figures on screen, live.
     *
     * Both were computed only on the way to the database — `declared_sales` by the model's `saving`
     * hook, `calculated_percentage_rent` by the lock — so the operator keyed a gross figure, some
     * deductions, and then saved without ever seeing either number. The helper text underneath even
     * spelled the derivation out ("1,368,000.00 gross less 218,000.00 deducted") while the field
     * beside it stayed empty, which is the shape that gets read as broken.
     *
     * It matters most for the one that costs money: percentage rent is charged on the sales ABOVE a
     * breakpoint, so a 12% error in the gross becomes a ~50% error in the charge. Seeing the charge
     * before committing to it is the only cheap check an operator has.
     *
     * The model stays the authority — this mirrors it, exactly as `declared_sales` was already
     * disabled-and-derived rather than editable. A transient declaration is used so the service
     * answers on the SAME code path a lock will later take, rather than a second copy of the
     * arithmetic that could drift from it.
     */
    private static function refreshDerived(Get $get, Set $set, bool $locked = false): void
    {
        // BOTH WAYS OF STATING THE FIGURE. A gross plus its deductions DERIVES the net; a net
        // typed straight in IS the net, which is what the form offers when no gross is given and
        // what the older declarations carry. The preview has to answer on either, or it goes blank
        // on exactly the simpler path — reported from the panel, on a declaration keyed that way.
        $gross = $get('gross_sales');
        $typed = $get('declared_sales');

        if (blank($gross) && blank($typed)) {
            return;
        }

        // A LOCKED declaration shows the figure it was BILLED at, never a fresh preview. The stored
        // overage is frozen at lock and an invoice was raised for it; recomputing on screen would
        // show a number that disagrees with the document whenever anything the calculation reads
        // has moved since — a tax rate, the lease's terms, a sibling month in an annual year.
        //
        // Read from the RECORD, not from `$get('status')`: a schema hydrates in declaration order
        // and `status` is declared after the field this runs for, so on an edit page the form state
        // has no status yet and the guard would never fire.
        if ($locked) {
            return;
        }

        if (filled($gross)) {
            $net = round(max(0.0, (float) $gross - SalesExclusions::total((array) ($get('sales_exclusions') ?? []))), 2);
            $set('declared_sales', $net);
        } else {
            $net = round(max(0.0, (float) $typed), 2);
        }

        $lease = filled($get('lease_id')) ? Lease::find($get('lease_id')) : null;

        if ($lease === null || ! $lease->has_percentage_rent || blank($get('period_start'))) {
            $set('calculated_percentage_rent', null);

            return;
        }

        // Unsaved on purpose: this is a preview of what a lock WOULD charge, and it must not
        // write anything. An annual lease reads its prior locked months through the relation,
        // so the figure shown is the marginal one that month would actually bill.
        $preview = new TenantSalesDeclaration([
            'lease_id' => $lease->getKey(),
            'period_start' => $get('period_start'),
            'period_end' => $get('period_end') ?: $get('period_start'),
            'declared_sales' => $net,
        ]);
        $preview->setRelation('lease', $lease);

        $set('calculated_percentage_rent', app(PercentageRentCalculationService::class)->calculate($preview));
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.sections.tenant_sales'))
                ->columns(3)
                ->components([
                    EntitySelect::make('lease_id')
                        ->label(__('admin.resources.lease.singular'))
                        ->entity(Lease::class)
                        ->modifyOptionsQuery(fn ($query) => $query->where('status', 'active'))
                        // OPENS ON the leases that carry a percentage-rent clause, because those are
                        // the ones a declaration usually belongs to — and still REACHES the rest by
                        // typing, which is the difference between a suggestion and a filter.
                        //
                        // Not a hard filter, deliberately: a mall collects turnover from tenants who
                        // owe no percentage rent (the Sales analytics screen is what it is for, and
                        // this database already holds one such declaration). Filtering them out
                        // would refuse a legitimate record — and worse, Filament resolves a Select's
                        // value by LABELLING it through the same query, so an existing declaration
                        // on a non-percentage lease would fail to open for editing at all.
                        ->suggest(fn ($query) => $query->where('has_percentage_rent', true))
                        // What the person keying the numbers needs to see WITHOUT leaving the form:
                        // the clause they are about to be measured against. A rate and a breakpoint
                        // are the two figures that decide the charge, and a natural breakpoint is
                        // DERIVED from the rent rather than agreed — so it is computed here rather
                        // than left for the operator to work out.
                        ->withRelations(['tenant', 'unit'])
                        ->decorateOption(function (RecordOption $option, Lease $lease): RecordOption {
                            if (! $lease->has_percentage_rent) {
                                return $option->withBadge(__('admin.percentage_rent.no_clause'), 'gray');
                            }

                            $rate = (float) $lease->percentage_rent_rate;

                            $breakpoint = ($lease->percentage_rent_calculation_type ?? 'artificial') === 'natural_breakpoint'
                                ? ($rate > 0 ? (float) $lease->base_rent_monthly * 100 / $rate : 0.0)
                                : (float) ($lease->percentage_rent_threshold ?? 0);

                            return $option->withBadge(__('admin.percentage_rent.clause_badge', [
                                'rate' => rtrim(rtrim(number_format($rate, 2), '0'), '.'),
                                'breakpoint' => number_format($breakpoint, 0),
                                'basis' => __('admin.statuses.percentage_rent_frequency_short.'.($lease->percentage_rent_frequency ?? 'monthly')),
                            ]), 'warning');
                        })
                        // Options list only ACTIVE leases; a declaration on a lease later expired or
                        // terminated would render the raw id on edit — resolve any stored lease.
                        // After `->entity()`, which installs its own narrowed resolver.
                        ->getOptionLabelUsing(fn ($value): ?string => ($l = Lease::with(['tenant', 'unit'])->find($value))
                            ? OptionDisplay::for($l)->toHtml()
                            : null)
                        ->required(),
                    DatePicker::make('period_start')
                        ->label(__('admin.fields.period_start'))
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, Set $set) => self::refreshDerived($get, $set, locked: $get('status') === 'locked'))
                        ->required()
                        ->displayFormat('d/m/Y')
                        ->default(now()->startOfMonth()->subMonth())
                        // Clamped: `lease_id` is client-supplied, and assertLeaseAssetInScope()
                        // runs in a mutate hook — i.e. AFTER the validation pass — so keyed
                        // raw this rule leaked whether a lease in an invisible property had
                        // already declared a given month (TenantScope::clampLeaseId).
                        ->unique(
                            table: TenantSalesDeclaration::class,
                            ignoreRecord: true,
                            modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('lease_id', TenantScope::clampLeaseId($get('lease_id'))),
                        )
                        ->validationMessages([
                            'unique' => __('api.sales_declaration_duplicate'),
                        ]),
                    DatePicker::make('period_end')
                        ->label(__('admin.fields.period_end'))
                        ->required()
                        ->displayFormat('d/m/Y')
                        ->afterOrEqual('period_start')
                        ->default(now()->subMonth()->endOfMonth()),
                    // The tenant no longer types a figure — they attach a sales
                    // report (see the section below). Staff read the number off
                    // it and enter it here, then Lock to bill the percentage
                    // rent. Optional so a declaration can be saved mid-review
                    // (the column is nullable); Lock with no figure owes 0.
                    // ── The certificate: gross, what comes off it, and the net it leaves ──────
                    // `declared_sales` was one number with no stated basis. Percentage rent is
                    // charged on it, and if a tenant reports the VAT-inclusive figure their POS
                    // prints by default the charge is wrong — badly, because the breakpoint is
                    // subtracted first, so a 14% error in sales becomes ~70% in the overage.
                    TextInput::make('gross_sales')
                        ->label(__('admin.fields.gross_sales'))
                        ->prefix('EGP')
                        ->numeric()
                        ->minValue(0)
                        ->step('0.01')
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, Set $set) => self::refreshDerived($get, $set, locked: $get('status') === 'locked'))
                        ->helperText(__('admin.helpers.gross_sales'))
                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.gross_sales')),
                    // The one deduction that is not a concession — a shop collects VAT for the
                    // state, so it was never its sales. Computed as the VAT WITHIN the figure
                    // (gross − gross ÷ 1.14), never gross × 14%, which would over-deduct by a
                    // factor of 1.14.
                    //
                    // AT THE RATE IN FORCE FOR THE PERIOD DECLARED, not today's. `vatWithin()`
                    // falls back to `Vat::standardRate()` with no date, and this call site passed
                    // none — so a declaration keyed after a rate change deducted the NEW rate from
                    // an OLD month's turnover. With a rise to 20% effective 1 August, a June
                    // declaration of 1,368,000 deducted 228,000 instead of 168,000 and billed
                    // 20,300 where 24,500 was due: 21% under, on the document the tenant signed.
                    //
                    // Law 157/2025 makes that an imminent case rather than a hypothetical, and it
                    // is the rule the rest of the system already keeps — a rate is a DATED rung,
                    // and a back-dated document keeps the rate that was in force.
                    Toggle::make('gross_includes_vat')
                        ->label(__('admin.fields.gross_includes_vat'))
                        ->dehydrated(false)
                        ->live()
                        // HYDRATED FROM THE DEDUCTIONS, because it stores nothing of its own.
                        //
                        // As an input helper it only ever wrote a `vat` row and then forgot itself,
                        // so an EDIT page opened with the toggle OFF beside a `vat` deduction of
                        // 168,000 — the screen stating the opposite of the record. Worse than a
                        // display fault: an operator flicking it on and off to check it works
                        // REMOVES that row, and on the declaration this was found on the charge
                        // went from 24,500 to 36,260 with nothing to say a deduction had gone.
                        //
                        // The deductions are the truth and this reflects them, which also makes it
                        // right for a row keyed by hand or arriving from the portal.
                        ->afterStateHydrated(fn (Get $get, Set $set) => $set(
                            'gross_includes_vat',
                            array_key_exists('vat', (array) ($get('sales_exclusions') ?? [])),
                        ))
                        ->helperText(fn (Get $get): string => __('admin.helpers.gross_includes_vat', [
                            'rate' => Vat::standardRate($get('period_end') ?: $get('period_start') ?: null),
                        ]))
                        ->visible(fn (Get $get): bool => filled($get('gross_sales')))
                        ->afterStateUpdated(function (bool $state, Get $get, Set $set) {
                            $exclusions = (array) ($get('sales_exclusions') ?? []);

                            if ($state) {
                                $exclusions['vat'] = SalesExclusions::vatWithin(
                                    (float) $get('gross_sales'),
                                    Vat::standardRate($get('period_end') ?: $get('period_start') ?: null),
                                );
                            } else {
                                unset($exclusions['vat']);
                            }

                            $set('sales_exclusions', $exclusions);
                            self::refreshDerived($get, $set, locked: $get('status') === 'locked');
                        }),
                    KeyValue::make('sales_exclusions')
                        ->label(__('admin.fields.sales_exclusions'))
                        ->keyLabel(__('admin.fields.sales_exclusion_type'))
                        ->valueLabel(__('admin.fields.amount'))
                        ->addActionLabel(__('admin.fields.sales_exclusion_add'))
                        ->live()
                        ->afterStateUpdated(fn (Get $get, Set $set) => self::refreshDerived($get, $set, locked: $get('status') === 'locked'))
                        ->columnSpanFull()
                        ->visible(fn (Get $get): bool => filled($get('gross_sales')))
                        ->helperText(__('admin.helpers.sales_exclusions')),
                    TextInput::make('declared_sales')
                        ->label(__('admin.fields.declared_sales'))
                        ->prefix('EGP')
                        ->numeric()
                        ->minValue(0)
                        ->step('0.01')
                        // Live when it is the field being TYPED — with no gross stated this is the
                        // figure the charge is computed from, and without this the preview beside
                        // it stayed blank however much was keyed into it.
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Get $get, Set $set) => self::refreshDerived($get, $set, locked: $get('status') === 'locked'))
                        // Derived once a gross figure is stated — the same rule as a rate-priced
                        // rent: two editable fields that derive from each other is how they end up
                        // disagreeing. The model is the authority; this only mirrors it on screen.
                        ->disabled(fn (Get $get): bool => filled($get('gross_sales')))
                        ->dehydrated()
                        ->helperText(fn (Get $get): string => filled($get('gross_sales'))
                            ? __('admin.helpers.declared_sales_derived', [
                                'gross' => number_format((float) $get('gross_sales'), 2),
                                'excluded' => number_format(SalesExclusions::total((array) ($get('sales_exclusions') ?? [])), 2),
                            ])
                            : __('admin.fields.declared_sales_help')),
                    TextInput::make('calculated_percentage_rent')
                        ->label(__('admin.fields.calculated_percentage_rent'))
                        // FILLED ON OPEN, not only once a field is touched. The stored column is
                        // 0.00 on anything not yet locked, so without this the preview appeared
                        // only after the operator typed — exactly the state where they most want
                        // to see what the lock is going to charge.
                        //
                        // A LOCKED declaration never reaches this: `canEdit()` refuses it outright,
                        // because it has already raised an invoice. The frozen figure is read on
                        // the VIEW page and in the table, not here.
                        ->afterStateHydrated(fn (Get $get, Set $set) => self::refreshDerived($get, $set))
                        ->prefix('EGP')
                        ->numeric()
                        ->disabled()
                        ->dehydrated(false)
                        ->step('0.01')
                        ->helperText(__('admin.fields.calculated_percentage_rent_help')),
                    Select::make('status')
                        ->label(__('admin.tables.common.status'))
                        ->options(fn () => __('admin.statuses.tenant_sales'))
                        // Read-only: status transitions go through the lock / dispute
                        // / void actions, which run PercentageRentCalculationService
                        // (creating the billing charge + stamping locked_at). A raw
                        // status='locked' write here would silently skip billing.
                        ->disabled()
                        ->dehydrated(false)
                        ->native(false),
                ]),

            Section::make(__('admin.sections.tenant_sales_report'))
                ->description(__('admin.sections.tenant_sales_report_description'))
                ->columns(1)
                ->components([
                    SpatieMediaLibraryFileUpload::make('sales_report')
                        ->label(__('admin.fields.sales_report'))
                        ->collection(TenantSalesDeclaration::REPORT_COLLECTION)
                        ->multiple()
                        ->downloadable()
                        ->openable()
                        ->preserveFilenames()
                        // Images + PDF only — what the tenant app can upload and
                        // the admin viewer can preview.
                        ->acceptedFileTypes(['image/*', 'application/pdf'])
                        ->maxSize(10240)
                        ->maxFiles(5)
                        ->columnSpanFull(),
                ]),

            Section::make(__('admin.sections.tenant_sales_audit'))
                ->columns(1)
                ->components([
                    Textarea::make('audit_notes')
                        ->label(__('admin.fields.audit_notes'))
                        ->rows(3)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
