<?php

namespace App\Filament\Admin\Resources\Violations\Schemas;

use App\Models\Asset;
use App\Models\Tenant;
use App\Models\Violation;
use App\Support\Filament\EntitySelect;
use App\Support\TenantScope;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ViolationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            EntitySelect::make('asset_id')
                ->label(__('admin.violations.fields.property'))
                // Scoped to the user's visible properties (never leaks another mall).
                ->entity(Asset::class)
                ->default(fn () => TenantScope::currentAssetId())
                // Locked to the current property, and additionally frozen once the fine is billed (the
                // invoice's property is fixed by the resolved lease).
                ->disabled(fn (?Violation $record) => TenantScope::currentAssetId() !== null || ($record?->isBilled() ?? false))
                ->dehydrated()
                ->required()
                ->live()
                ->native(false),

            EntitySelect::make('tenant_id')
                ->label(__('admin.violations.fields.tenant'))
                // Scoped to tenants leasing in the user's visible properties (plus
                // unaffiliated tenants) — a restricted user is never offered another
                // mall's tenants. Same helper the TenantRequestForm uses.
                ->entity(Tenant::class)
                // The options exclude a tenant leasing only in another property (or soft-deleted),
                // yet the violation row stays openable via its own asset — resolve the stored tenant
                // so edit never shows the raw id.
                ->getOptionLabelUsing(fn ($value): ?string => Tenant::withTrashed()->find($value)?->name)
                ->searchable()
                ->preload()
                ->required()
                // The tenant is the invoice's debtor once billed — lock it so an edit can't re-point a
                // live fine invoice at a different tenant.
                ->disabled(fn (?Violation $record) => $record?->isBilled() ?? false)
                ->native(false),

            Select::make('category')
                ->label(__('admin.violations.fields.category'))
                ->options(fn () => collect(Violation::CATEGORIES)
                    ->mapWithKeys(fn ($c) => [$c => __("admin.violations.categories.{$c}")]))
                ->default('other')
                ->required()
                ->native(false),

            Textarea::make('description')
                ->label(__('admin.violations.fields.description'))
                ->required()
                ->rows(3)
                ->columnSpanFull(),

            // Photographic evidence of the breach — the thing that makes a violation defensible.
            // Private disk (declared on the model's media collection).
            SpatieMediaLibraryFileUpload::make('photos')
                ->label(__('admin.violations.fields.photos'))
                ->helperText(__('admin.violations.fields.photos_hint'))
                ->collection(Violation::PHOTOS_COLLECTION)
                ->image()
                ->multiple()
                ->reorderable()
                ->downloadable()
                ->maxFiles(8)
                ->columnSpanFull(),

            TextInput::make('fine_amount')
                ->label(__('admin.violations.fields.fine_amount'))
                ->helperText(__('admin.violations.fine_amount_hint'))
                // FR-REQ-15: record the associated cost/fine. Optional (a violation may carry no
                // fine) and non-negative.
                ->numeric()
                ->minValue(0)
                ->prefix('EGP')
                // Once the fine is billed, the amount is fixed by the issued invoice — editing it here
                // would silently diverge the record from the AR. Cancel the invoice to change it.
                ->disabled(fn (?Violation $record) => $record?->isBilled() ?? false),

            DatePicker::make('violation_date')
                ->label(__('admin.violations.fields.violation_date'))
                ->required()
                ->default(now())
                // A violation happened on or before today — never in the future.
                ->maxDate(now())
                ->native(false),

            Select::make('status')
                ->label(__('admin.violations.fields.status'))
                ->options(fn () => collect(Violation::STATUSES)
                    ->mapWithKeys(fn (string $s) => [$s => __("admin.statuses.violation.$s")]))
                ->default(Violation::STATUS_OPEN)
                ->selectablePlaceholder(false)
                ->required()
                ->native(false),

            Textarea::make('notes')
                ->label(__('admin.violations.fields.notes'))
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }
}
