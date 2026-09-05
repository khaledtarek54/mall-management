<?php

namespace App\Filament\Portal\Resources\TenantRequests\Schemas;

use App\Enums\TenantRequestType;
use App\Enums\UnitOwnershipStatus;
use App\Models\Tenant;
use App\Models\TenantRequestSubcategory;
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
use Illuminate\Support\Collection;

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
                        ->options(fn (Get $get) => TenantRequestSubcategory::optionsFor(
                            TenantRequestType::tryFrom((string) $get('request_type')) ?? TenantRequestType::default()
                        ))
                        ->visible(fn (Get $get) => filled(TenantRequestSubcategory::optionsFor(
                            TenantRequestType::tryFrom((string) $get('request_type')) ?? TenantRequestType::default()
                        )))
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
                        ->modifyOptionsQuery(fn ($query) => $query->whereIn('id', self::reportableUnitIds()))
                        ->required()
                        ->columnSpanFull()
                        ->default(fn (): ?int => self::reportableUnitIds()->first()),
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

    /**
     * Every unit this portal account may report a fault in — LEASED **or OWNED**.
     *
     * **A unit owner could not raise a request at all.** Module 37's own rule is that an owner IS a
     * `tenants` row, and every other portal surface treats them as one — they receive assessments,
     * pay them, and read their own statement. This picker drew from LEASES only, so an owner who has
     * taken handover of a shop and has no lease saw an EMPTY dropdown on a `required()` field: the
     * screen was offered, the form could not be completed, and an empty picker reads as "no such
     * record" rather than as a bug, which is how it survived. The fault they were trying to report
     * came in by telephone instead.
     *
     * **Leases go through the PIVOT**, because a multi-unit lease keeps its extra units there and
     * only the master in `leases.unit_id` — listing the column alone hid half a tenant's space.
     * **Ownerships are `handed_over` and COVERING today**: `contracted` or `reserved` means the shop
     * has not been given to them yet, and a `transferred` one is somebody else's now, so neither is
     * a place they can report a fault in. Same predicate the assessment run bills from, so the two
     * cannot disagree about which shops are theirs.
     *
     * This IS the scope in the portal — `TenantScope::visibleAssetIds()` is null for a `TenantUser`,
     * so nothing else narrows it.
     *
     * @return Collection<int, int>
     */
    private static function reportableUnitIds(): Collection
    {
        $tenant = Portal::tenant();

        if (! $tenant instanceof Tenant) {
            return collect();
        }

        $leased = $tenant->leases()->with('units')->get()
            ->flatMap(fn ($lease) => $lease->units)->pluck('id');

        $owned = $tenant->unitOwnerships()
            ->where('status', UnitOwnershipStatus::HandedOver)
            ->covering()
            ->pluck('unit_id');

        return $leased->concat($owned)->filter()->unique()->values();
    }
}
