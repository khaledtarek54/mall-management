<?php

use App\Filament\Admin\Resources\Tenants\Pages\CreateTenant;
use App\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use App\Filament\Admin\Resources\Tenants\Pages\ListTenants;
use App\Filament\Admin\Resources\Tenants\Pages\ViewTenant;
use App\Filament\Exports\TenantExporter;
use App\Filament\Imports\TenantImporter;
use App\Models\CustomField;
use App\Models\Tenant;
use App\Support\CustomFields;
use App\Support\Filament\CustomFieldsSchema;
use App\Support\Filament\CustomFieldsTable;
use App\Support\Search\SearchText;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Imports\Models\Import;
use Livewire\Livewire;

/**
 * D-7 / EG-32 — user-defined fields, the largest structural gap this system had against Yardi UDFs,
 * MRI user-defined fields and Odoo Studio.
 *
 * Every operator eventually needs to record something the vendor never modelled. Without somewhere
 * to put it the fact goes in the notes box, where nothing can filter, report or export it — or they
 * ask for a deploy, and pay for one, every time.
 *
 * **The storage was already here and read by nothing.** `tenants`, `leases`, `assets`, `vendors` and
 * `departments` have carried a nullable `metadata` JSON column since the first migrations; all are
 * fillable, all are cast to array, and not one was written or read by any form, table, service,
 * report or export. The audit that raised D-7 counted them as evidence of the gap. They are also
 * its answer.
 *
 * The properties worth pinning are the ones where this shape can quietly lose or leak data:
 * `metadata` being fillable makes it a mass-assignment surface, and a definition is the ADDRESS of
 * every answer already recorded.
 */
function defineField(array $attrs = []): CustomField
{
    // `$attrs` FIRST — PHP's `+` keeps the LEFT operand's key, so defaults on the left silently
    // discard every argument.
    return CustomField::create($attrs + [
        'model' => 'tenant',
        'key' => 'parent_group',
        'label_en' => 'Parent group',
        'label_ar' => 'المجموعة الأم',
        'type' => 'text',
    ]);
}

it('writes only keys the catalogue defines, and discards the rest', function () {
    // The one that matters most. `metadata` is fillable on every model that has it, and a JSON
    // column accepts anything without complaint — so a crafted Livewire payload could otherwise
    // write arbitrary keys onto the record through an ordinary form.
    defineField();
    $tenant = makeTenant();

    $tenant->fillCustomFields([
        'parent_group' => 'Americana',
        'not_a_field' => 'should never be stored',
    ])->save();

    expect($tenant->fresh()->customFieldValues())->toBe(['parent_group' => 'Americana']);
});

it('stores a value in the shape its type promises', function () {
    // A form posts strings. Storing "12" for a number means every later reader has to remember to
    // cast, and the one that forgets compares a string.
    defineField(['key' => 'units_worldwide', 'type' => 'number']);
    defineField(['key' => 'listed', 'type' => 'boolean']);

    $tenant = makeTenant();
    $tenant->fillCustomFields(['units_worldwide' => '120', 'listed' => '1'])->save();

    $values = $tenant->fresh()->customFieldValues();

    expect($values['units_worldwide'])->toBe(120)
        ->and($values['listed'])->toBeTrue();
});

it('removes a cleared answer rather than storing a null', function () {
    // Otherwise `metadata` accumulates a null for every field anybody ever left blank, and
    // "recorded as empty" becomes indistinguishable from "never answered".
    defineField();
    $tenant = makeTenant();

    $tenant->fillCustomFields(['parent_group' => 'Americana'])->save();
    expect($tenant->fresh()->customFieldValues())->toHaveKey('parent_group');

    $tenant->fresh()->fillCustomFields(['parent_group' => ''])->save();

    expect($tenant->fresh()->customFieldValues())->toBe([])
        // Emptied entirely, the column goes back to null rather than holding an empty object.
        ->and($tenant->fresh()->metadata)->toBeNull();
});

it('merges into what is already recorded instead of replacing it', function () {
    // A form that only carries the ACTIVE fields must not wipe an answer given under one that has
    // since been retired. This is the quiet data-loss case the merge exists for.
    defineField();
    $retired = defineField(['key' => 'old_reference', 'label_en' => 'Old reference']);

    $tenant = makeTenant();
    $tenant->fillCustomFields(['parent_group' => 'Americana', 'old_reference' => 'REF-1'])->save();

    $retired->update(['is_active' => false]);

    // The form now offers only `parent_group`, and saving it must leave the retired answer alone.
    $tenant->fresh()->fillCustomFields(['parent_group' => 'Alshaya'])->save();

    expect($tenant->fresh()->customFieldValues())
        ->toBe(['parent_group' => 'Alshaya', 'old_reference' => 'REF-1']);
});

it('stops offering a deactivated field but keeps showing what it recorded', function () {
    $field = defineField();
    $tenant = makeTenant();
    $tenant->fillCustomFields(['parent_group' => 'Americana'])->save();

    $field->update(['is_active' => false]);
    $tenant = $tenant->fresh();

    expect($tenant->customFieldsForForm()->pluck('key')->all())->toBe([])
        // Still on the record, and still labelled — a field retired half way through a year still
        // explains what is on the records that carry it.
        ->and($tenant->customFieldsForDisplay()->pluck('key')->all())->toBe(['parent_group'])
        ->and($tenant->customFieldValue('parent_group'))->toBe('Americana');
});

it('still shows a value whose definition was deleted outright, labelled by its key', function () {
    // A display that only iterates the catalogue would make a stranded answer invisible while it is
    // still on the record — which reads as "nothing here" rather than "this can no longer be named".
    $tenant = makeTenant();
    $tenant->metadata = ['ghost_key' => 'still on the record'];
    $tenant->saveQuietly();

    $shown = $tenant->fresh()->customFieldsForDisplay();

    expect($shown->pluck('key')->all())->toBe(['ghost_key'])
        ->and($shown->first()->label())->toBe('ghost_key');
});

it('refuses to delete a field somebody has answered, and allows it before that', function () {
    $field = defineField();

    // Nothing has answered it — a mistyped field is a mistake worth clearing.
    expect($field->deletionBlockers())->toBe([])
        ->and($field->isDeletableNow())->toBeTrue();

    $tenant = makeTenant();
    $tenant->fillCustomFields(['parent_group' => 'Americana'])->save();

    expect($field->fresh()->deletionBlockers())->toBe(['1 tenant'])
        ->and(fn () => $field->fresh()->delete())->toThrow(DomainException::class);

    // The control: once the answer is gone the definition is removable again, so this is a real
    // guard rather than a blanket refusal.
    $tenant->metadata = null;
    $tenant->saveQuietly();

    $field->fresh()->delete();

    expect(CustomField::whereKey($field->id)->exists())->toBeFalse();
});

it('refuses to rename the key, because it is the address of every answer', function () {
    $field = defineField();

    expect(fn () => $field->update(['key' => 'group_parent']))->toThrow(DomainException::class);

    // Refreshed, because a refused save leaves the rejected value on the in-memory instance and the
    // next save would trip the same guard. A real request gets a fresh model; a test has to say so.
    $field = $field->fresh();

    expect($field->key)->toBe('parent_group');

    // The control: the LABEL is what an operator renames, and it reaches every record at once
    // because a label is resolved at read time.
    $field->update(['label_en' => 'Buying group']);

    expect($field->fresh()->label())->toBe('Buying group');
});

it('refuses a record type the operator may not extend', function () {
    // Not a UI concern: the form offers only the register, and this is what a crafted payload or
    // an import meets. An invoice is evidence — an operator-defined field on one is a place to
    // record something onto a document nobody can reconstruct later.
    expect(fn () => defineField(['model' => 'invoice']))->toThrow(DomainException::class);

    // The control, so this is not passing because every create is refused.
    expect(defineField(['model' => 'vendor'])->exists)->toBeTrue();
});

it('sees a field the operator just added, in the same request', function () {
    // The catalogue is memoised per request and a write fires no event on the resolver. Without the
    // flush hook a field just added would stay invisible for the rest of the request — and for the
    // rest of the day on a `queue:work` daemon.
    expect(CustomFields::for('tenant'))->toHaveCount(0);

    defineField();

    expect(CustomFields::for('tenant'))->toHaveCount(1);
});

it('renders the operator’s fields into a form, and nothing at all when none are defined', function () {
    // A fresh install defines no fields, and an empty "Additional information" heading on every
    // form is a permanent invitation to a screen that does nothing.
    $empty = CustomFieldsSchema::form('tenant');
    expect($empty[0]->isHidden())->toBeTrue();

    defineField();

    // Re-built, because the section decides per render — that is what lets it appear the moment
    // the first definition is saved.
    expect(CustomFieldsSchema::form('tenant')[0]->isHidden())->toBeFalse();
});

it('reads a choice field back by its label, in the reader’s language', function () {
    $field = defineField([
        'key' => 'segment',
        'type' => 'select',
        'options' => [
            ['value' => 'f_and_b', 'label_en' => 'Food & beverage', 'label_ar' => 'أغذية ومشروبات'],
            ['value' => 'fashion', 'label_en' => 'Fashion', 'label_ar' => 'أزياء'],
        ],
    ]);

    expect($field->choices())->toBe(['f_and_b' => 'Food & beverage', 'fashion' => 'Fashion']);

    app()->setLocale('ar');

    // The row carries BOTH labels, so a dropdown an Arabic-speaking operator reads does not fall
    // back to English for half its entries.
    expect($field->choices()['f_and_b'])->toBe('أغذية ومشروبات')
        ->and($field->label())->toBe('المجموعة الأم');

    app()->setLocale('en');
});

it('refuses a choice field with no choices', function () {
    // A dropdown that can never be answered, which `is_required` would then make the whole record
    // unsaveable behind.
    expect(fn () => defineField(['key' => 'empty_choice', 'type' => 'select', 'options' => []]))
        ->toThrow(DomainException::class);
});

it('fills in on the real create form and comes back on the edit form', function () {
    // Building a schema in a test proves nothing — the trap this codebase has been bitten by twice.
    // This drives the actual Filament pages: a Create that saves an answer, and an Edit that has to
    // find it again, which is where a virtual attribute either binds or silently does not.
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $asset = makeAsset();
    $this->actingAs(makeUser('manager', [$asset->id]));

    defineField();

    asTenant($asset, function () use ($asset) {
        Livewire::test(CreateTenant::class)
            ->fillForm([
                'name' => 'Americana Egypt',
                'asset_id' => $asset->id,
                'address_governorate' => 'Cairo',
                'address_city' => 'New Cairo',
                'address_street' => 'Road 90',
                'address_building_number' => '12',
                'custom_fields.parent_group' => 'Americana Group',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $tenant = Tenant::where('name', 'Americana Egypt')->sole();

        expect($tenant->customFieldValue('parent_group'))->toBe('Americana Group');

        // …and the edit form finds it again. Without the getter the box would open empty and the
        // next save would look like the operator had cleared it.
        Livewire::test(EditTenant::class, ['record' => $tenant->getRouteKey()])
            ->assertFormSet(['custom_fields.parent_group' => 'Americana Group']);
    });
});

it('never lets a form write a key the catalogue does not define', function () {
    // The mass-assignment case, driven through the real page rather than the model. `metadata` is a
    // JSON column that accepts anything, and it used to be fillable on all five models.
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $asset = makeAsset();
    $this->actingAs(makeUser('manager', [$asset->id]));

    defineField();

    asTenant($asset, function () use ($asset) {
        Livewire::test(CreateTenant::class)
            ->fillForm([
                'name' => 'Crafted',
                'asset_id' => $asset->id,
                'address_governorate' => 'Cairo',
                'address_city' => 'New Cairo',
                'address_street' => 'Road 90',
                'address_building_number' => '12',
            ])
            // Straight into the Livewire payload, past the form's own fields.
            ->set('data.custom_fields.parent_group', 'Legitimate')
            ->set('data.custom_fields.smuggled', 'should never land')
            ->set('data.metadata', ['smuggled_column' => 'should never land'])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(Tenant::where('name', 'Crafted')->sole()->customFieldValues())
            ->toBe(['parent_group' => 'Legitimate']);
    });
});

it('shows an answered field on the record page, and hides the section when none are', function () {
    // The other half of the same capability. A field an operator can fill in and never read back is
    // the shape this codebase keeps finding — data typed once and invisible thereafter.
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $asset = makeAsset();
    $this->actingAs(makeUser('manager', [$asset->id]));

    defineField();
    $tenant = makeTenant(['asset_id' => $asset->id]);

    asTenant($asset, function () use ($tenant) {
        // Nothing answered yet — the section must not appear as an empty heading.
        Livewire::test(ViewTenant::class, ['record' => $tenant->getRouteKey()])
            ->assertDontSee('Parent group');

        $tenant->fillCustomFields(['parent_group' => 'Americana Group'])->save();

        Livewire::test(ViewTenant::class, ['record' => $tenant->fresh()->getRouteKey()])
            ->assertSee('Parent group')
            ->assertSee('Americana Group');
    });
});

it('refuses a key Filament would read as nesting', function () {
    // A dot is NESTING in a form state path, so `parent.group` would become a two-level array and
    // the answer would never reach `metadata` under that key — a field that silently records
    // nothing. The definition form carries the same rule; this is the gate an import meets.
    foreach (['parent.group', 'Parent_Group', '9lives', 'has space', ''] as $bad) {
        expect(fn () => defineField(['key' => $bad]))->toThrow(DomainException::class);
    }

    // The control, so this is not passing because every create is refused.
    expect(defineField(['key' => 'parent_group2'])->exists)->toBeTrue();
});

/*
|--------------------------------------------------------------------------
| Slice 3 — the answers are usable, not just recorded
|--------------------------------------------------------------------------
|
| An operator who records a parent buying group on two hundred tenants wants a LIST by parent group
| and a spreadsheet of it — which is the whole reason they asked for the field. A value you can type
| and never group by is the notes box with extra steps.
|
| Display reads through the model; querying reads through SQL (`metadata->{key}`), because filtering
| and sorting have to happen in the database — a collection filter pages wrongly and a collection
| sort only orders the rows already fetched.
*/

/** A tenant on this asset carrying these answers. */
function tenantAnswering(array $values, array $attrs = []): Tenant
{
    $tenant = makeTenant($attrs + ['asset_id' => test()->cfAsset->id]);
    $tenant->fillCustomFields($values)->save();

    return $tenant->fresh();
}

it('filters a list by a custom field, in the database', function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->cfAsset = makeAsset();
    $this->actingAs(makeUser('manager', [$this->cfAsset->id]));

    defineField();
    defineField([
        'key' => 'segment', 'type' => 'select', 'label_en' => 'Segment',
        'options' => [
            ['value' => 'f_and_b', 'label_en' => 'Food & beverage', 'label_ar' => 'أغذية'],
            ['value' => 'fashion', 'label_en' => 'Fashion', 'label_ar' => 'أزياء'],
        ],
    ]);

    $fnb = tenantAnswering(['parent_group' => 'Americana Group', 'segment' => 'f_and_b']);
    $fashion = tenantAnswering(['parent_group' => 'Alshaya', 'segment' => 'fashion']);
    $unanswered = tenantAnswering([]);

    asTenant($this->cfAsset, function () use ($fnb, $fashion, $unanswered) {
        Livewire::withQueryParams([]);

        // The control comes FIRST, and that ordering is load-bearing: this panel sets
        // `persistFiltersInSession()`, so a filter applied by one component is still applied when
        // the next one mounts. A control asserted last would fail against filters the earlier
        // assertions had left behind, and would read as the list being broken.
        Livewire::test(ListTenants::class)
            ->assertCanSeeTableRecords([$fnb, $fashion, $unanswered]);

        // A choice field filters on the STORED value.
        Livewire::test(ListTenants::class)
            ->filterTable('cf_segment', ['value' => 'f_and_b'])
            ->assertCanSeeTableRecords([$fnb])
            ->assertCanNotSeeTableRecords([$fashion, $unanswered])
            ->resetTableFilters();

        // Text CONTAINS — an operator looking for "Americana" should not have to remember whether
        // they typed "Americana Group".
        Livewire::test(ListTenants::class)
            ->filterTable('cf_parent_group', ['value' => 'americana'])
            ->assertCanSeeTableRecords([$fnb])
            ->assertCanNotSeeTableRecords([$fashion])
            ->resetTableFilters();
    });
});

it('excludes a record that never answered, rather than treating it as empty', function () {
    // `NULL` at the JSON path fails every comparison, which is the right default: "no parent group
    // recorded" is not "parent group is empty".
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->cfAsset = makeAsset();
    $this->actingAs(makeUser('manager', [$this->cfAsset->id]));

    defineField();
    $answered = tenantAnswering(['parent_group' => 'Americana Group']);
    $never = tenantAnswering([]);

    asTenant($this->cfAsset, function () use ($answered, $never) {
        Livewire::withQueryParams([]);

        Livewire::test(ListTenants::class)
            ->filterTable('cf_parent_group', ['value' => 'a'])
            ->assertCanSeeTableRecords([$answered])
            ->assertCanNotSeeTableRecords([$never]);
    });
});

it('sorts by a custom field in the database, not in the fetched page', function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->cfAsset = makeAsset();
    $this->actingAs(makeUser('manager', [$this->cfAsset->id]));

    defineField();
    $c = tenantAnswering(['parent_group' => 'Cedar']);
    $a = tenantAnswering(['parent_group' => 'Americana']);
    $b = tenantAnswering(['parent_group' => 'Binghatti']);

    asTenant($this->cfAsset, function () use ($a, $b, $c) {
        Livewire::withQueryParams([]);

        Livewire::test(ListTenants::class)
            ->sortTable('custom_fields.parent_group')
            ->assertCanSeeTableRecords([$a, $b, $c], inOrder: true)
            ->sortTable('custom_fields.parent_group', 'desc')
            ->assertCanSeeTableRecords([$c, $b, $a], inOrder: true);
    });
});

it('offers a column per field, hidden until the operator asks for it', function () {
    // An operator who defines eight fields must not find eight new columns on a list they were
    // happy with. They turn one on — and EG-32 slice 1 lets them save that choice as a view.
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->cfAsset = makeAsset();
    $this->actingAs(makeUser('manager', [$this->cfAsset->id]));

    defineField();

    asTenant($this->cfAsset, function () {
        Livewire::withQueryParams([]);
        $page = Livewire::test(ListTenants::class);

        expect($page->instance()->isTableColumnToggledHidden('custom_fields.parent_group'))->toBeTrue()
            // The control: a shipped column is on, so this is the DEFAULT being hidden rather than
            // the whole column manager reporting everything as hidden.
            ->and($page->instance()->isTableColumnToggledHidden('name'))->toBeFalse();
    });
});

it('exports the answers, after the shipped columns', function () {
    // A field an operator can filter but not take away is still half a capability — the reason they
    // recorded it is usually a spreadsheet somebody else reads.
    defineField();
    defineField(['key' => 'segment', 'type' => 'select', 'label_en' => 'Segment', 'options' => [
        ['value' => 'f_and_b', 'label_en' => 'Food & beverage', 'label_ar' => 'أغذية'],
    ]]);

    $names = array_map(
        fn ($column) => $column->getName(),
        TenantExporter::getColumns(),
    );

    expect($names)->toContain('custom_fields.parent_group')
        ->and($names)->toContain('custom_fields.segment')
        // LAST, so an existing import template's column positions do not move under it.
        ->and(array_slice($names, -2))->toBe(['custom_fields.parent_group', 'custom_fields.segment'])
        ->and($names[0])->toBe('code');
});

it('does not offer a column or filter for a field that was retired', function () {
    // Same rule as the form: `is_active` stops a field being ASKED. A filter for a question nobody
    // is asked any more is a control that returns rows nobody can explain.
    $field = defineField();

    expect(CustomFieldsTable::columns('tenant'))->toHaveCount(1)
        ->and(CustomFieldsTable::filters('tenant'))->toHaveCount(1)
        ->and(CustomFieldsTable::exportColumns('tenant'))->toHaveCount(1);

    $field->update(['is_active' => false]);

    expect(CustomFieldsTable::columns('tenant'))->toBe([])
        ->and(CustomFieldsTable::filters('tenant'))->toBe([])
        ->and(CustomFieldsTable::exportColumns('tenant'))->toBe([]);
});

it('folds an answer into the record’s own search blob', function () {
    // The top bar could not find a tenant by their parent group. `metadata` is this row's own
    // attribute, so indexing it honours `searchTextSources()`'s one hard rule — never reach through
    // a relation — and the blob re-folds whenever the record saves.
    defineField();
    $tenant = makeTenant();

    expect($tenant->fresh()->search_text)->not->toContain('americana');

    $tenant->fillCustomFields(['parent_group' => 'Americana Group'])->save();

    expect($tenant->fresh()->search_text)->toContain('americana')
        // Found the way the search bar asks, with BOTH sides folded.
        ->and(Tenant::whereRaw('search_text like ?', ['%'.SearchText::normalize('Americana').'%'])->pluck('id'))
        ->toContain($tenant->id);
});

it('does not index a boolean answer as a search term', function () {
    // "1" is not a search term, and indexing it would match every record carrying any number.
    defineField(['key' => 'listed', 'type' => 'boolean']);
    $tenant = makeTenant();
    $before = $tenant->fresh()->search_text;

    $tenant->fillCustomFields(['listed' => true])->save();

    expect($tenant->fresh()->search_text)->toBe($before);
});

it('imports an answer from a spreadsheet, and refuses a key the catalogue never defined', function () {
    // The last direction the operator's own data could not travel. Routing through
    // `fillCustomFields()` means an import gets the same key filtering and casting a form does.
    defineField();
    defineField(['key' => 'units_worldwide', 'type' => 'number']);

    $columns = CustomFieldsTable::importColumns('tenant');
    $names = array_map(fn ($c) => $c->getName(), $columns);

    expect($names)->toBe(['cf_parent_group', 'cf_units_worldwide']);

    // Driven through the REAL importer, not by asserting a column exists: `fillRecordUsing` runs
    // inside Filament's own fill pass, and a column that never reaches the record looks identical
    // in a shape assertion.
    $import = new Import([
        'file_name' => 'tenants.csv',
        'file_path' => 'tenants.csv',
        'importer' => TenantImporter::class,
        'total_rows' => 1,
    ]);
    $import->user_id = makeUser('manager', [makeAsset()->id])->id;
    $import->save();

    (new TenantImporter($import, [
        'name' => 'Name',
        'email' => 'Email',
        'cf_parent_group' => 'Parent group',
        'cf_units_worldwide' => 'Units',
    ], []))([
        'Name' => 'Americana Egypt',
        'Email' => 'ops@americana.test',
        'Parent group' => 'Americana Group',
        'Units' => '120',
    ]);

    expect(Tenant::where('email', 'ops@americana.test')->sole()->customFieldValues())
        ->toBe(['parent_group' => 'Americana Group', 'units_worldwide' => 120]);
});
