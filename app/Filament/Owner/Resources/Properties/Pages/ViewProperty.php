<?php

namespace App\Filament\Owner\Resources\Properties\Pages;

use App\Filament\Owner\Resources\Properties\PropertyResource;
use App\Models\Asset;
use App\Services\AssetStatementPdfService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewProperty extends ViewRecord
{
    protected static string $resource = PropertyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('statement')
                ->label(__('admin.statement.property_action_label'))
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->action(function (Asset $record) {
                    $svc = app(AssetStatementPdfService::class);
                    $pdf = $svc->build($record);

                    return response()->streamDownload(
                        fn () => print($pdf),
                        $svc->filename($record),
                        ['Content-Type' => 'application/pdf'],
                    );
                }),
        ];
    }
}
