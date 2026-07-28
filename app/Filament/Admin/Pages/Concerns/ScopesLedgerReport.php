<?php

namespace App\Filament\Admin\Pages\Concerns;

use App\Models\Asset;
use App\Models\FiscalYear;
use App\Support\TenantScope;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Shared scaffold for the read-only ledger report PAGES — Trial Balance, General
 * Ledger, Income Statement, Balance Sheet, Cash Flow. Centralizes the year +
 * property filter state, the `general_ledger.view` gate, the Accounting nav
 * group, the property-scoped asset-id resolution, and the year list, so each
 * page declares only its icon/sort/route/title and the one report-service call.
 *
 * The year/property pickers are a native Filament Schema (`filtersForm`), not
 * hand-written <select> markup. They stay bound to the `$year` / `$assetId`
 * Livewire properties on purpose: the PDF and CSV header actions read those, so
 * screen and export are driven by one piece of state and cannot disagree about
 * which period was exported.
 */
trait ScopesLedgerReport
{
    public int $year;

    public ?int $assetId = null;

    public static function canAccess(): bool
    {
        return Auth::user()?->can('general_ledger.view') ?? false;
    }

    public function mount(): void
    {
        $this->year = (int) now()->year;
    }

    /** The year + property picker strip, rendered above the report table. */
    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->columns(['sm' => 2, 'lg' => 3])
                    ->schema([
                        Select::make('year')
                            ->label(__('admin.reports.fiscal_year'))
                            ->options(fn (): array => $this->yearOptions())
                            ->native(false)
                            ->live(),
                        Select::make('assetId')
                            ->label(__('admin.reports.property_scope'))
                            ->options(fn (): array => TenantScope::selectableAssetOptions())
                            ->placeholder(__('admin.fields.property_consolidated'))
                            ->native(false)
                            ->live(),
                    ]),
            ]);
    }

    /** First instant of the selected fiscal year. */
    protected function periodStart(): Carbon
    {
        return Carbon::create($this->year, 1, 1)->startOfDay();
    }

    /** Last instant of the selected fiscal year. */
    protected function periodEnd(): Carbon
    {
        return Carbon::create($this->year, 12, 31)->endOfDay();
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.accounting');
    }

    /** The report's asset-id filter, clamped to the user's visible properties. */
    protected function scopedAssetIds(): ?array
    {
        return TenantScope::reportAssetIds($this->assetId ?: null);
    }

    /**
     * Human label for the report's scope — used in PDF headers. Derived from the
     * CLAMPED asset-id set (scopedAssetIds), never the raw client-bound assetId, so
     * the header can't name a property the user isn't allowed to see and always
     * matches the PDF body. A single allowed property → its name; else Consolidated.
     */
    protected function propertyLabel(): string
    {
        $ids = $this->scopedAssetIds();

        if (is_array($ids) && count($ids) === 1) {
            return Asset::find($ids[0])?->name ?? __('admin.fields.property_consolidated');
        }

        return __('admin.fields.property_consolidated');
    }

    protected function canViewReports(): bool
    {
        return Auth::user()?->can('general_ledger.view') ?? false;
    }

    /** Filter view-data every report blade needs (year list, property picker, locale). */
    protected function filterViewData(): array
    {
        return [
            'years' => $this->yearOptions(),
            'properties' => ['' => __('admin.fields.property_consolidated')] + TenantScope::selectableAssetOptions(),
            'locale' => app()->getLocale(),
        ];
    }

    /** @return array<int, int> */
    protected function yearOptions(): array
    {
        $years = FiscalYear::query()->orderByDesc('year')->pluck('year')->all();
        if (empty($years)) {
            $years = [(int) now()->year];
        }

        return array_combine($years, $years);
    }
}
