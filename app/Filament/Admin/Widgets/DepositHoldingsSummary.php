<?php

namespace App\Filament\Admin\Widgets;

use App\Support\DepositHoldings;
use App\Support\TenantScope;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * What the operator actually holds in security deposits — above the deposit register.
 *
 * The register lists `deposit_transactions`, and that table stopped being the whole story when
 * `BillSecurityDepositService` arrived: a deposit billed on an invoice and paid by the tenant is
 * held, is owed back, and writes no movement row. So the register's own `Sum` footer read
 * 390,000 while the `deposits_held` liability stood at 534,000, and the screen an operator opens
 * to answer "what do we owe back?" was understating it by every deposit collected the new way.
 *
 * This states both roads and then checks itself against the ledger. The tie-out is the point: the
 * derived figure and the GL are computed from different places — one from movements and invoice
 * settlements, the other from posted journal lines — so agreement is evidence, and a gap is the
 * first thing anybody would want to know about a liability account.
 *
 * @see DepositHoldings the one definition, shared with `billing:reconcile`
 */
class DepositHoldingsSummary extends StatsOverviewWidget
{
    protected static ?int $sort = 0;

    protected function getStats(): array
    {
        // The register is property-scoped, so its totals must be too — otherwise the summary above
        // the table describes a different population from the table itself.
        $assetIds = TenantScope::visibleAssetIds();

        $recorded = DepositHoldings::recorded($assetIds);
        $billed = DepositHoldings::billedAndSettled($assetIds);
        $held = DepositHoldings::held($assetIds);
        $gl = DepositHoldings::glBalance($assetIds);

        $money = fn (float $v) => 'EGP '.number_format($v, 2);

        // Null means the `deposits_held` role is unmapped for these properties — nothing to compare
        // against, which is not the same as a discrepancy and must not be drawn as one.
        $gap = $gl === null ? null : round($held - $gl, 2);
        $ties = $gap !== null && abs($gap) < 0.01;

        return [
            Stat::make(__('admin.deposits.held_total'), $money($held))
                ->description(__('admin.deposits.held_total_hint'))
                ->descriptionIcon('heroicon-m-shield-check')
                ->color('success'),

            Stat::make(__('admin.deposits.held_recorded'), $money($recorded))
                ->description(__('admin.deposits.held_recorded_hint'))
                ->color('gray'),

            // The figure the register cannot show, named as such — the whole reason this widget
            // exists rather than a bigger number with no explanation.
            Stat::make(__('admin.deposits.held_billed'), $money($billed))
                ->description(__('admin.deposits.held_billed_hint'))
                ->descriptionIcon('heroicon-m-document-text')
                ->color($billed > 0 ? 'info' : 'gray'),

            Stat::make(
                __('admin.deposits.held_gl'),
                $gl === null ? '—' : $money($gl),
            )
                ->description($gl === null
                    ? __('admin.deposits.held_gl_unmapped')
                    : ($ties
                        ? __('admin.deposits.held_gl_ties')
                        : __('admin.deposits.held_gl_gap', ['amount' => $money((float) $gap)])))
                ->descriptionIcon($ties ? 'heroicon-m-check-circle' : 'heroicon-m-exclamation-triangle')
                ->color(match (true) {
                    $gl === null => 'gray',
                    $ties => 'success',
                    default => 'danger',
                }),
        ];
    }
}
