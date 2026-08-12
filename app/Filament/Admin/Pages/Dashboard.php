<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Actions\GuideAction;
use App\Support\DashboardLayout;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Widgets\Widget;
use Filament\Widgets\WidgetConfiguration;

/**
 * The admin dashboard, composed from `App\Support\DashboardLayout` rather than from whatever
 * happens to be in the widgets directory.
 *
 * Filament's stock dashboard renders `Filament::getWidgets()` — every widget the panel discovered
 * — and leaves each widget to gate itself in `canView()`. That default is what published the
 * monthly-close receivables to the HR and marketing dashboards: `MonthlyCloseStats` never declared
 * a gate, so it was visible to all, and its docblock's claim that it was "NOT registered on the
 * dashboard" was never true. Composing from the registry inverts that: a widget appears because a
 * role's layout names it, so forgetting to gate a new widget makes it invisible rather than public.
 */
class Dashboard extends BaseDashboard
{
    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::class),
        ];
    }

    /**
     * @return array<class-string<Widget> | WidgetConfiguration>
     */
    public function getWidgets(): array
    {
        return DashboardLayout::widgetsFor();
    }

    /**
     * Two columns. The stock dashboard also uses 2, but state it here: the layouts are written
     * assuming full-width panels stack and the paired half-width charts sit side by side.
     */
    public function getColumns(): int|array
    {
        return 2;
    }
}
