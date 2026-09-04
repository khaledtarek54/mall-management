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

            // **THE DECISION, ON THE RECORD'S ONLY READ SURFACE.** This resource declares no
            // infolist, so the list's `ViewAction` renders THIS schema disabled — and the schema
            // carried the property, the justification and the warehouse, and nothing at all about
            // how the request ENDED. `PurchaseRequestService::reject()` and `::cancel()` REQUIRE a
            // reason and `::approve()` invites a note; all three write it to `decision_notes`, and
            // at HEAD (e3154f27) `grep -rn decision_notes app/` returned those three writes plus
            // the `ActivityLogging` registration and NOT ONE read. So a refused purchase showed a
            // red *Rejected* badge and no reason anywhere in the panel — not on the list, not in
            // the View modal, not here. Measured on `mall_management_qa`, PR-AW-202609-0001 stores
            // "Approved — within the maintenance budget." and no screen printed it.
            //
            // Shown by the DATA, never by a status list: `approve` writes an OPTIONAL note and
            // `receive` writes none, so `PurchaseRequest::TERMINAL` — or any hand-written set —
            // would be a second answer, free to drift from the three services that do the writing.
            //
            // `dehydrated(false)` is belt to `disabled()`'s braces, and stated rather than assumed:
            // measured in this build, `CanBeDisabled::disabled()` also calls `saved(false)`
            // (`vendor/filament/schemas/src/Components/Concerns/CanBeDisabled.php:25`) and
            // `HasState::isDehydrated()` falls back to `isSaved()` (`.../HasState.php:776`), so a
            // disabled field is already not submitted TODAY. That is an upstream implementation
            // detail; saying it here means a Filament release that decouples the two cannot quietly
            // open a write path to the one column an operator must never touch — the reason
            // somebody's purchase was refused. The Edit page is reachable for every status
            // (`canEdit()` asks `procurement.edit` and nothing else) and the `updating` guard
            // freezes only `asset_id`, `warehouse_id` and `justification`, so there would be
            // nothing else in the way.
            Textarea::make('decision_notes')
                ->label(__('admin.fields.decision_notes'))
                ->disabled()
                ->dehydrated(false)
                ->rows(2)
                ->columnSpanFull()
                ->visible(fn (?PurchaseRequest $record): bool => filled($record?->decision_notes))
                // WHO decided and WHEN. `decided_by_user_id` and `decided_at` were written by the
                // same three services and read by nothing either: a refusal nobody can attribute is
                // one the buyer cannot take up with anybody.
                ->helperText(fn (?PurchaseRequest $record): ?string => $record?->decided_at === null
                    ? null
                    : __('admin.procurement.decided_by_on', [
                        'user' => $record->decidedBy?->name ?? '—',
                        'date' => $record->decided_at->toDateString(),
                    ])),
        ]);
    }
}
