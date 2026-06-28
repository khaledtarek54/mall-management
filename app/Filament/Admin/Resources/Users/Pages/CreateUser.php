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
        // A new user had no roles; a non-super_admin cannot create one as super_admin.
        UserResource::enforceSuperAdminRule($this->record, []);

        AccessControlAudit::logRoleDiff(
            $this->record,
            [],
            $this->record->roles()->pluck('name')->all(),
        );
    }
}
