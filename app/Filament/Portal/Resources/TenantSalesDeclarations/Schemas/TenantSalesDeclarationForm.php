<?php

namespace App\Filament\Portal\Resources\TenantSalesDeclarations\Schemas;

use App\Models\Lease;
use App\Models\TenantSalesDeclaration;
use App\Support\Filament\EntitySelect;
use App\Support\Portal;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
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
                    EntitySelect::make('lease_id')
                        ->label(__('admin.resources.lease.singular'))
                        ->entity(Lease::class)
                        // This retailer's own percentage-rent leases. The portal has no property
                        // scope of its own (`visibleAssetIds()` is null for a TenantUser), so the
                        // tenant clamp here is the isolation, not an addition to it.
                        ->modifyOptionsQuery(fn ($query) => $query
                            ->where('tenant_id', Portal::tenantId())
                            ->where('status', 'active')
                            ->where('has_percentage_rent', true))
                        ->required(),
                    TextEntry::make('period_info')
                        ->label(__('admin.fields.period'))
                        ->state(fn () => now()->subMonth()->isoFormat('MMMM YYYY')),
                    DatePicker::make('period_start')
                        ->label(__('admin.fields.period_start'))
                        ->required()
                        ->displayFormat('d/m/Y')
                        ->default(now()->startOfMonth()->subMonth())
                        // Clamped to this tenant's own leases. The Select's options scope
                        // the rendering, not the payload — and Laravel runs every field
                        // rule in one pass, so the `in` refusal on a foreign lease_id does
                        // not stop this raw unique query from having already run. Keyed
                        // raw it answered "has <lease> declared sales for <month>?" for
                        // ANOTHER retailer's lease (Portal::clampLeaseId).
                        ->unique(
                            table: TenantSalesDeclaration::class,
                            modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('lease_id', Portal::clampLeaseId($get('lease_id'))),
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
                    // Tenants upload their sales report instead of typing a
                    // figure; the property team reads the number off it and
                    // locks the declaration to bill any percentage rent.
                    SpatieMediaLibraryFileUpload::make('sales_report')
                        ->label(__('admin.fields.sales_report'))
                        ->collection(TenantSalesDeclaration::REPORT_COLLECTION)
                        ->required()
                        ->multiple()
                        ->reorderable()
                        ->appendFiles()
                        ->downloadable()
                        ->openable()
                        ->preserveFilenames()
                        ->acceptedFileTypes(['image/*', 'application/pdf'])
                        ->maxSize(10240)
                        ->maxFiles(5)
                        ->columnSpanFull()
                        ->helperText(__('admin.fields.sales_report_help')),
                ]),
        ]);
    }
}
