<?php

namespace App\Filament\Admin\Resources\TenantRequests\Schemas;

use App\Enums\TenantRequestType;
use App\Models\Area;
use App\Models\Department;
use App\Models\Tenant;
use App\Models\TenantRequest;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vendor;
use App\Support\Filament\EntitySelect;
use App\Support\FormTab;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class TenantRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        // One tab per concern through App\Support\FormTab, so each carries a badge counting
        // the validation errors INSIDE it (UX-13) — Filament v4 has no error indicator on Tabs,
        // and without one a blank required field on an unseen tab refuses the form with nothing
        // visible to fix.
        return $schema->columns(1)->components([
            Tabs::make('tenant_request')
                ->columnSpanFull()
                ->persistTabInQueryString()
                ->tabs([
                    FormTab::make('admin.sections.request', [

                        TextInput::make('reference')
                            ->label(__('admin.fields.reference'))
                            ->default(fn () => TenantRequest::generateReference('AW'))
                            ->disabled()
                            ->dehydrated(),
                        Select::make('request_type')
                            ->label(__('admin.fields.request_type'))
                            ->options(fn () => TenantRequestType::options())
                            ->default(TenantRequestType::default()->value)
                            ->required()
                            ->native(false)
                            ->live()
                            // Keep the reference prefix in step with the chosen type,
                            // and clear a now-irrelevant sub-category on type change.
                            ->afterStateUpdated(function ($state, Set $set) {
                                $type = TenantRequestType::tryFrom((string) $state) ?? TenantRequestType::default();
                                $set('reference', TenantRequest::generateReference('AW', $type->referencePrefix()));
                                $set('category', null);
                            })
                            // Type is fixed once a request exists (it would invalidate
                            // the reference + routing); editable only on create.
                            ->disabledOn('edit'),
                        EntitySelect::make('tenant_id')
                            ->label(__('admin.resources.tenant.singular'))
                            ->entity(Tenant::class)
                            // Resolve a stored tenant who now leases only in another property (excluded
                            // from the scoped options) so edit shows the name, not the raw id.
                            ->getOptionLabelUsing(fn ($value): ?string => Tenant::find($value)?->name)
                            ->searchable()
                            ->preload()
                            ->live()
                            // Required for a portal request (always a known tenant), or whenever no
                            // caller name is given — a request must record who reported it (the same
                            // invariant TenantRequest::booted enforces server-side).
                            ->required(fn (Get $get) => $get('channel') === TenantRequest::SELF_SERVICE_CHANNEL || blank($get('caller_name'))),
                        // Intake for someone who is NOT a registered tenant — only on a staff channel.
                        TextInput::make('caller_name')
                            ->label(__('admin.tenant_requests.caller.name'))
                            ->maxLength(255)
                            ->live()
                            ->helperText(__('admin.tenant_requests.caller.section_hint'))
                            ->visible(fn (Get $get) => $get('channel') !== TenantRequest::SELF_SERVICE_CHANNEL)
                            ->required(fn (Get $get) => $get('channel') !== TenantRequest::SELF_SERVICE_CHANNEL && blank($get('tenant_id'))),
                        TextInput::make('caller_phone')
                            ->label(__('admin.tenant_requests.caller.phone'))
                            ->tel()
                            ->maxLength(50)
                            ->visible(fn (Get $get) => $get('channel') !== TenantRequest::SELF_SERVICE_CHANNEL),
                        Textarea::make('caller_notes')
                            ->label(__('admin.tenant_requests.caller.notes'))
                            ->rows(2)
                            ->visible(fn (Get $get) => $get('channel') !== TenantRequest::SELF_SERVICE_CHANNEL),
                        EntitySelect::make('unit_id')
                            ->label(__('admin.fields.unit_label'))
                            ->entity(Unit::class)
                            ->required(),
                        Select::make('priority')
                            ->label(__('admin.fields.priority'))
                            ->options(fn () => __('admin.enums.work_priority'))
                            ->default('medium')
                            ->required()
                            ->native(false),
                        Select::make('category')
                            ->label(__('admin.fields.subcategory'))
                            // Options + visibility follow the chosen type: each type
                            // exposes its own sub-categories (electrical…, parking…,
                            // lease_copy…); types with none hide the field entirely.
                            ->options(fn (Get $get) => collect(
                                (TenantRequestType::tryFrom((string) $get('request_type')) ?? TenantRequestType::default())->subcategories()
                            )->mapWithKeys(fn (string $s) => [$s => __("admin.enums.tenant_request_subcategory.{$s}")]))
                            ->visible(fn (Get $get) => filled(
                                (TenantRequestType::tryFrom((string) $get('request_type')) ?? TenantRequestType::default())->subcategories()
                            ))
                            ->native(false),
                        Select::make('channel')
                            ->label(__('admin.fields.channel'))
                            ->options(fn () => __('admin.enums.request_channel'))
                            ->default('portal')
                            ->required()
                            ->native(false)
                            ->live() // drives the caller-intake fields + tenant requiredness
                            ->helperText(__('admin.fields.channel_helper')),
                        Select::make('status')
                            ->label(__('admin.tables.common.status'))
                            ->options(fn () => __('admin.statuses.tenant_request'))
                            ->default('submitted')
                            // Read-only: status changes go through the Change-Status action
                            // (TenantRequestService::transition) — the state machine that
                            // validates the hop, stamps resolved_at/closed_at, and notifies.
                            // A raw form write would skip all of that (and break auto-close).
                            ->disabled()
                            ->dehydrated(false)
                            ->native(false),
                    ])->columns(3),

                    FormTab::make('admin.sections.request_details', [

                        TextInput::make('title')
                            ->label(__('admin.fields.request_title'))
                            ->required()
                            ->maxLength(150)
                            ->columnSpanFull(),
                        Textarea::make('description')
                            ->label(__('admin.fields.description'))
                            ->required()
                            ->rows(4)
                            ->columnSpanFull(),
                    ])->columns(1),

                    // FR-REQ-13 / FR-REQ-14 — permit validity window. Shown + required only for the `permit`
                    // request type (driven by the ->live() request_type above), read-only/hidden otherwise.
                    // The model guards the ordering (valid_to >= valid_from) as a backstop; the inline
                    // afterOrEqual keeps a bad range from ever reaching it. NO approval step — a permit is a
                    // typed request that carries this window, nothing more.
                    FormTab::make('admin.sections.permit_validity', [
                        Placeholder::make('__tab_help')
                            ->hiddenLabel()
                            ->content(__('admin.sections.permit_validity_description'))
                            ->columnSpanFull(),

                        DatePicker::make('valid_from')
                            ->label(__('admin.fields.valid_from'))
                            ->native(false)
                            ->required(fn (Get $get) => $get('request_type') === TenantRequestType::Permit->value),
                        DatePicker::make('valid_to')
                            ->label(__('admin.fields.valid_to'))
                            ->native(false)
                            ->required(fn (Get $get) => $get('request_type') === TenantRequestType::Permit->value)
                            ->afterOrEqual('valid_from'),
                    ])->columns(2)
                        // Permit-only, exactly as the SECTION was: a Tab takes
                        // ->visible() too, and without it every request would carry an
                        // irrelevant permit tab.
                        ->visible(fn (Get $get) => $get('request_type') === TenantRequestType::Permit->value),

                    FormTab::make('admin.sections.assignment', [

                        EntitySelect::make('department_id')
                            ->label(__('admin.resources.department.singular'))
                            ->entity(Department::class)
                            // selectableOptions filters is_active — resolve a stored (now-inactive)
                            // department so edit shows its name, not the raw id.
                            ->getOptionLabelUsing(fn ($value): ?string => Department::find($value)?->name)
                            ->searchable()
                            ->placeholder(__('admin.fields.unassigned'))
                            ->native(false),
                        // The facility zone this request sits in — inherited from the unit on intake
                        // (TenantRequest::creating), so it's shown read-only here. Disabled +
                        // non-dehydrated: the derivation owns the value, the form only surfaces it.
                        EntitySelect::make('area_id')
                            ->label(__('admin.fields.area'))
                            ->entity(Area::class)
                            ->disabled()
                            ->dehydrated(false)
                            ->placeholder(__('admin.fields.area_auto')),
                        EntitySelect::make('assigned_to')
                            ->label(__('admin.fields.assigned_to'))
                            ->entity(User::class)
                            ->placeholder(__('admin.fields.unassigned')),
                        EntitySelect::make('assigned_to_vendor_id')
                            ->label(__('admin.fields.assigned_vendor') ?: 'External Vendor')
                            ->entity(Vendor::class)
                            ->relationship('assignedVendor')
                            ->modifyOptionsQuery(fn ($query) => $query->where('status', 'active'))
                            // The picker offers ACTIVE vendors only; a vendor assigned while active and
                            // later deactivated would otherwise render as its raw id on this form. This
                            // resolves it for DISPLAY, deliberately outside the narrowing above — and
                            // after `->entity()`, which installs its own scoped resolver.
                            ->getOptionLabelUsing(fn ($value): ?string => Vendor::find($value)?->name)
                            ->placeholder('—'),
                        DateTimePicker::make('target_resolution_at')
                            ->label(__('admin.fields.target_resolution_at'))
                            ->native(false)
                            ->seconds(false)
                            // A resolution target can't predate the request itself.
                            // On edit, floor it at the record's creation date; on
                            // create the row doesn't exist yet, so floor at today.
                            ->minDate(fn (?TenantRequest $record) => $record?->created_at?->startOfDay() ?? today())
                            ->validationMessages([
                                'after_or_equal' => __('admin.validation.request_resolution_after_creation'),
                            ]),
                        DateTimePicker::make('scheduled_from')
                            ->label(__('admin.fields.scheduled_from'))
                            ->native(false)
                            ->seconds(false),
                        DateTimePicker::make('scheduled_to')
                            ->label(__('admin.fields.scheduled_to'))
                            ->native(false)
                            ->seconds(false)
                            ->afterOrEqual('scheduled_from'),
                    ])->columns(2),

                    FormTab::make('admin.sections.resolution', [

                        Textarea::make('resolution_notes')
                            ->label(__('admin.fields.resolution_notes'))
                            ->rows(3)
                            ->columnSpanFull(),
                    ])->columns(1),

                    FormTab::make('admin.sections.attachments', [
                        Placeholder::make('__tab_help')
                            ->hiddenLabel()
                            ->content(__('admin.sections.attachments_description'))
                            ->columnSpanFull(),

                        SpatieMediaLibraryFileUpload::make('attachments')
                            ->label(__('admin.fields.attachments'))
                            ->collection('attachments')
                            ->multiple()
                            ->reorderable()
                            ->appendFiles()
                            ->downloadable()
                            ->openable()
                            ->preserveFilenames()
                            // Images + PDF only — these are what the tenant app can
                            // render/preview. Wider types (video, office docs) were
                            // dropped per QA so the mobile viewer never gets a file
                            // it can't open.
                            ->acceptedFileTypes(['image/*', 'application/pdf'])
                            ->maxSize(10240)
                            ->columnSpanFull(),
                    ]),
                ]),
        ]);
    }
}
