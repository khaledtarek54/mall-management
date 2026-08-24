<?php

namespace App\Filament\Admin\Resources\Roles\Schemas;

use App\Support\Translate;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class RoleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.sections.role_details'))
                ->columns(2)
                ->components([
                    TextInput::make('name')
                        ->label(__('admin.fields.role_name'))
                        ->required()
                        ->maxLength(125)
                        ->regex('/^[a-z0-9_]+$/')
                        ->helperText(__('admin.fields.role_name_helper'))
                        ->disabled(fn ($record) => $record && array_key_exists($record->name, RolesPermissionsSeeder::ROLES))
                        ->dehydrated()
                        ->unique(ignoreRecord: true),
                    Hidden::make('guard_name')->default('web'),
                ]),

            // One section per permission module — each contains a CheckboxList
            // bound to the same `permissions` relationship but scoped to that
            // module's keys. Filament merges the per-section selections when
            // saving the relationship (because each CheckboxList writes the
            // full set of selections within its options to the relationship).
            ...static::permissionSections(),
        ]);
    }

    /** @return Section[] */
    private static function permissionSections(): array
    {
        $sections = [];

        foreach (RolesPermissionsSeeder::PERMISSIONS as $module => $perms) {
            $sections[] = Section::make(Translate::orHumanized("admin.permission_modules.{$module}", $module))
                ->collapsible()
                ->collapsed()
                ->columns(1)
                ->components([
                    CheckboxList::make("permissions_module_{$module}")
                        // `hiddenLabel()`, not `label('')`: a blank label makes Filament DERIVE one
                        // from the field name, so the Arabic panel read "Permissions module assets"
                        // above every checkbox group. The section heading already names the module.
                        ->hiddenLabel()
                        ->options($perms)
                        ->columns(2)
                        ->bulkToggleable()
                        ->dehydrated(false), // persisted manually in EditRole/CreateRole
                ]);
        }

        return $sections;
    }

    private static function humanize(string $module): string
    {
        return ucwords(str_replace('_', ' ', $module));
    }
}
