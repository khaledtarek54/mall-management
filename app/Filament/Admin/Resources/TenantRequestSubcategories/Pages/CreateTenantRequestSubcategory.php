<?php

namespace App\Filament\Admin\Resources\TenantRequestSubcategories\Pages;

use App\Filament\Admin\Resources\TenantRequestSubcategories\TenantRequestSubcategoryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateTenantRequestSubcategory extends CreateRecord
{
    protected static string $resource = TenantRequestSubcategoryResource::class;
}
