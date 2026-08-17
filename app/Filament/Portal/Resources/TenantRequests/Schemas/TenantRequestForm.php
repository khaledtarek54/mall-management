<?php

namespace App\Filament\Portal\Resources\TenantRequests\Schemas;

use App\Enums\TenantRequestType;
use App\Models\Tenant;
use App\Models\Unit;
use App\Support\Filament\EntitySelect;
use App\Support\Portal;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class TenantRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.sections.request'))
                ->description(__('admin.tenant_requests.portal_create_description'))
                ->columns(2)
                ->components([
                    Select::make('request_type')
                        ->label(__('admin.fields.request_type'))
                        ->options(fn () => TenantRequestType::options())
                        ->default(TenantRequestType::default()->value)
                        ->required()
                        ->native(false)
                        ->live()
                        ->afterStateUpdated(fn (Set $set) => $set('category', null))
                        ->columnSpanFull(),
                    TextInput::make('title')
                        ->label(__('admin.fields.request_title'))
                        ->required()
                        ->maxLength(150)
                        ->columnSpanFull(),
                    Select::make('category')
                        ->label(__('admin.fields.subcategory'))
                        ->options(fn (Get $get) => collect(
                            (TenantRequestType::tryFrom((string) $get('request_type')) ?? TenantRequestType::default())->subcategories()
                        )->mapWithKeys(fn (string $s) => [$s => __("admin.enums.tenant_request_subcategory.{$s}")]))
                        ->visible(fn (Get $get) => filled(
                            (TenantRequestType::tryFrom((string) $get('request_type')) ?? TenantRequestType::default())->subcategories()
                        ))
                        ->native(false),
                    Select::make('priority')
                        ->label(__('admin.fields.priority'))
                        ->options(fn () => __('admin.enums.work_priority'))
                        ->default('medium')
                        ->required()
                        ->native(false)
                        ->helperText(__('admin.tenant_requests.urgent_warning')),
                    EntitySelect::make('unit_id')
                        ->label(__('admin.fields.unit_label'))
                        ->entity(Unit::class)
                        // EVERY unit on the tenant's leases, via the pivot — a multi-unit lease keeps
                        // its extra units there and only the master in `leases.unit_id`, so listing
                        // the column alone hid half a tenant's space and they could not report a
                        // fault in it. This IS the scope in the portal: `visibleAssetIds()` is null
                        // for a TenantUser, so nothing else narrows it.
                        ->modifyOptionsQuery(fn ($query) => $query->whereIn(
                            'id',
                            Portal::tenant()?->leases()->with('units')->get()
                                ->flatMap(fn ($lease) => $lease->units)->pluck('id')->unique() ?? [],
                        ))
                        ->required()
                        ->columnSpanFull()
                        ->default(function () {
                            /** @var Tenant|null $tenant */
                            $tenant = Portal::tenant();

                            return $tenant?->activeLeases()->first()?->unit_id;
                        }),
                    Textarea::make('description')
                        ->label(__('admin.fields.description'))
                        ->required()
                        ->rows(5)
                        ->maxLength(2000)
                        ->columnSpanFull(),
                ]),

            Section::make(__('admin.sections.attachments'))
                ->description(__('admin.tenant_requests.attachments_helper'))
                ->collapsible()
                ->components([
                    SpatieMediaLibraryFileUpload::make('attachments')
                        ->label(__('admin.fields.attachments'))
                        ->collection('attachments')
                        ->multiple()
                        ->reorderable()
                        ->appendFiles()
                        ->downloadable()
                        ->openable()
                        ->preserveFilenames()
                        // Images + PDF only — keep tenant uploads to what the
                        // app and admin viewer can preview (QA-restricted).
                        ->acceptedFileTypes(['image/*', 'application/pdf'])
                        ->maxSize(10240)
                        ->maxFiles(5)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
