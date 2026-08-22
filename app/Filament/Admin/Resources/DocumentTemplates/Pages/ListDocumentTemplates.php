<?php

namespace App\Filament\Admin\Resources\DocumentTemplates\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\DocumentTemplates\DocumentTemplateResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDocumentTemplates extends ListRecords
{
    protected static string $resource = DocumentTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::getResource()),
            CreateAction::make(),
        ];
    }
}
