<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Concerns\RoleScopedWidget;
use App\Filament\Admin\Resources\MarketingBudgets\MarketingBudgetResource;
use App\Models\MarketingBudget;
use App\Support\TenantScope;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * This year's marketing fund: what the levy accrued, what has been spent, what is left.
 *
 * The marketing role had **no dashboard at all** — it logged in to a blank page. The fund is
 * money the operator collects from every tenant (5% of base rent) and answers to the owner for,
 * so "how much of it is left" is the one number the job turns on. Over-spend is shown as a
 * negative balance in red rather than hidden behind a clamp, because a fund that is already
 * over-committed is precisely what marketing needs to see on login.
 */
class MarketingPerformance extends StatsOverviewWidget
{
    use RoleScopedWidget;

    protected static function widgetModule(): ?string
    {
        return 'marketing';
    }

    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $year = (int) CarbonImmutable::now()->year;

        // Property-scoped like every other widget: a marketing user assigned to one mall sees
        // that mall's fund, not the portfolio's.
        $budgets = TenantScope::applyTo(MarketingBudget::query())
            ->where('period_year', $year)
            ->get();

        $accrued = (float) $budgets->sum('accrued_amount');
        $spent = (float) $budgets->sum('spent_amount');
        $balance = $accrued - $spent;
        $usedPct = $accrued > 0 ? round(($spent / $accrued) * 100, 1) : 0.0;

        $url = MarketingBudgetResource::getUrl('index');

        return [
            Stat::make(__('admin.widgets.marketing.accrued'), 'EGP '.number_format($accrued, 0))
                ->description(__('admin.widgets.marketing.accrued_desc', ['year' => $year]))
                ->descriptionIcon('heroicon-m-banknotes')
                ->color('primary')
                ->url($url),

            Stat::make(__('admin.widgets.marketing.spent'), 'EGP '.number_format($spent, 0))
                ->description(__('admin.widgets.marketing.spent_desc', ['pct' => $usedPct]))
                ->descriptionIcon('heroicon-m-megaphone')
                // Past 90% of the fund is the point at which the next campaign needs a decision,
                // not a surprise.
                ->color($usedPct > 100 ? 'danger' : ($usedPct >= 90 ? 'warning' : 'success'))
                ->url($url),

            Stat::make(__('admin.widgets.marketing.balance'), 'EGP '.number_format($balance, 0))
                ->description($balance < 0
                    ? __('admin.widgets.marketing.over_budget')
                    : __('admin.widgets.marketing.balance_desc'))
                ->descriptionIcon($balance < 0 ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-circle')
                ->color($balance < 0 ? 'danger' : 'success')
                ->url($url),
        ];
    }
}
