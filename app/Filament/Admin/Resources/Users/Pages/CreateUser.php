<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Resources\Users\UserResource;
use App\Support\AccessControlAudit;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function afterCreate(): void
    {
        // A new user had no roles; a non-super_admin cannot create one with a
        // protected role (super_admin / manager).
        UserResource::enforceProtectedRolesRule($this->record, []);

        // A new user held nothing, so every assignment is a grant.
        UserResource::enforceGrantableAssetsRule($this->record, []);

        AccessControlAudit::logRoleDiff(
            $this->record,
            [],
            $this->record->roles()->pluck('name')->all(),
        );
    }
}
