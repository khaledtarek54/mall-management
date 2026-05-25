<?php

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Concerns\RoleScopedWidget;
use App\Filament\Admin\Resources\Assets\AssetResource;
use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Filament\Admin\Resources\Units\UnitResource;
use App\Models\Asset;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\Unit;
use Filament\Widgets\Widget;

/**
 * First-impression widget at the top of the dashboard.
 *
 *  - Fresh install (no data): expands the next-step CTA and hides the rest.
 *  - Mid-setup: shows the next missing step with a one-click CTA.
 *  - Setup complete: shows a compact "All set" tick row.
 *
 * Detects platform state from real DB counts; each step CTA deep-links into
 * the relevant resource so an operator goes from zero to "first invoice" in
 * 5 clicks.
 */
class SetupGuide extends Widget
{
    use RoleScopedWidget;

    protected static function allowedRoles(): array
    {
        // Only operational + admin roles see the setup guide. Viewers and
        // owners don't drive setup, they consume it.
        return ['manager', 'leasing_manager'];
    }

    protected string $view = 'filament.admin.widgets.setup-guide';

    // Top of the dashboard, above ActionRequired, so it's the first thing
    // a new operator sees on login.
    protected static ?int $sort = -1;

    protected static bool $isLazy = false;

    protected int|string|array $columnSpan = 'full';

    public function getViewData(): array
    {
        $steps = [
            [
                'key' => 'properties',
                'label' => __('admin.setup.steps.properties'),
                'description' => __('admin.setup.steps.properties_description'),
                'done' => Asset::query()->withoutGlobalScopes()->exists(),
                'url' => AssetResource::getUrl('create'),
                'cta' => __('admin.setup.cta.create_property'),
                'icon' => 'heroicon-o-building-office-2',
            ],
            [
                'key' => 'units',
                'label' => __('admin.setup.steps.units'),
                'description' => __('admin.setup.steps.units_description'),
                'done' => Unit::query()->exists(),
                'url' => UnitResource::getUrl('create'),
                'cta' => __('admin.setup.cta.create_unit'),
                'icon' => 'heroicon-o-rectangle-stack',
            ],
            [
                'key' => 'tenants',
                'label' => __('admin.setup.steps.tenants'),
                'description' => __('admin.setup.steps.tenants_description'),
                'done' => Tenant::query()->exists(),
                'url' => TenantResource::getUrl('create'),
                'cta' => __('admin.setup.cta.create_tenant'),
                'icon' => 'heroicon-o-users',
            ],
            [
                'key' => 'leases',
                'label' => __('admin.setup.steps.leases'),
                'description' => __('admin.setup.steps.leases_description'),
                'done' => Lease::query()->exists(),
                'url' => LeaseResource::getUrl('create'),
                'cta' => __('admin.setup.cta.create_lease'),
                'icon' => 'heroicon-o-document-text',
            ],
            [
                'key' => 'invoices',
                'label' => __('admin.setup.steps.invoices'),
                'description' => __('admin.setup.steps.invoices_description'),
                'done' => Invoice::query()->exists(),
                'url' => InvoiceResource::getUrl('index'),
                'cta' => __('admin.setup.cta.create_invoice'),
                'icon' => 'heroicon-o-banknotes',
            ],
        ];

        $doneCount = count(array_filter($steps, fn ($s) => $s['done']));
        $totalCount = count($steps);
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
