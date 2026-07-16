<?php

namespace App\Filament\Admin\Resources\Leases\Pages;

use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Filament\Imports\LeaseImporter;
use Filament\Actions\CreateAction;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ListRecords;

class ListLeases extends ListRecords
{
    protected static string $resource = LeaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ImportAction::make()
                ->importer(LeaseImporter::class)
                ->label(__('admin.actions.import'))
                ->icon('heroicon-o-arrow-up-tray')
                // Bulk import writes lease records — gate server-side (was ungated).
                ->visible(fn () => LeaseResource::canCreate())
                ->authorize(fn () => LeaseResource::canCreate()),
            CreateAction::make(),
        ];
    }
}
