<?php

namespace App\Filament\Portal\Resources\TenantSalesDeclarations\Schemas;

use App\Models\Lease;
use App\Models\TenantSalesDeclaration;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Unique;

class TenantSalesDeclarationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.sections.tenant_sales_submit'))
                ->description(__('admin.sections.tenant_sales_submit_description'))
                ->columns(2)
                ->components([
                    Select::make('lease_id')
                        ->label(__('admin.resources.lease.singular'))
                        ->options(fn () => Lease::with('unit')
                            ->where('tenant_id', \App\Support\Portal::tenantId())
                            ->where('status', 'active')
                            ->where('has_percentage_rent', true)
                            ->get()
                            ->mapWithKeys(fn (Lease $l) => [
                                $l->id => sprintf('%s — %s', $l->reference, $l->unit?->code),
                            ]))
                        ->required()
                        ->native(false),
                    TextEntry::make('period_info')
                        ->label(__('admin.fields.period'))
                        ->state(fn () => now()->subMonth()->isoFormat('MMMM YYYY')),
                    DatePicker::make('period_start')
                        ->label(__('admin.fields.period_start'))
                        ->required()
                        ->displayFormat('d/m/Y')
                        ->default(now()->startOfMonth()->subMonth())
                        ->unique(
                            table: TenantSalesDeclaration::class,
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
                        ->step('0.01')
                        ->columnSpanFull()
                        ->helperText(__('admin.fields.declared_sales_help')),
                ]),
        ]);
    }
}
