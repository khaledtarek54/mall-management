<?php

namespace App\Filament\Admin\Resources\FixedAssets\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\Concerns\SavesTableViews;
use App\Filament\Admin\Resources\FixedAssets\FixedAssetResource;
use App\Filament\Imports\FixedAssetImporter;
use App\Services\DepreciationService;
use App\Support\Imports;
use App\Support\ReportCsv;
use App\Support\StatusTabs;
use App\Support\TenantScope;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\ImportAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListFixedAssets extends ListRecords
{
    use SavesTableViews;

    protected static string $resource = FixedAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->savedViewActions(),
            GuideAction::for(static::getResource()),
            // Post this month's straight-line charge across all active assets (same
            // work the monthly cron does — idempotent, safe to click twice).
            Action::make('post_depreciation')
                ->label(__('admin.fixed_assets.actions.post_depreciation'))
                ->icon('heroicon-o-calculator')
                ->color('primary')
                ->requiresConfirmation()
                ->visible(fn () => auth()->user()?->can('fixed_assets.edit') ?? false)
                ->authorize(fn () => auth()->user()?->can('fixed_assets.edit') ?? false)
                ->action(function (): void {
                    abort_unless(auth()->user()?->can('fixed_assets.edit') ?? false, 403);
                    // Scope to the properties this user can see — a single-property
                    // accounting user must never post another mall's depreciation.
                    // visibleAssetIds() is null for portfolio users → posts everything.
                    $count = app(DepreciationService::class)->run(now(), TenantScope::visibleAssetIds());
                    Notification::make()
                        ->title(__('admin.fixed_assets.depreciation_posted'))
                        ->body(__('admin.fixed_assets.depreciation_posted_body', ['count' => $count]))
                        ->success()
                        ->send();
                }),
            // The fixed-asset register in the accountant's format — cost, accumulated depreciation
            // and net book value per asset plus totals, the schedule behind the balance sheet.
            Action::make('export_csv')
                ->label(__('admin.reports.csv.export'))
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->visible(fn () => FixedAssetResource::canViewAny())
                ->authorize(fn () => FixedAssetResource::canViewAny())
                ->action(function () {
                    $csv = FixedAssetResource::registerCsv();

                    return ReportCsv::stream('fixed-asset-register', $csv['headers'], $csv['rows']);
                }),
            // Every imported asset is an OPENING BALANCE — bought before this system existed, so it
            // posts no acquisition and carries the depreciation already taken. See FixedAssetImporter.
            ImportAction::make()
                ->importer(FixedAssetImporter::class)
                ->label(__('admin.actions.import'))
                ->icon('heroicon-o-arrow-up-tray')
                ->visible(fn () => Imports::allowed())
                ->authorize(fn () => Imports::allowed()),
            CreateAction::make()->visible(fn () => FixedAssetResource::canCreate()),
        ];
    }

    /** The live register (which ties to the balance sheet) vs. what has been disposed. */
    public function getTabs(): array
    {
        return StatusTabs::build(FixedAssetResource::class, [
            'all' => ['label' => __('admin.tabs.all')],
            'active' => ['label' => __('admin.fixed_assets.statuses.active'), 'statuses' => ['active'], 'badge' => true, 'color' => 'success'],
            'disposed' => ['label' => __('admin.fixed_assets.statuses.disposed'), 'statuses' => ['disposed']],
        ]);
    }
}
