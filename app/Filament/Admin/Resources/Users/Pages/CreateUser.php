<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Resources\Users\UserResource;
use App\Support\AccessControlAudit;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return UserResource::guardSuperAdminAssignment($data, null);
    }

    protected function afterCreate(): void
    {
        AccessControlAudit::logRoleDiff(
            $this->record,
            [],
            $this->record->roles()->pluck('name')->all(),
        );
    }
}
