<?php

namespace App\Filament\Admin\Resources\AccountMappings\Pages;

use App\Filament\Admin\Resources\AccountMappings\AccountMappingResource;
use Filament\Resources\Pages\CreateRecord;

class CreateAccountMapping extends CreateRecord
{
    protected static string $resource = AccountMappingResource::class;
}
