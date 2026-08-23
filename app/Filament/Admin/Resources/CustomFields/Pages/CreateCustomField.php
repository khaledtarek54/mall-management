<?php

namespace App\Filament\Admin\Resources\CustomFields\Pages;

use App\Filament\Admin\Resources\CustomFields\CustomFieldResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomField extends CreateRecord
{
    protected static string $resource = CustomFieldResource::class;
}
