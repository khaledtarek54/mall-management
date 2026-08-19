<?php

namespace App\Filament\Admin\Resources\SlaPolicies\Schemas;

use App\Support\Filament\PropertyField;
use App\Support\SlaResolver;
use App\Support\TenantScope;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Unique;

class SlaPolicyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            PropertyField::make()
                ->label(__('admin.facility.fields.property'))
                ->live(),

            Select::make('priority')
                ->label(__('admin.facility.fields.priority'))
                ->helperText(__('admin.facility.sla.priority_hint'))
                ->options(fn () => __('admin.facility.priorities'))
                ->required()
                ->live()
                // One row per property × priority, matching sla_policy_asset_priority_unique.
                // Clamped: asset_id is client-supplied, and a unique rule keyed on the raw
                // value leaks which properties have a policy (TenantScope::clampAssetId).
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule->where('asset_id', TenantScope::clampAssetId($get('asset_id'))))
                ->native(false),

            TextInput::make('resolve_hours')
                ->label(__('admin.facility.sla.hours'))
                ->helperText(__('admin.facility.sla.hours_hint'))
                ->numeric()
                ->minValue(1)
                ->required()
                // Show what this property gets today, so an operator overriding a value can
                // see what they are changing away from rather than guessing.
                ->placeholder(fn (Get $get) => filled($get('priority'))
                    ? SlaResolver::globalHoursFor($get('priority')).' ('.__('admin.facility.sla.global_default').')'
                    : null)
                ->default(fn (Get $get) => filled($get('priority')) ? SlaResolver::globalHoursFor($get('priority')) : null),

            Toggle::make('is_active')
                ->label(__('admin.facility.fields.active'))
                ->helperText(__('admin.facility.sla.active_hint'))
                ->default(true),
        ]);
    }
}
