<?php

namespace App\Filament\Admin\Resources\PurchaseRequests\Tables;

use App\Filament\Admin\Resources\PurchaseRequests\PurchaseRequestResource;
use App\Models\ApprovalRule;
use App\Models\PurchaseRequest;
use App\Models\Vendor;
use App\Services\PurchaseOrderPdfService;
use App\Support\Filament\PdfDownloadAction;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PurchaseRequestsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['asset', 'vendor', 'warehouse', 'requestedBy']))
            ->columns([
                TextColumn::make('reference')->label(__('admin.procurement.fields.reference'))->searchable()->sortable(),
                TextColumn::make('po_number')
                    ->label(__('admin.procurement.fields.po_number'))
                    ->searchable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('asset.name')->label(__('admin.procurement.fields.asset'))->toggleable(),
                TextColumn::make('total_value')
                    ->label(__('admin.procurement.fields.total_value'))
                    ->money('EGP')->alignRight()->sortable()
                    ->summarize(Sum::make('total')->label(__('admin.reports.totals'))->money('EGP')),
                TextColumn::make('status')
                    ->label(__('admin.procurement.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.procurement.statuses.{$state}"))
                    ->color(fn (string $state) => match ($state) {
                        'received' => 'success',
                        'approved', 'ordered' => 'info',
                        'rejected', 'cancelled' => 'danger',
                        default => 'warning',
                    })
                    // While it waits, say WHO it waits for — otherwise a request sits unseen
                    // because nobody knew it was theirs to action (FR-PROC-02).
                    ->description(function (PurchaseRequest $record): ?string {
                        if ($record->status === PurchaseRequest::STATUS_REQUESTED) {
                            return __('admin.procurement.awaiting', ['tier' => self::tierLabel($record)]);
                        }
                        // Nullable by design — an approved request has no vendor until it is ordered.
                        /** @var Vendor|null $vendor */
                        $vendor = $record->vendor;

                        return $vendor?->name;
                    }),
                TextColumn::make('requestedBy.name')->label(__('admin.procurement.fields.requested_by'))->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label(__('admin.procurement.fields.created_at'))->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.procurement.fields.status'))
                    ->options(fn () => collect(PurchaseRequest::STATUSES)
                        ->mapWithKeys(fn (string $s) => [$s => __("admin.procurement.statuses.{$s}")])->all()),
            ])
            ->recordActions([
                // Read the record without opening its edit form — less
                // friction, and no write surface for view-only roles. The
                // schema is the resource's own form rendered disabled, so it
                // cannot drift from the fields that actually exist.
                ViewAction::make()
                    ->visible(fn ($record) => PurchaseRequestResource::canView($record))
                    ->authorize(fn ($record) => PurchaseRequestResource::canView($record)),

                // authorize(), not just visible(): visible() is a display gate, not a dispatch
                // gate (mountAction checks isDisabled(), never isVisible()), so without this the
                // status/permission gate is bypassable via mountAction('edit', $id). Mirrors the
                // five sibling actions above (D-95).
                // authorize() mirrors visible() to match the five sibling actions above (D-95).
                // In this Filament build visible() already refuses the mount (a hidden table
                // action does not mount), so this is defence-in-depth + consistency rather than a
                // live-exploit fix: it keeps the gate holding if a future Filament decouples mount
                // from visibility (the framework's documented `mountAction` checks isDisabled()).
                // ── The list FINDS; the record ACTS ─────────────────────────────────────
                // Defined once in App\Filament\Admin\Actions\PurchaseRequestActions and composed onto this
                // record's own page, so opening the record is enough to act on it.
                EditAction::make()
                    ->visible(fn (PurchaseRequest $r) => $r->status === PurchaseRequest::STATUS_REQUESTED
                        && PurchaseRequestResource::canEdit($r))
                    ->authorize(fn (PurchaseRequest $r) => $r->status === PurchaseRequest::STATUS_REQUESTED
                        && PurchaseRequestResource::canEdit($r)),

                // "View working" for the total + which approval tier it fell into — an operator
                // approving EGP 50k should see the lines that make the number and why it needs the
                // manager it needs, not trust a bare figure. Native infolist, per convention.
                Action::make('breakdown')
                    ->label(__('admin.procurement.actions.view_working'))
                    ->icon('heroicon-o-calculator')
                    ->color('gray')
                    ->modalSubmitAction(false)
                    ->schema(fn (PurchaseRequest $record) => [
                        TextEntry::make('lines_working')
                            ->label(__('admin.procurement.fields.items'))
                            ->state(function () use ($record) {
                                $rows = $record->lines()->with('item')->get();

                                if ($rows->isEmpty()) {
                                    return '—';
                                }

                                return $rows->map(fn ($l) => sprintf(
                                    '%s · %s × %s = EGP %s',
                                    optional($l->item)->name ?? $l->description ?? '—',
                                    rtrim(rtrim(number_format((float) $l->quantity, 3), '0'), '.'),
                                    number_format((float) $l->unit_cost, 2),
                                    number_format((float) $l->line_value, 2),
                                ))->join("\n");
                            }),
                        TextEntry::make('total_working')
                            ->label(__('admin.procurement.fields.total_value'))
                            ->state(fn () => 'EGP '.number_format((float) $record->total_value, 2)),
                        TextEntry::make('tier_working')
                            ->label(__('admin.procurement.fields.approval_tier'))
                            ->state(fn () => self::tierLabel($record))
                            ->helperText(__('admin.procurement.tier_hint')),
                    ]),

                // The Purchase Order document — the whole point of "ordering". Available once the
                // request has become an order (ordered or received), so there is a PO to render.
                PdfDownloadAction::make('downloadPo')
                    ->label(__('admin.procurement.actions.download_po'))
                    ->service(PurchaseOrderPdfService::class)
                    ->recipient(fn (PurchaseRequest $record) => $record->vendor)
                    ->visible(fn (PurchaseRequest $r) => in_array($r->status, [PurchaseRequest::STATUS_ORDERED, PurchaseRequest::STATUS_RECEIVED], true)),
            ])
            ->defaultSort('id', 'desc')
            ->emptyStateIcon('heroicon-o-shopping-cart')
            ->emptyStateHeading(__('admin.empty.purchase_requests.heading'))
            ->emptyStateDescription(__('admin.empty.purchase_requests.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('admin.empty.purchase_requests.cta'))
                    ->icon('heroicon-o-plus'),
            ]);
    }

    /** The stored tier is a permission (`approvals.tier_1`); a dotted key can never resolve. */
    private static function tierLabel(PurchaseRequest $record): string
    {
        $tier = str_replace('approvals.', '', (string) $record->required_permission);

        if (! in_array($record->required_permission, ApprovalRule::TIERS, true)) {
            $tier = 'unknown';
        }

        return __("admin.procurement.tiers.{$tier}");
    }
}
