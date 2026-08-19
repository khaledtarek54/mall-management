<?php

namespace App\Filament\Admin\Resources\PurchaseRequests\Schemas;

use App\Models\PurchaseRequest;
use App\Models\Warehouse;
use App\Support\Filament\EntitySelect;
use App\Support\Filament\PropertyField;
use App\Support\TenantScope;
use Filament\Forms\Components\Textarea;
use Filament\Schemas\Schema;

class PurchaseRequestForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            // The extra lock still carries its own reason for the paths where nothing is pinned
            // (console, the All-Properties plumbing): the property decides the budget and the
            // warehouse, so moving it once something has been ordered would strand both.
            PropertyField::make(alsoDisabledWhen: fn (?PurchaseRequest $record) => $record !== null && $record->status !== PurchaseRequest::STATUS_REQUESTED)
                ->label(__('admin.procurement.fields.asset'))
                ->live(),

            // FR-PROC-01 — "and justification". Required: a purchase nobody can justify is what
            // the approval workflow exists to catch. Frozen once approved (same as asset_id +
            // warehouse below) — the model's updating guard is the real gate; this is the UX.
            Textarea::make('justification')
                ->label(__('admin.procurement.fields.justification'))
                ->required()
                ->rows(3)
                ->columnSpanFull()
                ->disabled(fn (?PurchaseRequest $record) => $record !== null && $record->status !== PurchaseRequest::STATUS_REQUESTED),

            EntitySelect::make('warehouse_id')
                ->label(__('admin.procurement.fields.warehouse'))
                ->helperText(__('admin.procurement.fields.warehouse_hint'))
                ->entity(Warehouse::class)
                // The requesting mall's warehouses only — goods are received into the mall that
                // asked for them. The service re-checks this on receipt; the form is one caller.
                ->modifyOptionsQuery(fn ($query, callable $get) => $query
                    ->where('asset_id', TenantScope::clampAssetId($get('asset_id')))
                    ->where('is_active', true))
                // Frozen once the request leaves `requested` — moving where the goods land after
                // the PO is out would strand the receipt from the movement (audit M29-1).
                ->disabled(fn (?PurchaseRequest $record) => $record !== null && $record->status !== PurchaseRequest::STATUS_REQUESTED),
        ]);
    }
}
