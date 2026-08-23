<?php

namespace App\Filament\Admin\Resources\CustomFields\Pages;

use App\Filament\Admin\Resources\CustomFields\CustomFieldResource;
use App\Support\Filament\RefreshesRecordState;
use Filament\Resources\Pages\EditRecord;

class EditCustomField extends EditRecord
{
    use RefreshesRecordState;

    protected static string $resource = CustomFieldResource::class;
}
