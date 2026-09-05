<?php

namespace App\Filament\Admin\Resources\UnitOwnerships\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\Concerns\SavesTableViews;
use App\Filament\Admin\Resources\UnitOwnerships\UnitOwnershipResource;
use App\Filament\Imports\ChargeImporter;
use App\Filament\Imports\UnitOwnershipImporter;
use App\Support\Imports;
use Filament\Actions\CreateAction;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ListRecords;

class ListUnitOwnerships extends ListRecords
{
    use SavesTableViews;

    protected static string $resource = UnitOwnershipResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->savedViewActions(),
            GuideAction::for(static::getResource()),
            // THE REGISTER ITSELF, before the schedules below — that is the order the work
            // happens in, and a schedule keyed by an ownership reference has nothing to attach to
            // until the ownership exists. A mall that sold floors has hundreds of these, and every
            // one missing is an owner `BillUnitOwnershipsService` never bills, reported month
            // after month as an unremarkable `skipped`. See UnitOwnershipImporter.
            ImportAction::make('importOwnerships')
                ->importer(UnitOwnershipImporter::class)
                ->label(__('admin.actions.import'))
                ->icon('heroicon-o-arrow-up-tray')
                ->visible(fn () => Imports::allowed())
                ->authorize(fn () => Imports::allowed()),
            // The assessment schedules, keyed by ownership reference — the same importer the lease
            // list mounts, because a صيانة assessment IS a `charges` row. Without it a migrating
            // operator loads a portfolio of sold units and every one of them is un-billable, which
            // is the missing-schedule failure (pre-staging QA F-01) arriving through the import
            // door rather than through the screen.
            ImportAction::make('importAssessments')
                ->importer(ChargeImporter::class)
                ->label(__('admin.actions.import_assessments'))
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->visible(fn () => Imports::allowed())
                ->authorize(fn () => Imports::allowed()),
            CreateAction::make(),
        ];
    }
}
