<?php

namespace App\Filament\Admin\Resources\Roles\Pages;

use App\Filament\Admin\Resources\Roles\RoleResource;
use App\Support\AccessControlAudit;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Spatie\Permission\PermissionRegistrar;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn () => ! array_key_exists($this->record->name, RolesPermissionsSeeder::ROLES))
                // Deleting a role cascades to a mass revoke (model_has_roles +
                // role_has_permissions both cascadeOnDelete) — audit it before the
                // record (and its subject link) is gone.
                ->before(function (): void {
                    $holders = $this->record->users()->pluck('name')->all();
                    AccessControlAudit::log($this->record, 'role_deleted', [
                        $this->record->name.' ('.__('admin.activity.held_by').': '
                            .($holders ? implode(', ', $holders) : '—').')',
                    ]);
                }),
        ];
    }

    /**
     * Split the role's flat permission list across the per-module form fields
     * so each Section's CheckboxList shows the right checks on load.
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $names = $this->record->permissions->pluck('name')->all();
        foreach (RolesPermissionsSeeder::PERMISSIONS as $module => $perms) {
            $data["permissions_module_{$module}"] = array_values(array_intersect($names, array_keys($perms)));
        }
        return $data;
    }

    /**
     * After saving the role's name/guard, collect the per-module checkbox
     * selections, merge them into a single list, and sync to Spatie.
     */
    protected function afterSave(): void
    {
        $before = $this->record->permissions()->pluck('name')->all();

        $names = [];
        foreach (RolesPermissionsSeeder::PERMISSIONS as $module => $perms) {
            $key = "permissions_module_{$module}";
            $selected = $this->data[$key] ?? [];
            $names = array_merge($names, $selected);
        }
        $after = array_values(array_unique($names));

        $this->record->syncPermissions($after);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        // Audit the permission diff explicitly — this page is the only UI that edits it.
        AccessControlAudit::logPermissionDiff($this->record, $before, $after);
    }
}
