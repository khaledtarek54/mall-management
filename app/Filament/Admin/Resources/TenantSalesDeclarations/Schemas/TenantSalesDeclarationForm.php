<?php

namespace App\Filament\Admin\Resources\TenantSalesDeclarations\Schemas;

use App\Models\Lease;
use App\Models\TenantSalesDeclaration;
use App\Support\Filament\EntitySelect;
use App\Support\SalesExclusions;
use App\Support\Search\OptionDisplay;
use App\Support\TenantScope;
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
                        // Options list only ACTIVE leases; a declaration on a lease later expired or
                        // terminated would render the raw id on edit — resolve any stored lease.
                        // After `->entity()`, which installs its own narrowed resolver.
                        ->getOptionLabelUsing(fn ($value): ?string => ($l = Lease::with(['tenant', 'unit'])->find($value))
                            ? OptionDisplay::for($l)->toHtml()
                            : null)
                        ->required(),
                    DatePicker::make('period_start')
                        ->label(__('admin.fields.period_start'))
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
                        ->helperText(__('admin.helpers.gross_sales'))
                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.gross_sales')),
                    // The one deduction that is not a concession — a shop collects VAT for the
                    // state, so it was never its sales. Computed as the VAT WITHIN the figure
                    // (gross − gross ÷ 1.14), never gross × 14%, which would over-deduct by a
                    // factor of 1.14.
                    Toggle::make('gross_includes_vat')
                        ->label(__('admin.fields.gross_includes_vat'))
                        ->dehydrated(false)
                        ->live()
                        ->helperText(__('admin.helpers.gross_includes_vat'))
                        ->visible(fn (Get $get): bool => filled($get('gross_sales')))
                        ->afterStateUpdated(function (bool $state, Get $get, Set $set) {
                            $exclusions = (array) ($get('sales_exclusions') ?? []);

                            if ($state) {
                                $exclusions['vat'] = SalesExclusions::vatWithin((float) $get('gross_sales'));
                            } else {
                                unset($exclusions['vat']);
                            }

                            $set('sales_exclusions', $exclusions);
                        }),
                    KeyValue::make('sales_exclusions')
                        ->label(__('admin.fields.sales_exclusions'))
                        ->keyLabel(__('admin.fields.sales_exclusion_type'))
                        ->valueLabel(__('admin.fields.amount'))
                        ->addActionLabel(__('admin.fields.sales_exclusion_add'))
                        ->live()
                        ->columnSpanFull()
                        ->visible(fn (Get $get): bool => filled($get('gross_sales')))
                        ->helperText(__('admin.helpers.sales_exclusions')),
                    TextInput::make('declared_sales')
                        ->label(__('admin.fields.declared_sales'))
                        ->prefix('EGP')
                        ->numeric()
                        ->minValue(0)
                        ->step('0.01')
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
