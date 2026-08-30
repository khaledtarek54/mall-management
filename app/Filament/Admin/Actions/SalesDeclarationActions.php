<?php

declare(strict_types=1);

namespace App\Filament\Admin\Actions;

use App\Models\Lease;
use App\Models\TenantSalesDeclaration;
use App\Services\PercentageRentCalculationService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontWeight;
use Illuminate\Support\Carbon;

/**
 * The four acts on a sales declaration, in ONE place — lock, dispute, void, and read the working.
 *
 * They lived inside `TenantSalesDeclarationsTable` and were therefore reachable only from the LIST.
 * An operator who opened a declaration to check its figures had to go back to the list to lock it,
 * and the edit page offered Delete and nothing else — the same gap the lease's Invoices tab had,
 * and the reason `LeaseActions` exists as a class rather than as a block inside a table.
 *
 * Extracted rather than copied: `lock()` raises an invoice, `voidLocked()` reverses one and refuses
 * if it has been paid. Two definitions of either is two answers to the same question, and the one
 * nobody is looking at is the one that goes stale.
 */
class SalesDeclarationActions
{
    /** Every act, in the order an operator meets them. */
    public static function all(): array
    {
        return [
            Action::make('lock')
                ->label(__('admin.actions.lock_declaration'))
                ->icon('heroicon-o-lock-closed')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(__('admin.actions.lock_declaration_confirm'))
                ->schema([
                    Textarea::make('audit_notes')
                        ->label(__('admin.fields.audit_notes'))
                        ->rows(3),
                ])
                ->visible(fn (TenantSalesDeclaration $record) => self::canLock($record))
                ->action(function (TenantSalesDeclaration $record, array $data): void {
                    abort_unless(self::canLock($record), 403);
                    app(PercentageRentCalculationService::class)->lock(
                        $record,
                        auth()->user(),
                        $data['audit_notes'] ?? null,
                    );

                    // Annual: show how the whole year now sits (re-truing can move the charge onto a
                    // different month). Monthly: just this month's figure.
                    $record = $record->fresh();
                    Notification::make()
                        ->success()
                        ->title(__('admin.notifications.declaration_locked'))
                        ->body(self::annualYearSummary($record) ?? __('admin.notifications.declaration_locked_body', [
                            'amount' => number_format((float) $record->calculated_percentage_rent, 2),
                        ]))
                        ->send();
                }),
            Action::make('dispute')
                ->label(__('admin.actions.dispute_declaration'))
                ->icon('heroicon-o-exclamation-triangle')
                ->color('danger')
                ->requiresConfirmation()
                ->schema([
                    Textarea::make('audit_notes')
                        ->label(__('admin.fields.audit_notes'))
                        ->required()
                        ->rows(3),
                ])
                ->visible(fn (TenantSalesDeclaration $record) => self::canDispute($record))
                ->action(function (TenantSalesDeclaration $record, array $data): void {
                    abort_unless(self::canDispute($record), 403);
                    $record->update([
                        'status' => 'disputed',
                        'audit_notes' => $data['audit_notes'],
                    ]);
                    Notification::make()->warning()->title(__('admin.notifications.declaration_disputed'))->send();
                }),
            // Void a previously-locked declaration if it turns out to be
            // wrong post-lock. Deactivates the percentage_rent Charge so
            // the next monthly billing run skips it; sets status to
            // disputed; stamps audit_notes with the reason + operator
            // (audit M12 F-48 / D-36).
            Action::make('voidLocked')
                ->label(__('admin.actions.void_locked_declaration'))
                ->icon('heroicon-o-no-symbol')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading(__('admin.actions.void_locked_modal_heading'))
                ->modalDescription(__('admin.actions.void_locked_modal_description'))
                ->schema([
                    Textarea::make('reason')
                        ->label(__('admin.fields.void_reason'))
                        ->required()
                        ->rows(3)
                        ->placeholder(__('admin.actions.void_locked_reason_placeholder')),
                ])
                ->visible(fn (TenantSalesDeclaration $record) => self::canVoid($record))
                ->action(function (TenantSalesDeclaration $record, array $data): void {
                    abort_unless(self::canVoid($record), 403);
                    try {
                        app(PercentageRentCalculationService::class)
                            ->voidLocked($record, auth()->user(), $data['reason']);
                    } catch (\DomainException $e) {
                        // The overage invoice was already PAID — VoidInvoiceService refuses,
                        // the whole void transaction rolls back. Tell the operator to refund first.
                        Notification::make()
                            ->danger()
                            ->title(__('admin.notifications.declaration_void_blocked'))
                            ->body($e->getMessage())
                            ->persistent()
                            ->send();

                        return;
                    }

                    // Annual: the void re-trued the year — show the operator how it now sits (a
                    // survivor month's charge may have changed). Monthly: no body (as before).
                    Notification::make()
                        ->warning()
                        ->title(__('admin.notifications.declaration_voided'))
                        ->body(self::annualYearSummary($record->fresh()))
                        ->send();
                }),
            // Read-only breakdown so an operator can SEE how the figure is derived — essential for
            // annual leases, where a single month's charge is a share of a running yearly total.
            // Native Filament infolist entries (no custom markup) so it matches the design system.
            Action::make('working')
                ->label(__('admin.actions.view_sales_working'))
                ->icon('heroicon-o-calculator')
                ->color('gray')
                ->modalHeading(fn (TenantSalesDeclaration $record) => __('admin.actions.view_sales_working_heading', [
                    'period' => $record->periodLabel(),
                ]))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel(__('admin.actions.close'))
                ->schema(fn (TenantSalesDeclaration $record) => self::workingSchema($record))
                ->visible(fn (TenantSalesDeclaration $record) => self::hasPercentageRent($record)),
        ];
    }

    /**
     * For an ANNUAL lease, a plain-language summary of how the year's percentage rent now sits after a
     * lock/void re-trued it — which months carry what + the year total — so the re-attribution (locking
     * one month can shift the charge onto another; voiding re-trues the survivors) is visible to the
     * operator instead of silent. Returns null for a monthly lease (nothing to summarise).
     */
    public static function annualYearSummary(TenantSalesDeclaration $record): ?string
    {
        if (! self::isAnnualLease($record)) {
            return null;
        }

        $attr = app(PercentageRentCalculationService::class)->yearAttribution(
            $record->lease_id,
            Carbon::parse($record->period_start)->year,
        );

        $breakdown = collect($attr['months'])
            ->filter(fn ($m) => $m['share'] > 0)
            ->map(fn ($m) => $m['period'].': EGP '.number_format($m['share'], 2))
            ->implode(' · ');

        return __('admin.notifications.declaration_annual_summary', [
            'year' => $attr['year'],
            'total' => number_format($attr['total'], 2),
            'breakdown' => $breakdown !== '' ? $breakdown : __('admin.notifications.declaration_annual_none'),
        ]);
    }

    public static function canDispute(TenantSalesDeclaration $record): bool
    {
        return $record->status === 'submitted' && (auth()->user()?->can('tenant_sales.dispute') ?? false);
    }

    /**
     * The predicate for each write action, named ONCE so visible() (the UI) and action() (the real
     * gate) can't drift. Filament's mountAction() never checks isVisible(), so a hidden action is
     * still dispatchable by a crafted Livewire call — every write must re-assert in action()
     * (abort_unless). `viewer` + `owner` hold tenant_sales.view (the list renders) but not the action
     * perms; without the action-side gate they could Lock (bill an overage invoice + post GL),
     * Dispute, or Void a declaration via mountAction. The permission AND the status are re-checked.
     */
    public static function canLock(TenantSalesDeclaration $record): bool
    {
        return $record->status === 'submitted' && (auth()->user()?->can('tenant_sales.lock') ?? false);
    }

    public static function canVoid(TenantSalesDeclaration $record): bool
    {
        return $record->status === 'locked' && (auth()->user()?->can('tenant_sales.lock') ?? false);
    }

    public static function hasPercentageRent(TenantSalesDeclaration $record): bool
    {
        $lease = $record->lease;

        return $lease instanceof Lease && (bool) $lease->has_percentage_rent;
    }

    /**
     * The read-only "View working" modal body as native Filament infolist entries (no custom markup,
     * so it matches the design system). Shows how this declaration's percentage rent is derived — and
     * for an annual lease, the cumulative year-to-date working that makes a single month's share
     * explicable — from the service's plain-language `explain()`.
     *
     * @return array<int, TextEntry>
     */
    public static function workingSchema(TenantSalesDeclaration $record): array
    {
        $w = app(PercentageRentCalculationService::class)->explain($record);

        if (! ($w['applicable'] ?? false)) {
            return [TextEntry::make('working_na')->hiddenLabel()->state(__('admin.sales_working.not_applicable'))];
        }

        $money = fn ($v) => 'EGP '.number_format((float) $v, 2);
        $annual = ($w['frequency'] ?? null) === 'annual';

        $entries = [
            TextEntry::make('working_frequency')->label(__('admin.fields.percentage_rent_frequency'))->inlineLabel()
                ->state(__('admin.enums.percentage_rent_frequency')[$w['frequency']] ?? $w['frequency'])
                ->badge()->color($annual ? 'info' : 'gray'),
            TextEntry::make('working_method')->label(__('admin.fields.percentage_rent_calculation_type'))->inlineLabel()
                ->state(__('admin.enums.percentage_rent_calculation_type')[$w['method']] ?? $w['method']),
            TextEntry::make('working_rate')->label(__('admin.sales_working.rate'))->inlineLabel()
                ->state(rtrim(rtrim(number_format((float) $w['rate'], 2), '0'), '.').'%'),
        ];

        if ($annual) {
            $entries[] = TextEntry::make('working_declared')->label(__('admin.sales_working.declared_this_month'))->inlineLabel()->state($money($w['declared_sales']));
            $entries[] = TextEntry::make('working_prior')->label(__('admin.sales_working.prior_ytd_sales'))->inlineLabel()->state($money($w['prior_ytd_sales']));
            $entries[] = TextEntry::make('working_cumulative')->label(__('admin.sales_working.cumulative_ytd_sales'))->inlineLabel()->state($money($w['cumulative_ytd_sales']))->weight(FontWeight::Bold);
            $entries[] = TextEntry::make('working_breakpoint')->label(__('admin.sales_working.annual_breakpoint'))->inlineLabel()->state($money($w['breakpoint']));
            $entries[] = TextEntry::make('working_ytd_overage')->label(__('admin.sales_working.ytd_overage'))->inlineLabel()->state($money($w['ytd_overage']));
            $entries[] = TextEntry::make('working_share')->label(__('admin.sales_working.this_month_share'))->inlineLabel()->state($money($w['this_period_share']))->weight(FontWeight::Bold)->color('primary');
            $entries[] = TextEntry::make('working_note')->hiddenLabel()->state(__('admin.sales_working.annual_note'))->color('gray');
        } else {
            $entries[] = TextEntry::make('working_declared')->label(__('admin.sales_working.declared_sales'))->inlineLabel()->state($money($w['declared_sales']));
            $entries[] = TextEntry::make('working_breakpoint')->label(__('admin.sales_working.monthly_breakpoint'))->inlineLabel()->state($money($w['breakpoint']));
            $entries[] = TextEntry::make('working_overage')->label(__('admin.sales_working.this_month_overage'))->inlineLabel()->state($money($w['this_period_share']))->weight(FontWeight::Bold)->color('primary');
        }

        if ($w['is_estimate'] ?? false) {
            $entries[] = TextEntry::make('working_estimate')->hiddenLabel()->state(__('admin.sales_working.estimate_note'))->color('warning');
        }

        return $entries;
    }

    public static function isAnnualLease(TenantSalesDeclaration $record): bool
    {
        $lease = $record->lease;

        return $lease instanceof Lease && $lease->percentage_rent_frequency === 'annual';
    }
}
