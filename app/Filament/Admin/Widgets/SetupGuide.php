<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Concerns\RoleScopedWidget;
use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\Admin\Resources\Units\UnitResource;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\Unit;
use Filament\Facades\Filament;
use Filament\Widgets\Widget;

/**
 * Onboarding checklist at the top of the dashboard, for a property that is still being set up.
 *
 *  - Fresh install (no data): expands the next-step CTA and hides the rest.
 *  - Mid-setup: shows the next missing step with a one-click CTA.
 *  - Setup complete: **the widget disappears.**
 *
 * It used to keep a permanent "You're all set ✓" banner once every step was done, which meant a
 * mall that had been live for months still opened its dashboard on a row of ticks telling it so.
 * An onboarding aid that never leaves is not an aid. Nothing is lost by removing it: every step it
 * lists is reachable from the sidebar, and the checklist returns for the next new property.
 *
 * Detects platform state from real DB counts; each step CTA deep-links into the relevant resource
 * so an operator goes from zero to "first invoice" in 5 clicks.
 */
class SetupGuide extends Widget
{
    use RoleScopedWidget;

    protected string $view = 'filament.admin.widgets.setup-guide';

    // Top of the dashboard, above ActionRequired, so it's the first thing
    // a new operator sees on login.
    protected static ?int $sort = -1;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        // Role first (cheap), then the four existence checks — so a role that never sees this
        // widget doesn't pay for the queries.
        return static::roleAllowsView() && ! static::isComplete();
    }

    /** Has this property finished every onboarding step? */
    public static function isComplete(): bool
    {
        return ! in_array(false, array_values(static::stepState()), true);
    }

    /**
     * Just the four "is this done?" answers, with no labels and — critically — no `getUrl()`.
     *
     * `canView()` runs before the panel has resolved a tenant in some contexts, and building a
     * resource URL there throws `UrlGenerationException` for the missing `{tenant}` parameter.
     * Splitting the state from the presentation keeps the gate cheap and safe, and both this and
     * `steps()` read the same four checks so "finished" can't mean two things.
     *
     * @return array<string, bool>
     */
    protected static function stepState(): array
    {
        // With panel tenancy enabled, every list & query is property-scoped, so the setup
        // checklist measures THIS property's state, not the whole platform.
        $assetId = Filament::getTenant()?->getKey();

        return [
            'units' => Unit::query()->where('asset_id', $assetId)->exists(),
            'tenants' => Tenant::query()
                ->whereHas('leases.unit', fn ($q) => $q->where('asset_id', $assetId))
                ->exists(),
            'leases' => Lease::query()
                ->whereHas('unit', fn ($q) => $q->where('asset_id', $assetId))
                ->exists(),
            'invoices' => Invoice::query()
                ->whereHas('lease.unit', fn ($q) => $q->where('asset_id', $assetId))
                ->exists(),
        ];
    }

    /**
     * The rendered checklist: the state above, plus the labels and CTAs the view needs.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function steps(): array
    {
        $done = static::stepState();

        return [
            [
                'key' => 'units',
                'label' => __('admin.setup.steps.units'),
                'description' => __('admin.setup.steps.units_description'),
                'done' => $done['units'],
                'url' => UnitResource::getUrl('create'),
                'cta' => __('admin.setup.cta.create_unit'),
                'icon' => 'heroicon-o-rectangle-stack',
            ],
            [
                'key' => 'tenants',
                'label' => __('admin.setup.steps.tenants'),
                'description' => __('admin.setup.steps.tenants_description'),
                'done' => $done['tenants'],
                'url' => TenantResource::getUrl('create'),
                'cta' => __('admin.setup.cta.create_tenant'),
                'icon' => 'heroicon-o-users',
            ],
            [
                'key' => 'leases',
                'label' => __('admin.setup.steps.leases'),
                'description' => __('admin.setup.steps.leases_description'),
                'done' => $done['leases'],
                'url' => LeaseResource::getUrl('create'),
                'cta' => __('admin.setup.cta.create_lease'),
                'icon' => 'heroicon-o-document-text',
            ],
            [
                'key' => 'invoices',
                'label' => __('admin.setup.steps.invoices'),
                'description' => __('admin.setup.steps.invoices_description'),
                'done' => $done['invoices'],
                'url' => InvoiceResource::getUrl('index'),
                'cta' => __('admin.setup.cta.create_invoice'),
                'icon' => 'heroicon-o-banknotes',
            ],
        ];
    }

    public function getViewData(): array
    {
        $steps = static::steps();

        $doneCount = count(array_filter($steps, fn ($s) => $s['done']));
        $totalCount = count($steps);

        // Kept for the view's contract, but always false in practice: canView() removes the
        // widget the moment every step is done, so the "all set" branch is now unreachable.
        $allDone = $doneCount === $totalCount;

        // First incomplete step — the one we surface with a big CTA.
        $nextStep = null;
        foreach ($steps as $s) {
            if (! $s['done']) {
                $nextStep = $s;
                break;
            }
        }

        return [
            'steps' => $steps,
            'doneCount' => $doneCount,
            'totalCount' => $totalCount,
            'allDone' => $allDone,
            'nextStep' => $nextStep,
            'progressPct' => (int) round(($doneCount / $totalCount) * 100),
        ];
    }
}
