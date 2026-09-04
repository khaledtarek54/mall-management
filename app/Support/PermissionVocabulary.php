<?php

namespace App\Support;

use Database\Seeders\RolesPermissionsSeeder;

/**
 * The words the Roles & Permissions screens are written in.
 *
 * **The defect this closes.** `RolesPermissionsSeeder::PERMISSIONS` is `key => English sentence`,
 * and `RoleForm` handed that array straight to its `CheckboxList` as the options. Measured at
 * 83624504: **232 checkbox labels** on the one screen that decides who may do what, in English, on
 * the Arabic panel — plus the role-description column on the Roles list (14 more English sentences,
 * read off `ROLES`) and the two role PICKERS on the Users screen, which offered the raw identifiers
 * `super_admin` / `hr` while the badge column beside them rendered «مدير عام» through
 * `admin.users.roles_list`. One operator, two vocabularies for one value.
 *
 * {@see Translate}'s own docblock records the previous round of this — *"The whole Roles &
 * Permissions form was English in Arabic … ~110 strings on one screen"* — which fixed the SECTION
 * headings and left every checkbox inside them untouched.
 *
 * **One seam, and the English half is DERIVED.** Every label resolves here, through
 * `admin.permissions.{key}` with the registry's own sentence as the floor, so a permission added to
 * the seeder still renders (in English) rather than rendering a raw key. `lang/en/admin/
 * permissions.php` DOES NOT EXIST: `Translate::orFallback()` already falls back to `floor()`, which
 * is the registry's own English sentence, so an English lang file would be exactly the second
 * wording this class exists to avoid. `lang/ar/admin/permissions.php` is the Arabic half and IS
 * written out, because there is nothing to derive it from.
 *
 * A first version of this DID build the English catalogue inside `lang/en/admin/permissions.php`,
 * by `use`-ing the seeder and looping it. It read as "derive, never re-list" and was the wrong home:
 * a lang file is DATA, read by tooling that does not boot the application, and requiring an app
 * class there broke `TranslationKeyConformanceTest` outright — `require`ing the file without the
 * autoloader is a fatal, not a missing translation.
 * `TranslationKeyConformanceTest` test B then compares the two catalogues key-for-key, so a new
 * permission with no Arabic turns the build red on arrival.
 *
 * **The catalogue keys are NESTED, and they have to be.** `__()` reads a dot as a path, so a flat
 * `'charge_codes.view' => '…'` entry is looked up as `['charge_codes']['view']`, never found, and
 * the operator reads the literal key (test G exists for exactly that mistake).
 */
final class PermissionVocabulary
{
    /** @var array<string, string>|null permission key => the registry's English sentence */
    private static ?array $floor = null;

    /**
     * The permission modules, in registry order — which is the order the Roles form's sections
     * appear in. Read from the registry, never re-listed.
     *
     * @return array<int, string>
     */
    public static function modules(): array
    {
        return array_keys(RolesPermissionsSeeder::PERMISSIONS);
    }

    /** The heading over one module's block of checkboxes. */
    public static function moduleLabel(string $module): string
    {
        return Translate::orHumanized("admin.permission_modules.{$module}", $module);
    }

    /**
     * One module's checkboxes, keyed by the permission name Filament stores.
     *
     * @return array<string, string>
     */
    public static function optionsFor(string $module): array
    {
        $options = [];

        foreach (array_keys(RolesPermissionsSeeder::PERMISSIONS[$module] ?? []) as $permission) {
            $options[$permission] = self::label($permission);
        }

        return $options;
    }

    /**
     * What one permission lets somebody do, in the reader's language.
     *
     * Falls back to the registry's English sentence and, failing that, to the key itself — which is
     * what an unrecognised permission genuinely IS, and more useful than a humanised guess.
     */
    public static function label(string $permission): string
    {
        return Translate::orFallback(
            "admin.permissions.{$permission}",
            self::floor()[$permission] ?? $permission,
        );
    }

    /** A role's NAME as a person reads it — «مدير عام», not `super_admin`. */
    public static function roleLabel(string $role): string
    {
        return Translate::orHumanized("admin.users.roles_list.{$role}", $role);
    }

    /**
     * What a role is FOR, in the reader's language.
     *
     * A role somebody built in the panel has no shipped description; it is named as custom rather
     * than left blank, because an empty cell reads as missing data.
     */
    public static function roleDescription(string $role): string
    {
        $floor = RolesPermissionsSeeder::ROLES[$role] ?? null;

        return $floor === null
            ? __('admin.fields.role_custom')
            : Translate::orFallback("admin.role_descriptions.{$role}", $floor);
    }

    /** Is this one of the roles the installer ships, rather than one an admin built? */
    public static function isSeededRole(string $role): bool
    {
        return array_key_exists($role, RolesPermissionsSeeder::ROLES);
    }

    /** @return array<int, string> */
    public static function seededRoleNames(): array
    {
        return array_keys(RolesPermissionsSeeder::ROLES);
    }

    /** @return array<string, string> */
    private static function floor(): array
    {
        if (self::$floor !== null) {
            return self::$floor;
        }

        $floor = [];

        foreach (RolesPermissionsSeeder::PERMISSIONS as $permissions) {
            $floor += $permissions;
        }

        return self::$floor = $floor;
    }
}
