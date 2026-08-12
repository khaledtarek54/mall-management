<?php

namespace App\Filament\Admin\Resources\Leases\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\Concerns\SavesTableViews;
use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Filament\Imports\ChargeImporter;
use App\Filament\Imports\LeaseImporter;
use App\Support\Imports;
use App\Support\StatusTabs;
use Filament\Actions\CreateAction;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ListRecords;

class ListLeases extends ListRecords
{
    use SavesTableViews;

    protected static string $resource = LeaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->savedViewActions(),
            GuideAction::for(static::getResource()),
            ImportAction::make()
                ->importer(LeaseImporter::class)
                ->label(__('admin.actions.import'))
                ->icon('heroicon-o-arrow-up-tray')
                // Bulk import writes lease records — gate server-side (was ungated).
                ->visible(fn () => Imports::allowed())
                ->authorize(fn () => Imports::allowed()),
            // A SECOND import on this page, deliberately: the charge schedule is keyed by lease
            // reference, so it is portfolio-wide work rather than something done one lease at a
            // time on the relation manager. Named distinctly, because uploading a charge file into
            // the lease importer would create leases out of charge rows.
            ImportAction::make('importCharges')
                ->importer(ChargeImporter::class)
                ->label(__('admin.actions.import_charges'))
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->visible(fn () => Imports::allowed())
                ->authorize(fn () => Imports::allowed()),
            CreateAction::make(),
        ];
    }

    /**
     * The leasing pipeline. "Expiring" is a date window rather than a status —
     * a lease is still `active` right up to the day it lapses, so renewals are
     * invisible on a pure status split. 90 days matches the ExpiringLeases
     * widget and the expiring_soon filter.
     */
    public function getTabs(): array
    {
        return StatusTabs::build(LeaseResource::class, [
            'all' => ['label' => __('admin.tabs.all')],
            'pending_approval' => ['label' => __('admin.tabs.pending_approval'), 'statuses' => ['draft', 'pending_approval'], 'badge' => true, 'color' => 'warning'],
            'active' => ['label' => __('admin.tabs.active'), 'statuses' => ['active'], 'badge' => true, 'color' => 'success'],
            'expiring' => [
                'label' => __('admin.tabs.expiring'),
                'badge' => true,
                'color' => 'danger',
                'query' => fn ($query) => $query
                    ->where('status', 'active')
                    ->whereNotNull('expiry_date')
                    ->whereBetween('expiry_date', [now()->toDateString(), now()->addDays(90)->toDateString()]),
            ],
            'ended' => ['label' => __('admin.tabs.ended'), 'statuses' => ['expired', 'renewed', 'terminated', 'cancelled']],
        ]);
    }
}
