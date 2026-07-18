<?php

namespace App\Filament\Admin\Resources\Violations\Schemas;

use App\Models\Violation;
use App\Support\TenantScope;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class ViolationForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('asset_id')
                ->label(__('admin.violations.fields.property'))
                // Scoped to the user's visible properties (never leaks another mall).
                ->options(fn () => TenantScope::selectableAssetOptions())
                ->default(fn () => TenantScope::currentAssetId())
                ->disabled(fn () => TenantScope::currentAssetId() !== null)
                ->dehydrated()
                ->required()
                ->live()
                ->native(false),

            Select::make('tenant_id')
                ->label(__('admin.violations.fields.tenant'))
                // Scoped to tenants leasing in the user's visible properties (plus
                // unaffiliated tenants) — a restricted user is never offered another
                // mall's tenants. Same helper the TenantRequestForm uses.
                ->options(fn () => TenantScope::selectableTenantOptions())
                ->searchable()
                ->preload()
                ->required()
                ->native(false),

            Textarea::make('description')
                ->label(__('admin.violations.fields.description'))
                ->required()
                ->rows(3)
                ->columnSpanFull(),

            TextInput::make('fine_amount')
                ->label(__('admin.violations.fields.fine_amount'))
                ->helperText(__('admin.violations.fine_amount_hint'))
                // FR-REQ-15: record the associated cost/fine. Optional (a violation
                // may carry no fine) and non-negative. Recorded only — never billed.
                ->numeric()
                ->minValue(0)
                ->prefix('EGP'),

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
