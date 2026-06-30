<?php

namespace App\Filament\Admin\Pages\Concerns;

use App\Models\FiscalYear;
use App\Support\TenantScope;
use Illuminate\Support\Facades\Auth;

/**
 * Shared scaffold for the read-only ledger report PAGES — Trial Balance, General
 * Ledger, Income Statement, Balance Sheet. Centralizes the year + property filter
 * state, the `general_ledger.view` gate, the Accounting nav group, the
 * property-scoped asset-id resolution, and the year list, so each page declares
 * only its icon/sort/route/title and the one report-service call.
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

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.accounting');
    }

    /** The report's asset-id filter, clamped to the user's visible properties. */
    protected function scopedAssetIds(): ?array
    {
        return TenantScope::reportAssetIds($this->assetId ?: null);
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
