<?php

namespace App\Filament\Admin\Resources\TenantSalesDeclarations\Schemas;

use App\Models\Lease;
use App\Models\TenantSalesDeclaration;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class TenantSalesDeclarationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.sections.tenant_sales'))
                ->columns(3)
                ->components([
                    Select::make('lease_id')
                        ->label(__('admin.resources.lease.singular'))
                        ->options(function () {
                            $assetId = \App\Support\TenantScope::currentAssetId();
                            return Lease::with(['tenant', 'unit'])
                                ->where('status', 'active')
                                ->when($assetId, fn ($q) => $q->whereHas('unit', fn ($u) => $u->where('asset_id', $assetId)))
                                ->get()
                                ->mapWithKeys(fn (Lease $l) => [$l->id => sprintf('%s — %s (%s)', $l->reference, $l->tenant?->name, $l->unit?->code)]);
                        })
                        ->searchable()
                        ->required(),
                    DatePicker::make('period_start')
                        ->label(__('admin.fields.period_start'))
                        ->required()
                        ->displayFormat('d/m/Y')
                        ->default(now()->startOfMonth()->subMonth())
                        ->unique(
                            table: TenantSalesDeclaration::class,
                            ignoreRecord: true,
                            modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('lease_id', $get('lease_id')),
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
                    TextInput::make('declared_sales')
                        ->label(__('admin.fields.declared_sales'))
                        ->prefix('EGP')
                        ->required()
                        ->numeric()
                        ->minValue(0)
                        ->step('0.01'),
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
