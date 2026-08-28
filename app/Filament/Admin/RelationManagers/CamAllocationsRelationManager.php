<?php

namespace App\Filament\Admin\RelationManagers;

use App\Models\CamAllocation;
use App\Models\Tenant;
use App\Services\CamReconciliationService;
use App\Services\CamStatementPdfService;
use App\Support\Filament\PdfDownloadAction;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class CamAllocationsRelationManager extends RelationManager
{
    protected static string $relationship = 'allocations';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.tables.cam.allocations');
    }

    protected static function tenantName(CamAllocation $record): string
    {
        // Both agreement shapes. An allocation belongs to a lease OR a unit ownership, so reading the
        // lease alone titled every owner's allocation modal '—'.
        $tenant = $record->lease?->tenant ?? $record->unitOwnership?->tenant;

        return $tenant instanceof Tenant ? $tenant->name : '—';
    }

    /** Named once so the bill action's visible() (UI) and action() (real gate) can't drift. */
    public static function canBill(CamAllocation $record): bool
    {
        return $record->status === 'pending'
            && (auth()->user()?->can('cam.bill_allocation') ?? false);
    }

    /** Void (un-bill) — the inverse of bill(); same permission domain. */
    public static function canVoid(CamAllocation $record): bool
    {
        return $record->status === 'billed'
            && (auth()->user()?->can('cam.bill_allocation') ?? false);
    }

    /**
     * The billed-allocation notification body, branched on what the service actually did: a recovery
     * invoice (positive true-up), an auto-applied credit (the tenant over-paid the estimate), or a
     * fee-only invoice (estimate matched actual). The old copy said "true-up of EGP :amount added" for
     * all three — which read as "-500 added" for a credit and never mentioned the admin fee.
     */
    public static function billedNotificationBody(CamAllocation $record): string
    {
        $trueUp = (float) $record->true_up_amount;
        $fee = number_format((float) $record->admin_fee_amount, 2);

        if ($trueUp < -0.005) {
            return __('admin.notifications.allocation_billed_credit', [
                'amount' => number_format(abs($trueUp), 2),
                'fee' => $fee,
            ]);
        }

        if ($trueUp > 0.005) {
            return __('admin.notifications.allocation_billed_recovery', [
                'amount' => number_format($trueUp, 2),
                'fee' => $fee,
            ]);
        }

        return __('admin.notifications.allocation_billed_fee_only', ['fee' => $fee]);
    }

    /**
     * The read-only per-allocation "Breakdown" as native Filament infolist entries — every leg of the
     * true-up (pro-rata share → allocated → cap ceiling/capped/absorbed → estimate → true-up → recovery
     * VAT → admin fee + its VAT → net invoiced/credited), so the number is verifiable exactly where a
     * tenant is most likely to dispute it (a cap biting makes the bare table columns stop adding up).
     *
     * @return array<int, TextEntry>
     */
    public static function breakdownSchema(CamAllocation $record): array
    {
        $w = app(CamReconciliationService::class)->explainAllocation($record);
        $money = fn ($v) => 'EGP '.number_format((float) $v, 2);

        $rows = [
            TextEntry::make('bd_share')->label(__('admin.cam_working.share'))->inlineLabel()
                ->state(number_format($w['share_pct'], 4).'%'),
            TextEntry::make('bd_pool_actual')->label(__('admin.cam_working.pool_actual'))->inlineLabel()->state($money($w['pool_actual'])),
            TextEntry::make('bd_allocated')->label(__('admin.cam_working.allocated'))->inlineLabel()->state($money($w['allocated'])),
        ];

        if ($w['cap_applied']) {
            $rows[] = TextEntry::make('bd_cap_ceiling')->label(__('admin.cam_working.cap_ceiling'))->inlineLabel()->state($money($w['cap_ceiling']));
            $rows[] = TextEntry::make('bd_capped_cost')->label(__('admin.cam_working.capped_cost'))->inlineLabel()->state($money($w['capped_cost']));
            $rows[] = TextEntry::make('bd_cap_absorbed')->label(__('admin.cam_working.cap_absorbed'))->inlineLabel()
                ->state($money($w['cap_absorbed']))->color('success');
        }

        $rows[] = TextEntry::make('bd_estimated')->label(__('admin.cam_working.estimated_paid'))->inlineLabel()->state($money($w['estimated_paid']));
        $rows[] = TextEntry::make('bd_true_up')->label(__('admin.cam_working.true_up'))->inlineLabel()
            ->state($money($w['true_up']))->weight(FontWeight::Bold)
            ->color($w['true_up'] > 0 ? 'warning' : ($w['true_up'] < 0 ? 'success' : 'gray'));

        if ($w['recovery_vat'] > 0) {
            $rows[] = TextEntry::make('bd_recovery_vat')
                ->label(__('admin.cam_working.recovery_vat', ['rate' => rtrim(rtrim(number_format($w['recovery_vat_rate'], 2), '0'), '.')]))
                ->inlineLabel()->state($money($w['recovery_vat']));
        }

        if ($w['admin_fee'] > 0) {
            $rows[] = TextEntry::make('bd_admin_fee')->label(__('admin.cam_working.admin_fee'))->inlineLabel()->state($money($w['admin_fee']));
            if ($w['admin_fee_vat'] > 0) {
                $rows[] = TextEntry::make('bd_admin_fee_vat')->label(__('admin.cam_working.admin_fee_vat'))->inlineLabel()->state($money($w['admin_fee_vat']));
            }
        }

        $rows[] = $w['direction'] === 'credit'
            ? TextEntry::make('bd_net')->label(__('admin.cam_working.net_credited'))->inlineLabel()
                ->state($money($w['net_invoiced']))->weight(FontWeight::Bold)->color('success')
            : TextEntry::make('bd_net')->label(__('admin.cam_working.net_invoiced'))->inlineLabel()
                ->state($money($w['net_invoiced']))->weight(FontWeight::Bold)->color('primary');

        return $rows;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['lease.tenant', 'lease.unit', 'pool']))
            ->columns([
                TextColumn::make('lease.tenant.name')
                    ->label(__('admin.tables.cam.tenant'))
                    ->searchable()
                    ->weight('medium'),
                TextColumn::make('lease.unit.code')
                    ->label(__('admin.tables.cam.unit'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('pro_rata_share_pct')
                    ->label(__('admin.tables.cam.share'))
                    ->formatStateUsing(fn ($state) => number_format((float) $state, 2).'%'),
                TextColumn::make('allocated_amount')
                    ->label(__('admin.tables.cam.allocated'))
                    ->money('EGP', divideBy: 1),
                // Cap legs — hidden by default, but present so the true-up reconciles when a cap bites
                // (allocated − absorbed = capped cost; capped cost − estimated = true-up).
                TextColumn::make('capped_cost_amount')
                    ->label(__('admin.tables.cam.capped_cost'))
                    ->money('EGP', divideBy: 1)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cap_absorbed_amount')
                    ->label(__('admin.tables.cam.cap_absorbed'))
                    ->money('EGP', divideBy: 1)
                    ->color('success')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('estimated_paid')
                    ->label(__('admin.tables.cam.estimated_paid'))
                    ->money('EGP', divideBy: 1)
                    ->toggleable(),
                TextColumn::make('true_up_amount')
                    ->label(__('admin.tables.cam.true_up'))
                    ->money('EGP', divideBy: 1)
                    ->weight('semibold')
                    ->color(fn ($state) => $state > 0 ? 'warning' : ($state < 0 ? 'success' : 'gray')),
                TextColumn::make('admin_fee_amount')
                    ->label(__('admin.tables.cam.admin_fee'))
                    ->money('EGP', divideBy: 1)
                    ->toggleable()
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.cam_allocation.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'billed' => 'success',
                        'disputed' => 'danger',
                        'closed' => 'gray',
                        default => 'gray',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.cam_allocation')),
            ])
            ->defaultSort('allocated_amount', 'desc')
            ->emptyStateIcon('heroicon-o-calculator')
            ->emptyStateHeading(__('admin.empty.cam_allocations.heading'))
            ->emptyStateDescription(__('admin.empty.cam_allocations.description'))
            ->recordActions([
                // Read-only working — how this tenant's true-up is derived, every leg (cap + VAT included).
                Action::make('breakdown')
                    ->label(__('admin.actions.view_cam_working'))
                    ->icon('heroicon-o-calculator')
                    ->color('gray')
                    ->modalHeading(fn (CamAllocation $record) => __('admin.actions.view_cam_working_heading', [
                        'tenant' => self::tenantName($record),
                    ]))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('admin.actions.close'))
                    ->schema(fn (CamAllocation $record) => self::breakdownSchema($record)),
                // The statement the tenant can audit against (RC-06). Almost every commercial lease
                // grants a service-charge audit right, and the answer to "show me how you got this"
                // used to be an invoice line reading "CAM Recovery 2028".
                PdfDownloadAction::make('statement')
                    ->label(__('admin.cam_statement.download'))
                    ->icon(Heroicon::OutlinedDocumentArrowDown)
                    ->service(CamStatementPdfService::class)
                    // A CAM allocation belongs to a lease OR a unit ownership, so the party is
                    // resolved the same way the statement's own header resolves it — an owner is a
                    // `tenants` row too, and reads in their own language just as a retailer does.
                    ->recipient(fn (CamAllocation $record) => $record->lease?->tenant ?? $record->unitOwnership?->tenant),
                Action::make('bill')
                    ->label(__('admin.actions.bill_allocation'))
                    ->icon('heroicon-o-banknotes')
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalDescription(__('admin.actions.bill_allocation_confirm'))
                    ->visible(fn (CamAllocation $record) => self::canBill($record))
                    ->action(function (CamAllocation $record): void {
                        // action() is the real gate — mountAction() never checks visible() (a custom
                        // role with cam.edit but not cam.bill_allocation could otherwise bill).
                        abort_unless(self::canBill($record), 403);
                        app(CamReconciliationService::class)->bill($record);
                        Notification::make()
                            ->success()
                            ->title(__('admin.notifications.allocation_billed'))
                            ->body(self::billedNotificationBody($record->refresh()))
                            ->send();
                    }),
                Action::make('void')
                    ->label(__('admin.actions.void_allocation'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalDescription(__('admin.actions.void_allocation_confirm'))
                    ->visible(fn (CamAllocation $record) => self::canVoid($record))
                    ->action(function (CamAllocation $record): void {
                        abort_unless(self::canVoid($record), 403);
                        try {
                            app(CamReconciliationService::class)->voidAllocation($record);
                            Notification::make()
                                ->success()
                                ->title(__('admin.notifications.allocation_voided'))
                                ->send();
                        } catch (\DomainException $e) {
                            // e.g. the recovery invoice was already paid — refund it first.
                            Notification::make()->danger()->title($e->getMessage())->send();
                        }
                    }),
            ]);
    }
}
