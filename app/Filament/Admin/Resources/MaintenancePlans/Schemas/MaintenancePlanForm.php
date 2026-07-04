<?php

namespace App\Filament\Admin\Resources\MaintenancePlans\Schemas;

use App\Models\Department;
use App\Models\MaintenancePlan;
use App\Models\Unit;
use App\Models\Vendor;
use App\Support\TenantScope;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class MaintenancePlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('asset_id')
                ->label(__('admin.preventive_maintenance.fields.property'))
                ->options(fn () => TenantScope::selectableAssetOptions())
                ->default(fn () => TenantScope::currentAssetId())
                ->disabled(fn () => TenantScope::currentAssetId() !== null)
                ->dehydrated()
                ->required()
                ->live()
                ->native(false),
            Select::make('unit_id')
                ->label(__('admin.preventive_maintenance.fields.unit'))
                // Units of the chosen property; blank = common / asset-wide.
                ->options(fn (Get $get) => $get('asset_id')
                    ? Unit::query()->where('asset_id', $get('asset_id'))->orderBy('code')->pluck('code', 'id')->all()
                    : [])
                ->searchable()
                ->native(false),
            TextInput::make('title')
                ->label(__('admin.preventive_maintenance.fields.title'))
                ->required()
                ->maxLength(255),
            Select::make('category')
                ->label(__('admin.preventive_maintenance.fields.category'))
                ->options(fn () => __('admin.preventive_maintenance.categories'))
                ->default('other')
                ->required()
                ->native(false),
            TextInput::make('frequency_value')
                ->label(__('admin.preventive_maintenance.fields.frequency_value'))
                ->numeric()
                ->minValue(1)
                ->default(1)
                ->required(),
            Select::make('frequency_unit')
                ->label(__('admin.preventive_maintenance.fields.frequency_unit'))
                ->options(fn () => __('admin.preventive_maintenance.frequency_units'))
                ->default('months')
                ->required()
                ->native(false),
            DatePicker::make('next_due_date')
                ->label(__('admin.preventive_maintenance.fields.next_due'))
                ->default(now())
                ->required()
                ->native(false),
            Toggle::make('is_active')
                ->label(__('admin.preventive_maintenance.fields.active'))
                ->default(true),
            Select::make('department_id')
                ->label(__('admin.preventive_maintenance.fields.department'))
                ->options(fn () => Department::selectableOptions())
                ->searchable()
                ->native(false),
            Select::make('vendor_id')
                ->label(__('admin.preventive_maintenance.fields.vendor'))
                ->options(fn () => Vendor::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->native(false),
            TagsInput::make('checklist')
                ->label(__('admin.preventive_maintenance.fields.checklist'))
                ->placeholder('Add a check item…')
                ->columnSpanFull(),
            Textarea::make('description')
                ->label(__('admin.preventive_maintenance.fields.description'))
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }
}
