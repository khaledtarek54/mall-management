<?php

namespace App\Filament\Admin\Resources\Departments\Pages;

use App\Filament\Admin\Resources\Departments\DepartmentResource;
use Filament\Resources\Pages\ListRecords;

class ListDepartments extends ListRecords
{
    protected static string $resource = DepartmentResource::class;

    // No header actions — departments are a fixed set (no "New department").
}
