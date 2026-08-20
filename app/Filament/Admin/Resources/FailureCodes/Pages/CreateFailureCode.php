<?php

namespace App\Filament\Admin\Resources\FailureCodes\Pages;

use App\Filament\Admin\Resources\FailureCodes\FailureCodeResource;
use Filament\Resources\Pages\CreateRecord;

class CreateFailureCode extends CreateRecord
{
    protected static string $resource = FailureCodeResource::class;
}
