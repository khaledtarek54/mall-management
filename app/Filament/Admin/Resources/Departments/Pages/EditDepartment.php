<?php

namespace App\Filament\Admin\Resources\Departments\Pages;

use App\Filament\Admin\Resources\Departments\DepartmentResource;
use Filament\Resources\Pages\EditRecord;

class EditDepartment extends EditRecord
{
    protected static string $resource = DepartmentResource::class;

    // No Delete header action — departments cannot be deleted (fixed set).
}
