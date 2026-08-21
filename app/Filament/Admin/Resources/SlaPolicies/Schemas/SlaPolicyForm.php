<?php

namespace App\Filament\Admin\Resources\SlaPolicies\Schemas;

use App\Enums\TenantRequestType;
use App\Models\SlaPolicy;
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

            Select::make('request_type')
                ->label(__('admin.facility.sla.request_type'))
                ->helperText(__('admin.facility.sla.request_type_hint'))
                // `any` is a real value, not a blank: the column is NOT NULL because a nullable one
                // inside the unique index stops enforcing it — SQL treats NULLs as distinct, so two
                // conflicting "urgent" rows would both save.
                ->options(fn () => [SlaPolicy::ANY_TYPE => __('admin.facility.sla.any_request_type')]
                    + collect(TenantRequestType::cases())
                        ->filter(fn (TenantRequestType $t) => $t->hasSla())
                        ->mapWithKeys(fn (TenantRequestType $t) => [$t->value => $t->label()])
                        ->all())
                ->default(SlaPolicy::ANY_TYPE)
                ->required()
                ->live()
                ->native(false),

            Select::make('priority')
                ->label(__('admin.facility.fields.priority'))
                ->helperText(__('admin.facility.sla.priority_hint'))
                ->options(fn () => __('admin.facility.priorities'))
                ->required()
                ->live()
                // One row per property × REQUEST TYPE × priority, matching
                // sla_policy_asset_type_priority_unique. The type was added to the index and not to
                // this rule, which made the form refuse a legitimate second row ("urgent complaints
                // in 8 hours" beside "urgent anything in 4") with a validation error naming no
                // reason. Clamped: asset_id is client-supplied, and a unique rule keyed on the raw
                // value leaks which properties have a policy (TenantScope::clampAssetId).
                ->unique(ignoreRecord: true, modifyRuleUsing: fn (Unique $rule, Get $get) => $rule
                    ->where('asset_id', TenantScope::clampAssetId($get('asset_id')))
                    ->where('request_type', $get('request_type') ?: SlaPolicy::ANY_TYPE))
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
