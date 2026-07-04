<?php

namespace App\Filament\Admin\Resources\MaintenanceWorkOrders\Schemas;

use App\Models\Department;
use App\Models\MaintenanceWorkOrder;
use App\Models\Unit;
use App\Models\Vendor;
use App\Support\TenantScope;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class MaintenanceWorkOrderForm
{
    public static function configure(Schema $schema): Schema
    {
        // A done/cancelled order is terminal — read-only.
        $locked = fn (?MaintenanceWorkOrder $record) => $record !== null && $record->isTerminal();

        return $schema->columns(2)->components([
            Select::make('asset_id')
                ->label(__('admin.preventive_maintenance.fields.property'))
                ->options(fn () => TenantScope::selectableAssetOptions())
                ->default(fn () => TenantScope::currentAssetId())
                ->disabled(fn (?MaintenanceWorkOrder $record) => TenantScope::currentAssetId() !== null || $record !== null)
                ->dehydrated()
                ->required()
                ->live()
                ->native(false),
            Select::make('unit_id')
                ->label(__('admin.preventive_maintenance.fields.unit'))
                ->options(fn (Get $get) => $get('asset_id')
                    ? Unit::query()->where('asset_id', $get('asset_id'))->orderBy('code')->pluck('code', 'id')->all()
                    : [])
                ->searchable()
                ->native(false)
                ->disabled($locked),
            TextInput::make('title')
                ->label(__('admin.preventive_maintenance.fields.title'))
                ->required()
                ->maxLength(255)
                ->disabled($locked),
            Select::make('category')
                ->label(__('admin.preventive_maintenance.fields.category'))
                ->options(fn () => __('admin.preventive_maintenance.categories'))
                ->default('other')
                ->required()
                ->native(false)
                ->disabled($locked),
            DatePicker::make('scheduled_for')
                ->label(__('admin.preventive_maintenance.fields.scheduled_for'))
                ->default(now())
                ->required()
                ->native(false)
                ->disabled($locked),
            Select::make('department_id')
                ->label(__('admin.preventive_maintenance.fields.department'))
                ->options(fn () => Department::selectableOptions())
                ->searchable()
                ->native(false)
                ->disabled($locked),
            Select::make('vendor_id')
                ->label(__('admin.preventive_maintenance.fields.vendor'))
                ->options(fn () => Vendor::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->native(false)
                ->disabled($locked),
            Textarea::make('notes')
                ->label(__('admin.preventive_maintenance.fields.notes'))
                ->rows(2)
                ->columnSpanFull()
                ->disabled($locked),
        ]);
    }
}
