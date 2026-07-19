<?php

namespace App\Filament\Admin\Resources\PostDatedCheques\Pages;

use App\Filament\Admin\Resources\PostDatedCheques\PostDatedChequeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPostDatedCheques extends ListRecords
{
    protected static string $resource = PostDatedChequeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->visible(fn () => PostDatedChequeResource::canCreate()),
        ];
    }
}
