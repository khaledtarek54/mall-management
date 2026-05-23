<?php

namespace App\Filament\Portal\Resources\TenantSalesDeclarations\Schemas;

use App\Models\Lease;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

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
                            ->where('tenant_id', Auth::guard('portal')->id())
                            ->where('status', 'active')
                            ->where('has_percentage_rent', true)
                            ->get()
                            ->mapWithKeys(fn (Lease $l) => [
                                $l->id => sprintf('%s — %s', $l->reference, $l->unit?->code),
                            ]))
                        ->required()
                        ->native(false),
                    Placeholder::make('period_info')
                        ->label(__('admin.fields.period'))
                        ->content(fn () => now()->subMonth()->isoFormat('MMMM YYYY')),
                    DatePicker::make('period_start')
                        ->label(__('admin.fields.period_start'))
                        ->required()
                        ->displayFormat('d/m/Y')
                        ->default(now()->startOfMonth()->subMonth()),
                    DatePicker::make('period_end')
                        ->label(__('admin.fields.period_end'))
                        ->required()
                        ->displayFormat('d/m/Y')
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
