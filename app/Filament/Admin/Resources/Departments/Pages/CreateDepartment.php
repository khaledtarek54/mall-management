<?php

namespace App\Filament\Admin\Resources\Departments\Pages;

use App\Filament\Admin\Resources\Departments\DepartmentResource;
use Filament\Resources\Pages\CreateRecord;

/**
 * The page D-6 forgot.
 *
 * `fd1ea2d1` removed `DepartmentResource::canCreate()`'s hard `return false` and said, in the commit
 * message, that "a department can be added". The gate opened and the door was never built: there was
 * no `create` route, no page and no button, so `departments.create` was a permission that granted
 * nothing and a mall with its own Security team still had nowhere to put it. The form was already
 * written for this page — `name` is `disabledOn('edit')`, a branch nothing could reach.
 */
class CreateDepartment extends CreateRecord
{
    protected static string $resource = DepartmentResource::class;

    /**
     * Both ends of the move, not just the submitted value — the same guard {@see EditDepartment}
     * carries, because a blank property means EVERY mall and granting that is a portfolio act.
     *
     * The "from" side is null on a create: there is no previous home to leave.
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        DepartmentResource::assertMayWriteAcrossPortfolio(
            isset($data['asset_id']) ? (int) $data['asset_id'] : null,
            null,
            'admin.errors.department_needs_every_property',
        );

        return $data;
    }
}
