<?php

namespace App\Filament\Admin\Resources\FacilityWorkOrders\Tables;

use App\Filament\Admin\Resources\FacilityWorkOrders\FacilityWorkOrderResource;
use App\Filament\Admin\Resources\FacilityWorkOrders\Schemas\CorrectiveWorkOrderForm;
use App\Models\FacilityWorkOrder;
use App\Models\Trade;
use App\Models\VendorBill;
use App\Services\ApplySlaPenaltyService;
use App\Services\AssessSlaPenaltyService;
use App\Services\AttributeWorkOrderFaultService;
use App\Services\FacilityWorkOrderService;
use App\Services\RaiseCorrectiveWorkOrderService;
use App\Support\Filament\EntitySelect;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;

class FacilityWorkOrdersTable
{
    private static function canComplete(): bool
    {
        return auth()->user()?->can('facility.complete') ?? false;
    }

    private static function canCreate(): bool
    {
        return auth()->user()?->can('facility.create') ?? false;
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['asset', 'unit', 'equipment', 'trade', 'parentWorkOrder', 'sourceItem', 'penalty']))
            ->columns([
                TextColumn::make('reference')
                    ->label(__('admin.facility.fields.reference'))
                    ->fontFamily('mono')
                    // FR-CM-15 — the chain is visible from the list, not buried on the edit
                    // page: "why does this job exist?" is the first question about a CM.
                    ->description(fn (FacilityWorkOrder $record) => $record->parentWorkOrder
                        ? __('admin.facility.cm.follow_up_of').' '.$record->parentWorkOrder->reference
                        : ($record->sourceItem ? __('admin.facility.cm.from_check').': '.$record->sourceItem->label : null))
                    ->searchable(),
                TextColumn::make('work_order_type')
                    ->label(__('admin.facility.fields.work_order_type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.facility.work_order_types.{$state}"))
                    ->color(fn (string $state) => $state === FacilityWorkOrder::TYPE_CM ? 'warning' : 'gray')
                    ->description(fn (FacilityWorkOrder $record) => $record->execution_type
                        ? __("admin.facility.execution_types.{$record->execution_type}")
                        : null),
                TextColumn::make('title')
                    ->label(__('admin.facility.fields.title'))
                    ->weight('bold')
                    ->description(fn (FacilityWorkOrder $record) => $record->trade?->label() ?? '—')
                    ->searchable(),
                TextColumn::make('asset.name')
                    ->label(__('admin.facility.fields.property'))
                    ->badge()->color('gray')->toggleable(),
                // The location for an area-based job, so the technician knows WHERE.
                TextColumn::make('area.name')
                    ->label(__('admin.facility.fields.area'))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('equipment.code')
                    ->label(__('admin.facility.equipment.singular'))
                    ->fontFamily('mono')
                    ->description(fn (FacilityWorkOrder $record) => $record->equipment?->name_en)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('scheduled_for')
                    ->label(__('admin.facility.fields.scheduled_for'))
                    ->date('d/m/Y')
                    ->sortable()
                    ->color(fn ($state, FacilityWorkOrder $record) => ! $record->isTerminal() && $state && $state->isPast() ? 'danger' : null),
                TextColumn::make('progress')
                    ->label(__('admin.facility.fields.progress'))
                    ->state(fn (FacilityWorkOrder $record) => ($record->marked_items_count ?? 0).' / '.($record->items_count ?? 0))
                    ->badge()
                    // Amber once a check has failed — the visit is progressing but the
                    // order will need corrective follow-up. Green = all marked, none failed.
                    ->color(function (FacilityWorkOrder $record): string {
                        if (($record->failed_items_count ?? 0) > 0) {
                            return 'warning';
                        }

                        return ($record->items_count ?? 0) > 0 && ($record->marked_items_count ?? 0) >= $record->items_count
                            ? 'success'
                            : 'gray';
                    }),
                TextColumn::make('priority')
                    ->label(__('admin.facility.fields.priority'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.facility.priorities.{$state}"))
                    ->color(fn (string $state) => match ($state) {
                        'urgent' => 'danger',
                        'high' => 'warning',
                        'low' => 'gray',
                        default => 'info',
                    })
                    ->toggleable(),
                // The clock nobody could see. An order sitting unaccepted had no resolution
                // deadline at all, so the column beside this one was blank and the job read as
                // fine — which is exactly what a job nobody has looked at looks like.
                TextColumn::make('target_response_at')
                    ->label(__('admin.facility.sla.response_target'))
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    ->color(fn (FacilityWorkOrder $record) => $record->isResponseBreached() ? 'danger' : null)
                    ->description(fn (FacilityWorkOrder $record) => $record->isResponseBreached()
                        ? __('admin.facility.sla.unanswered').' · '.$record->hoursOverResponseSla().'h'
                        : null)
                    // Sortable AND shown by default, because the dashboard's "nobody has
                    // responded" card links here with `sort=target_response_at:asc`. A
                    // non-sortable column makes Filament drop the sort silently
                    // (`getSortableVisibleColumn()` returns null), and hiding it by default
                    // landed the operator on a triage list with the deadline column absent —
                    // re-creating the exact blindness the comment above describes.
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('target_resolution_at')
                    ->label(__('admin.facility.sla.target'))
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—')
                    // Red once the deadline has passed and the job is still open — the
                    // whole point of the clock is that it is visible before someone asks.
                    ->color(fn (FacilityWorkOrder $record) => $record->isOverdue() ? 'danger' : null)
                    ->description(fn (FacilityWorkOrder $record) => $record->isOverdue()
                        ? __('admin.facility.sla.overdue').' · '.$record->hoursOverSla().'h'
                        : null)
                    // Same reason: the breached-SLA card sorts on this column.
                    ->sortable()
                    ->toggleable(),
                TextColumn::make('penalty.amount')
                    ->label(__('admin.facility.penalty.label'))
                    ->money('EGP')
                    ->placeholder('—')
                    ->description(fn (FacilityWorkOrder $record) => $record->penalty
                        ? __("admin.facility.penalty.statuses.{$record->penalty->status}")
                        : null)
                    // Grey once waived: the number stays visible for audit, but it is not owed.
                    ->color(fn (FacilityWorkOrder $record) => match ($record->penalty?->status) {
                        'final' => 'danger',
                        'pending' => 'warning',
                        'waived' => 'gray',
                        default => null,
                    })
                    ->toggleable(),
                // FR-CM-13 — the answer the operator actually wants out of this: which jobs are
                // the tenants' fault, and which are ours.
                TextColumn::make('cost_bearer')
                    ->label(__('admin.facility.fault.column'))
                    ->badge()
                    ->placeholder(__('admin.facility.fault.not_attributed'))
                    ->formatStateUsing(fn (?string $state) => $state === null ? null : __("admin.facility.fault.bearers.{$state}"))
                    ->color(fn (?string $state) => $state === FacilityWorkOrder::BEARER_TENANT ? 'warning' : 'gray')
                    ->description(fn (FacilityWorkOrder $record) => $record->fault_party === null ? null
                        : __("admin.facility.fault.parties.{$record->fault_party}"))
                    ->toggleable(),
                TextColumn::make('status')
                    ->label(__('admin.facility.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.facility.statuses.$state"))
                    ->color(fn (string $state) => match ($state) {
                        'done' => 'success',
                        'in_progress' => 'warning',
                        'cancelled' => 'gray',
                        default => 'info',
                    }),
                // What the job actually cost — the whole point of the cost object. Toggleable
                // rather than always-on: a coordinator triaging today's faults is not costing
                // them, and a column nobody reads on that screen is noise.
                TextColumn::make('act_total_cost')
                    ->label(__('admin.facility.fields.act_total_cost'))
                    ->money('EGP')
                    ->sortable()
                    ->summarize(Sum::make()->money('EGP')->label(__('admin.facility.fields.act_total_cost')))
                    ->toggleable(isToggledHiddenByDefault: true),

                // Planned minus actual. Red when the job ran over what was expected, which is the
                // finding — a bare "14 hours" is a number nobody can act on.
                TextColumn::make('cost_variance')
                    ->label(__('admin.facility.fields.cost_variance'))
                    ->money('EGP')
                    ->state(fn (FacilityWorkOrder $r): ?float => $r->costVariance())
                    ->placeholder(__('admin.facility.not_estimated'))
                    ->color(fn (FacilityWorkOrder $r): ?string => match (true) {
                        $r->costVariance() === null => null,
                        $r->costVariance() < 0 => 'danger',
                        default => 'success',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

            ])
            ->filters([
                // Preventive and corrective share a list; an engineer looking for faults
                // should not have to read past every scheduled visit to find them.
                // The trade is the routing spine and the axis every maintenance-spend report
                // groups by — and it left the search blob when it stopped being a column on the
                // row (the blob is a pure function of a row's OWN attributes, so reaching through
                // to the trade's name would strand every blob the day a trade is renamed). A
                // 14-value dimension belongs in a filter anyway: search finds A RECORD, a filter
                // narrows A SET.
                SelectFilter::make('trade_id')
                    ->label(__('admin.facility.fields.trade'))
                    ->options(fn () => Trade::options(activeOnly: false)),

                SelectFilter::make('work_order_type')
                    ->label(__('admin.facility.fields.work_order_type'))
                    ->options(fn () => __('admin.facility.work_order_types')),
                SelectFilter::make('execution_type')
                    ->label(__('admin.facility.fields.execution_type'))
                    ->options(fn () => __('admin.facility.execution_types')),
                SelectFilter::make('status')
                    ->label(__('admin.facility.fields.status'))
                    ->options(fn () => __('admin.facility.statuses')),
                SelectFilter::make('priority')
                    ->label(__('admin.facility.fields.priority'))
                    ->options(fn () => __('admin.facility.priorities')),
                Filter::make('sla_breached')
                    ->label(__('admin.facility.sla.breached_filter'))
                    ->query(fn ($query) => $query
                        ->whereNotNull('target_resolution_at')
                        ->where('target_resolution_at', '<', now())
                        ->whereNotIn('status', FacilityWorkOrder::TERMINAL)),
                // Reuses the model scope the scan and the dashboard read, so the list, the count
                // and the nightly alert can never disagree about who is unanswered.
                Filter::make('response_breached')
                    ->label(__('admin.facility.sla.response_breached_filter'))
                    ->query(fn ($query) => $query->responseBreached()),
            ])
            // The dispatcher's two axes: what state the board is in, and what is urgent.
            ->groups([
                Group::make('status')->label(__('admin.filters.status'))->collapsible(),
                Group::make('priority')->label(__('admin.facility.fields.priority'))->collapsible(),
            ])
            ->recordActions([
                // Read the record without opening its edit form — less
                // friction, and no write surface for view-only roles. The
                // schema is the resource's own form rendered disabled, so it
                // cannot drift from the fields that actually exist.
                ViewAction::make()
                    ->visible(fn ($record) => FacilityWorkOrderResource::canView($record))
                    ->authorize(fn ($record) => FacilityWorkOrderResource::canView($record)),
                Action::make('start')
                    ->label(__('admin.facility.actions.start'))
                    ->icon('heroicon-o-play')
                    ->color('warning')
                    ->visible(fn (FacilityWorkOrder $record) => $record->status === 'open' && self::canComplete())
                    ->authorize(fn () => self::canComplete())
                    // authorize() can't see the record, so re-check the permission AND
                    // the record's state server-side; the service owns the transition rules.
                    ->action(function (FacilityWorkOrder $record): void {
                        abort_unless(self::canComplete(), 403);
                        app(FacilityWorkOrderService::class)->transition($record, 'in_progress');
                    }),
                Action::make('complete')
                    ->label(__('admin.facility.actions.complete'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->requiresConfirmation()
                    ->visible(fn (FacilityWorkOrder $record) => ! $record->isTerminal() && self::canComplete())
                    ->authorize(fn () => self::canComplete())
                    ->action(function (FacilityWorkOrder $record): void {
                        abort_unless(self::canComplete(), 403);

                        try {
                            app(FacilityWorkOrderService::class)->transition($record, 'done');
                        } catch (\DomainException $e) {
                            // FR-PPM-07: unmarked checklist items block closure. A refusal
                            // is an expected outcome, not a fault — show it, don't 500.
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title(__('admin.facility.completed_notice'))->success()->send();
                    }),
                Action::make('cancel')
                    ->label(__('admin.facility.actions.cancel'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (FacilityWorkOrder $record) => ! $record->isTerminal() && FacilityWorkOrderResource::canEdit($record))
                    ->authorize(fn (FacilityWorkOrder $record) => FacilityWorkOrderResource::canEdit($record))
                    ->action(function (FacilityWorkOrder $record): void {
                        abort_unless(FacilityWorkOrderResource::canEdit($record), 403);
                        app(FacilityWorkOrderService::class)->transition($record, 'cancelled');
                        Notification::make()->title(__('admin.facility.cancelled_notice'))->success()->send();
                    }),
                // FR-CM-14/15 — the external company closed it but the work isn't done.
                // Deliberately available ON A TERMINAL order: that is the point. The client
                // wants a NEW linked job rather than reopening, so the original's SLA and
                // closure record survive for audit — which also keeps the project's
                // terminal-immutability rule intact instead of bending it.
                Action::make('follow_up')
                    ->label(__('admin.facility.cm.follow_up'))
                    ->icon('heroicon-o-arrow-uturn-right')
                    ->color('warning')
                    ->modalDescription(__('admin.facility.cm.follow_up_hint'))
                    ->visible(fn (FacilityWorkOrder $record) => $record->isTerminal() && self::canCreate())
                    ->authorize(fn () => self::canCreate())
                    ->schema(fn (FacilityWorkOrder $record) => CorrectiveWorkOrderForm::fields($record->asset_id))
                    ->action(function (FacilityWorkOrder $record, array $data): void {
                        abort_unless(self::canCreate(), 403);

                        $followUp = app(RaiseCorrectiveWorkOrderService::class)->asFollowUp($record, $data);

                        Notification::make()
                            ->title(__('admin.facility.cm.raised_notice'))
                            ->body($followUp->reference)
                            ->success()
                            ->send();
                    }),
                // FR-CM-08 (money) — deduct the penalty from what the vendor is owed.
                // Only offers bills that can actually absorb it: same vendor, postable, and
                // a balance at least the penalty, so the service's guards are a backstop
                // rather than the way the user finds out.
                // FR-CM-12/13 — rule on the cause, and thereby on who bears the cost.
                //
                // Available on a DONE order on purpose: the cause is usually only known once the
                // machine is open, and FR-CM-12 wants it "as recorded on the work order". Terminal
                // immutability protects the record of the WORK; this is the commercial finding
                // about it, and refusing it after closure would mean it could never be recorded.
                // Not available on a cancelled job — that work never happened, so there is no cost.
                Action::make('attribute_fault')
                    ->label(__('admin.facility.fault.action'))
                    ->icon('heroicon-o-scale')
                    ->color('warning')
                    ->modalDescription(__('admin.facility.fault.hint'))
                    ->visible(fn (FacilityWorkOrder $record) => $record->status !== 'cancelled'
                        && (auth()->user()?->can(AttributeWorkOrderFaultService::PERMISSION) ?? false))
                    ->authorize(fn () => auth()->user()?->can(AttributeWorkOrderFaultService::PERMISSION) ?? false)
                    ->fillForm(fn (FacilityWorkOrder $record) => [
                        'fault_party' => $record->fault_party,
                        'fault_notes' => $record->fault_notes,
                    ])
                    ->schema([
                        Select::make('fault_party')
                            ->label(__('admin.facility.fault.party'))
                            ->options(fn () => collect(FacilityWorkOrder::FAULT_PARTIES)
                                ->mapWithKeys(fn (string $p) => [$p => __("admin.facility.fault.parties.{$p}")])
                                ->all())
                            ->required()
                            ->native(false)
                            ->live()
                            // FR-CM-13 in the open: the bearer follows the cause, so show the
                            // consequence before it is committed rather than after.
                            ->helperText(fn (?string $state) => $state === null ? null : __('admin.facility.fault.derives', [
                                'bearer' => __('admin.facility.fault.bearers.'.FacilityWorkOrder::bearerFor($state)),
                            ])),
                        Textarea::make('fault_notes')
                            ->label(__('admin.facility.fault.notes'))
                            ->helperText(__('admin.facility.fault.notes_hint'))
                            ->rows(2),
                    ])
                    ->action(function (FacilityWorkOrder $record, array $data): void {
                        try {
                            $updated = app(AttributeWorkOrderFaultService::class)
                                ->attribute($record, $data['fault_party'], $data['fault_notes'] ?? null);
                        } catch (\DomainException|\InvalidArgumentException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()
                            ->title(__('admin.facility.fault.recorded_notice'))
                            ->body(__('admin.facility.fault.derives', [
                                'bearer' => __('admin.facility.fault.bearers.'.$updated->cost_bearer),
                            ]))
                            ->success()
                            ->send();
                    }),
                Action::make('charge_penalty')
                    ->label(__('admin.facility.penalty.charge'))
                    ->icon('heroicon-o-banknotes')
                    ->color('danger')
                    ->modalDescription(__('admin.facility.penalty.charge_hint'))
                    ->visible(fn (FacilityWorkOrder $record) => $record->penalty?->isChargeable() === true
                        && FacilityWorkOrderResource::canEdit($record))
                    ->authorize(fn (FacilityWorkOrder $record) => FacilityWorkOrderResource::canEdit($record))
                    ->schema(fn (FacilityWorkOrder $record) => [
                        EntitySelect::make('vendor_bill_id')
                            ->label(__('admin.facility.penalty.bill'))
                            ->entity(VendorBill::class)
                            // Scoped to the work order's OWN property, not just the vendor.
                            // A vendor serves several malls, so without this the dropdown
                            // both leaked other properties' bill numbers + balances to a
                            // user scoped to this one, and let a penalty earned here be
                            // charged there (ApplySlaPenaltyService re-checks server-side —
                            // this list is UX, that is the gate).
                            ->modifyOptionsQuery(fn ($query) => $query
                                ->where('vendor_id', $record->vendor_id)
                                ->where('asset_id', $record->asset_id)
                                ->whereNotIn('status', ['draft', 'cancelled'])
                                ->where('balance', '>=', $record->penalty?->amount ?? 0))
                            ->placeholder(__('admin.facility.penalty.no_eligible_bill'))
                            ->required(),
                    ])
                    ->action(function (FacilityWorkOrder $record, array $data): void {
                        abort_unless(FacilityWorkOrderResource::canEdit($record), 403);

                        $bill = VendorBill::find($data['vendor_bill_id']);

                        if ($record->penalty === null || $bill === null) {
                            return;
                        }

                        try {
                            app(ApplySlaPenaltyService::class)->toBill($record->penalty, $bill);
                        } catch (\DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title(__('admin.facility.penalty.charged_notice'))->success()->send();
                    }),
                // FR-CM-08 — an operator decides not to charge it (the vendor called ahead,
                // the part was on back-order, the breach was the mall's fault). Deliberately
                // available on a TERMINAL order: the penalty outlives the job, and the
                // decision is usually made after the fact.
                Action::make('waive_penalty')
                    ->label(__('admin.facility.penalty.waive'))
                    ->icon('heroicon-o-receipt-refund')
                    ->color('gray')
                    ->modalDescription(__('admin.facility.penalty.waive_hint'))
                    ->visible(fn (FacilityWorkOrder $record) => $record->penalty !== null
                        && ! $record->penalty->isWaived()
                        && FacilityWorkOrderResource::canEdit($record))
                    ->authorize(fn (FacilityWorkOrder $record) => FacilityWorkOrderResource::canEdit($record))
                    ->schema([
                        Textarea::make('waive_reason')
                            ->label(__('admin.facility.penalty.waive_reason'))
                            ->required()
                            ->rows(3),
                    ])
                    ->action(function (FacilityWorkOrder $record, array $data): void {
                        abort_unless(FacilityWorkOrderResource::canEdit($record), 403);

                        if ($record->penalty === null) {
                            return;
                        }

                        try {
                            app(AssessSlaPenaltyService::class)->waive($record->penalty, $data['waive_reason']);
                        } catch (\DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        Notification::make()->title(__('admin.facility.penalty.waived_notice'))->success()->send();
                    }),
                EditAction::make()->visible(fn (FacilityWorkOrder $record) => ! $record->isTerminal() && FacilityWorkOrderResource::canEdit($record)),
            ])
            ->defaultSort('scheduled_for', 'desc')
            ->emptyStateIcon('heroicon-o-clipboard-document-list')
            ->emptyStateHeading(__('admin.empty.facility_work_orders.heading'))
            ->emptyStateDescription(__('admin.empty.facility_work_orders.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('admin.empty.facility_work_orders.cta'))
                    ->icon('heroicon-o-plus'),
            ]);
    }
}
