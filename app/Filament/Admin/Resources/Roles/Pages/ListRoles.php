<?php

namespace App\Filament\Admin\Resources\Roles\Pages;

use App\Filament\Admin\Resources\Roles\RoleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRoles extends ListRecords
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        // Navigate to the Create PAGE (not a modal): the permission CheckboxLists
        // are dehydrated(false) and only CreateRole::afterCreate syncs + audits them.
        return [CreateAction::make()->url(RoleResource::getUrl('create'))];
    }
}
