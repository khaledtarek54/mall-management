<?php

namespace App\Filament\Admin\Resources\ViolationCategories\Pages;

use App\Filament\Admin\Resources\ViolationCategories\ViolationCategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateViolationCategory extends CreateRecord
{
    protected static string $resource = ViolationCategoryResource::class;
}
