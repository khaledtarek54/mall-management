<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\Users\UserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::getResource()),
            // Navigate to the Create PAGE (not a modal) so CreateUser::afterCreate
            // runs the super_admin guard + the role-grant audit.
            CreateAction::make()
                ->url(UserResource::getUrl('create')),
        ];
    }
}
