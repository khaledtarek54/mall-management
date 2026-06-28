<?php

namespace App\Filament\Admin\Resources\Users\Pages;

use App\Filament\Admin\Resources\Users\UserResource;
use App\Support\AccessControlAudit;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /** @var array<int, string> role names held before this save */
    protected array $rolesBefore = [];

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }

    protected function beforeSave(): void
    {
        // Captured before the roles relationship is synced (the Select saves via
        // raw pivot sync, which fires no spatie event) — so we diff it ourselves.
        $this->rolesBefore = $this->record->roles()->pluck('name')->all();
    }

    protected function afterSave(): void
    {
        // Enforce the protected-role policy FIRST (so the audit reflects the real
        // outcome), then log the role delta.
        UserResource::enforceProtectedRolesRule($this->record, $this->rolesBefore);

        AccessControlAudit::logRoleDiff(
            $this->record,
            $this->rolesBefore,
            $this->record->roles()->pluck('name')->all(),
        );
    }
}
