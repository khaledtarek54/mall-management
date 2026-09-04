<?php

/*
|--------------------------------------------------------------------------
| Regression — the RBAC vocabulary is translated, not left in the registry's English
|--------------------------------------------------------------------------
| `RolesPermissionsSeeder::PERMISSIONS` is `key => English sentence`, and `RoleForm` handed that
| array straight to its `CheckboxList` as the options. Measured at 83624504: **232 checkbox labels**
| in English on the Arabic panel, on the one screen that decides who may do what — plus the
| role-description column on the Roles list (14 more English sentences, read off
| `RolesPermissionsSeeder::ROLES`) and the two role PICKERS on the Users screen, which offered the
| raw identifiers `super_admin` / `hr` while the badge column beside them rendered «مدير عام»
| through `admin.users.roles_list`. One operator, two vocabularies for one value.
|
| `Translate`'s own docblock records the previous round of this — *"The whole Roles & Permissions
| form was English in Arabic … ~110 strings on one screen"* — which fixed the SECTION headings and
| left every checkbox inside them untouched.
|
| `App\Support\PermissionVocabulary` is the one seam now. The English half of the catalogue is
| DERIVED from the registry (`lang/en/admin/permissions.php` builds it), so there is no second
| English wording to drift; `TranslationKeyConformanceTest` test B then makes a permission with no
| Arabic a build failure on arrival.
*/

use App\Filament\Admin\Resources\Roles\Pages\EditRole;
use App\Filament\Admin\Resources\Roles\Pages\ListRoles;
use App\Filament\Admin\Resources\Users\Pages\CreateUser;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Support\PermissionVocabulary;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Lang;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(makeUser('super_admin'));
    Filament::setTenant(makeAsset());
    app()->setLocale('ar');
});

afterEach(function () {
    app()->setLocale('en');
    Filament::setTenant(null, isQuiet: true);
});

it('carries an Arabic sentence for every permission the registry defines', function () {
    $english = collect(RolesPermissionsSeeder::PERMISSIONS)->collapse()->all();

    // The sweep must have seen the whole catalogue before it reports on it.
    expect(count($english))->toBeGreaterThan(200);

    $untranslated = [];

    foreach ($english as $key => $sentence) {
        $arabic = PermissionVocabulary::label($key);

        // `fallback: false` deliberately — `Lang::has()` falls back to English by default, so the
        // obvious form of this check passes for every key present only in English.
        if (! Lang::has("admin.permissions.{$key}", 'ar', fallback: false)
            || $arabic === $sentence
            || preg_match('/\p{Arabic}/u', $arabic) !== 1) {
            $untranslated[] = $key;
        }
    }

    expect($untranslated)->toBe([], count($untranslated).' permissions read in English on the Arabic panel: '
        .implode(', ', array_slice($untranslated, 0, 20)));
});

it('renders those Arabic labels on the real Roles form', function () {
    // Walk the schema the page actually built. A section nests its components one level down, and
    // the checkboxes are the only place the permission wording appears.
    $flatten = function (array $components) use (&$flatten): array {
        $flat = [];

        foreach ($components as $component) {
            $flat[] = $component;

            foreach ($component->getChildSchemas() as $child) {
                $flat = array_merge($flat, $flatten($child->getComponents(withHidden: true)));
            }
        }

        return $flat;
    };

    $role = Role::findByName('manager', 'web');

    $page = Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])->instance();

    $options = [];

    foreach ($flatten($page->getSchema('form')?->getComponents(withHidden: true) ?? []) as $component) {
        if ($component instanceof CheckboxList) {
            $options += $component->getOptions();
        }
    }

    // The premise: the walk found the whole matrix, not one section of it.
    $expected = collect(RolesPermissionsSeeder::PERMISSIONS)->collapse()->all();
    expect(array_keys($options))->toEqualCanonicalizing(array_keys($expected));

    $stillEnglish = array_keys(array_filter(
        $options,
        fn (string $label, string $key): bool => $label === ($expected[$key] ?? null),
        ARRAY_FILTER_USE_BOTH,
    ));

    expect($stillEnglish)->toBe([]);
});

it('describes a role on the Roles list in the reader language', function () {
    // `marketing` deliberately: both wordings are well under the column's 80-character limit, so a
    // truncation ellipsis cannot decide the assertion, and the search isolates the row.
    $page = Livewire::test(ListRoles::class)->searchTable('marketing');

    $page->assertSee(PermissionVocabulary::roleDescription('marketing'))
        ->assertDontSee(RolesPermissionsSeeder::ROLES['marketing']);
});

it('names a role in the user picker the way the badge beside it names one', function () {
    $flatten = function (array $components) use (&$flatten): array {
        $flat = [];

        foreach ($components as $component) {
            $flat[] = $component;

            foreach ($component->getChildSchemas() as $child) {
                $flat = array_merge($flat, $flatten($child->getComponents(withHidden: true)));
            }
        }

        return $flat;
    };

    $page = Livewire::test(CreateUser::class)->instance();

    $select = null;

    foreach ($flatten($page->getSchema('form')?->getComponents(withHidden: true) ?? []) as $component) {
        if ($component instanceof Select && $component->getName() === 'roles') {
            $select = $component;
        }
    }

    expect($select)->not->toBeNull();

    $options = array_values($select->getOptions());

    expect($options)->toContain(PermissionVocabulary::roleLabel('manager'))
        ->and($options)->not->toContain('manager');
});

it('names the role in the users filter chip too', function () {
    $manager = Role::findByName('manager', 'web');

    $component = Livewire::test(ListUsers::class)->filterTable('roles', (string) $manager->getKey());

    $labels = collect($component->instance()->getTable()->getFilterIndicators())
        ->map(fn ($indicator) => (string) $indicator->getLabel())
        ->implode(' | ');

    // Filament's own indicator plucks the relationship title attribute — `roles.name` — straight
    // off the column and reads no label callback, so the chip is a THIRD rendering of one value.
    expect($labels)->toContain(PermissionVocabulary::roleLabel('manager'));

    $component->assertOk();
});

it('still reads in English for an operator working in English', function () {
    // The control: the fix must not have replaced one language with another.
    app()->setLocale('en');

    expect(PermissionVocabulary::label('settings.manage'))
        ->toBe(RolesPermissionsSeeder::PERMISSIONS['settings']['settings.manage'])
        ->and(PermissionVocabulary::roleLabel('manager'))->toBe('Manager')
        ->and(PermissionVocabulary::roleDescription('manager'))->toBe(RolesPermissionsSeeder::ROLES['manager']);
});
