<?php

namespace App\Filament\Admin\Resources\PurchaseRequests\Schemas;

use App\Models\PurchaseRequest;
use App\Models\Warehouse;
use App\Support\TenantScope;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class PurchaseRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('asset_id')
                ->label(__('admin.procurement.fields.asset'))
                ->options(fn () => TenantScope::selectableAssetOptions())
                ->required()
                ->live()
                ->native(false)
                // Editable after creation only while nothing has been ordered — the property
                // decides the budget and the warehouse, so moving it later would strand both.
                ->disabled(fn (?PurchaseRequest $record) => $record !== null && $record->status !== PurchaseRequest::STATUS_REQUESTED),

            // FR-PROC-01 — "and justification". Required: a purchase nobody can justify is what
            // the approval workflow exists to catch.
            Textarea::make('justification')
                ->label(__('admin.procurement.fields.justification'))
                ->required()
                ->rows(3)
                ->columnSpanFull(),

            Select::make('warehouse_id')
                ->label(__('admin.procurement.fields.warehouse'))
                ->helperText(__('admin.procurement.fields.warehouse_hint'))
                // The requesting mall's warehouses only — goods are received into the mall that
                // asked for them. The service re-checks this on receipt; the form is one caller.
                ->options(fn (callable $get) => Warehouse::query()
                    ->where('asset_id', TenantScope::clampAssetId($get('asset_id')))
                    ->where('is_active', true)
                    ->orderBy('name')
                    ->pluck('name', 'id')
                    ->all())
                ->native(false),
        ]);
    }
}
