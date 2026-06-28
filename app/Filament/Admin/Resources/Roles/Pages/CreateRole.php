<?php

namespace App\Filament\Admin\Resources\Roles\Pages;

use App\Filament\Admin\Resources\Roles\RoleResource;
use App\Support\AccessControlAudit;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Resources\Pages\CreateRecord;
use Spatie\Permission\PermissionRegistrar;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['guard_name'] = 'web';
        return $data;
    }

    /**
     * After the role row is created, gather the per-module checkbox selections
     * from form state and attach them via Spatie.
     */
    protected function afterCreate(): void
    {
        $names = [];
        foreach (RolesPermissionsSeeder::PERMISSIONS as $module => $perms) {
            $key = "permissions_module_{$module}";
            $selected = $this->data[$key] ?? [];
            $names = array_merge($names, $selected);
        }
        $after = array_values(array_unique($names));

        $this->record->syncPermissions($after);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        AccessControlAudit::logPermissionDiff($this->record, [], $after);
    }
}
