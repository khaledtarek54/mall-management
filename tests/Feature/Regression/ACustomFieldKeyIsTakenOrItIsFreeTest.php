<?php

use App\Filament\Admin\Resources\CustomFields\Pages\CreateCustomField;
use App\Models\CustomField;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/*
|--------------------------------------------------------------------------
| A custom-field key is taken or it is free — and the operator is told which (SW-118)
|--------------------------------------------------------------------------
| `custom_fields` has carried `unique(['model', 'key'])` since the table was created, for the
| reason the migration states: the key is the ADDRESS of every value already recorded under it, so
| two definitions cannot share one.
|
| Nothing above the database asked. Measured at HEAD 2026-09-04: `CustomFieldForm` carries
| `required`, `maxLength(64)` and the key regex and no uniqueness rule; `CustomField::booted()`
| checked the SHAPE of the key (`/^[a-z][a-z0-9_]*$/`) and that it had not moved, and never whether
| it was already in use. So adding a second "parent_group" to Tenants — the exact example the
| module doc opens with — came back as a raw duplicate-key QueryException, i.e. the 500 page, on an
| ordinary create by an operator doing an ordinary thing.
|
| Two layers, one wording. The FORM refuses inline, so everything else the operator typed survives
| (a `DomainException` renders as a toast with a redirect back, and the form is gone). The MODEL is
| the gate an import, the console or a crafted payload meets — `model` is disabled on the form and
| still dehydrated, so a payload can carry a different one and MOVE a definition onto a taken pair.
| Both read `CustomField::keyConflictRefusal()`, so the inline error and the toast cannot say
| different things.
|
| The DEACTIVATED case gets its own sentence, because the escape is the opposite one: the operator
| wants that field back, not a second one — a duplicate could never read the answers already
| recorded under the key.
|
| The unique index stays the backstop for the race neither guard can close.
*/
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(makeUser('super_admin'));
    // The catalogue is portfolio-shared and opts out of the panel's tenancy scope, but the PANEL
    // still has tenancy — a Filament page rendered with no tenant is a different failure from the
    // one under test.
    Filament::setTenant(makeAsset(['code' => 'CFLD']));

    $this->existing = CustomField::create([
        'model' => 'tenant',
        'key' => 'parent_group',
        'label_en' => 'Parent group',
        'label_ar' => 'المجموعة الأم',
        'type' => 'text',
    ]);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('refuses a second definition on a key this record type already carries', function () {
    expect(fn () => CustomField::create([
        'model' => 'tenant',
        'key' => 'parent_group',
        'label_en' => 'Group',
        'label_ar' => 'المجموعة',
        'type' => 'text',
    ]))->toThrow(DomainException::class);

    expect(CustomField::query()->where('model', 'tenant')->where('key', 'parent_group')->count())->toBe(1);
});

it('lets two record types both carry the same key', function () {
    // The control, and the reason the index is per MODEL: the migration says so in writing — two
    // record types may both sensibly have a `parent_group`, and forcing one of them to be
    // `tenant_parent_group` would be this system's naming leaking into the operator's vocabulary.
    $onALease = CustomField::create([
        'model' => 'lease',
        'key' => 'parent_group',
        'label_en' => 'Parent group',
        'label_ar' => 'المجموعة الأم',
        'type' => 'text',
    ]);

    expect($onALease->exists)->toBeTrue();
});

it('lets the definition that holds the key be saved again', function () {
    // The other control, and the one a naive guard fails: a row must not collide with itself, or a
    // rename, a reorder or a deactivation becomes impossible.
    $this->existing->update(['label_en' => 'Buying group', 'sort_order' => 3]);

    expect($this->existing->fresh()->label_en)->toBe('Buying group');
});

it('names the switched-off field the operator should turn back on', function () {
    // The two sentences differ because the escapes do. While the field is live the answer is
    // "choose another key"; once it is retired the answer is "turn that one back on", because every
    // answer already recorded sits under it and a second definition could never read them.
    expect(CustomField::keyConflictRefusal('tenant', 'parent_group')['key'])
        ->toBe('admin.refusals.cf_key_taken');

    $this->existing->update(['is_active' => false]);

    $refusal = CustomField::keyConflictRefusal('tenant', 'parent_group');

    expect($refusal['key'])->toBe('admin.refusals.cf_key_taken_inactive')
        // Resolved, not left as a raw key, and carrying the field the operator has to go and find.
        ->and(__($refusal['key'], $refusal['replace']))
        ->toContain('parent_group')
        ->and(__($refusal['key'], $refusal['replace']))
        ->toContain('Parent group');

    expect(CustomField::keyConflictRefusal('tenant', 'somewhere_else'))->toBeNull();
});

it('refuses the duplicate on the definition form, keeping what was typed', function () {
    Livewire::test(CreateCustomField::class)
        ->fillForm([
            'model' => 'tenant',
            'key' => 'parent_group',
            'label_en' => 'Group',
            'label_ar' => 'المجموعة',
            'type' => 'text',
        ])
        ->call('create')
        ->assertHasFormErrors(['key']);

    expect(CustomField::query()->count())->toBe(1);
});

it('still creates a definition on a free key', function () {
    // Paired with the refusal above: a rule that refused everything would satisfy that test alone.
    Livewire::test(CreateCustomField::class)
        ->fillForm([
            'model' => 'tenant',
            'key' => 'buying_office',
            'label_en' => 'Buying office',
            'label_ar' => 'مكتب المشتريات',
            'type' => 'text',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(CustomField::query()->where('key', 'buying_office')->exists())->toBeTrue();
});
