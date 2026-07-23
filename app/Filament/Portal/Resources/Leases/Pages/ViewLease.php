<?php

namespace App\Filament\Portal\Resources\Leases\Pages;

use App\Filament\Portal\Resources\Leases\LeaseResource;
use App\Models\Lease;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewLease extends ViewRecord
{
    protected static string $resource = LeaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadDocument')
                ->label(__('admin.portal.lease.download_document'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->visible(fn () => $this->record->getMedia(Lease::DOCUMENTS_COLLECTION)->isNotEmpty())
                ->action(function () {
                    $media = $this->record->getMedia(Lease::DOCUMENTS_COLLECTION)->last();
                    abort_if($media === null, 404);

                    return $media->toResponse(request());
                }),
        ];
    }
}
