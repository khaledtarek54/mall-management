<?php

use App\Filament\Admin\Resources\DocumentTemplates\Pages\CreateDocumentTemplate;
use App\Models\DocumentTemplate;
use App\Support\DocumentText;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * SW-119 — the HOUSE row's uniqueness was unenforced at BOTH layers, so the wording on every
 * invoice could become a coin toss.
 *
 * `document_templates` is one row per block per property, and a null `asset_id` is the HOUSE
 * default every mall inherits. The migration that created the table says so in writing: *"One row
 * per block per property. Without this a second row is a silent tie the resolver would break by
 * insertion order, which is nobody's decision."*
 *
 * Neither layer that was supposed to make that true could see the house row.
 *
 *  - **The form.** Its `Rule::unique` scoped itself with `$get('asset_id')`, and this screen's
 *    property control is `PropertyField::scope()` — a `Radio` whose blank state is the STRING `''`,
 *    not null (`->default('')`, `formatStateUsing()` returns `''`). Measured on the dev database,
 *    the rule compiled to `select * from document_templates where "key" = ? and ("asset_id" = ?)`
 *    with the bindings `["invoice.footer", ""]`. `asset_id = ''` can never match a NULL row — it is
 *    UNKNOWN in three-valued logic — so the check never fired for the one scope where it is the
 *    only check there is.
 *  - **The index.** `SHOW CREATE TABLE document_templates` gives
 *    `UNIQUE KEY document_templates_key_asset_id_unique (key, asset_id)` over a NULLABLE column,
 *    and MySQL — like SQLite — permits unlimited duplicate rows where one side is NULL.
 *
 * So a second house footer saved cleanly, and `DocumentText::operatorText()` then chose between the
 * two by whatever order the storage engine returned. An operator editing one of them would see the
 * invoice keep the other one's wording, with nothing on screen to say why.
 *
 * Both halves are fixed and both are tested here, because they fail in different places: the form
 * clamp is what turns this into a FIELD ERROR the operator can act on, and the model guard is what
 * an import, a seeder or a crafted payload meets.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
});

it('refuses a second house wording block on the form, where the blank scope is an empty string', function () {
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $here = makeAsset(['code' => 'AW', 'name' => 'Atriom Walk']);
    Filament::setTenant($here);

    try {
        // The house row. `asset_id => ''` is exactly what the Radio submits for "All properties".
        Livewire::test(CreateDocumentTemplate::class)
            ->fillForm(['key' => 'invoice.footer', 'asset_id' => '', 'body_en' => 'Pay within 7 days.'])
            ->call('create')
            ->assertHasNoFormErrors();

        // The second one. A FIELD error on `key`, not a toast and not a saved row.
        Livewire::test(CreateDocumentTemplate::class)
            ->fillForm(['key' => 'invoice.footer', 'asset_id' => '', 'body_en' => 'A second house footer.'])
            ->call('create')
            ->assertHasFormErrors(['key']);

        expect(DocumentTemplate::query()->where('key', 'invoice.footer')->whereNull('asset_id')->count())
            ->toBe(1, 'A second HOUSE wording block for one document slot was written.');

        // THE CONTROL. The same block for THIS mall is a different row and is the whole point of the
        // screen — an override. A guard that refused it would have broken the feature instead.
        Livewire::test(CreateDocumentTemplate::class)
            ->fillForm(['key' => 'invoice.footer', 'asset_id' => (string) $here->id, 'body_en' => 'Pay into the AW account.'])
            ->call('create')
            ->assertHasNoFormErrors();

        expect(DocumentTemplate::query()->where('key', 'invoice.footer')->count())->toBe(2);
    } finally {
        Filament::setTenant(null, isQuiet: true);
    }
});

it('refuses a second house wording block at the model, where no index can see a NULL', function () {
    DocumentTemplate::create(['key' => 'invoice.footer', 'asset_id' => null, 'body_en' => 'Pay within 7 days.']);

    expect(fn () => DocumentTemplate::create([
        'key' => 'invoice.footer',
        'asset_id' => null,
        'body_en' => 'A second house footer.',
    ]))->toThrow(DomainException::class);

    expect(DocumentTemplate::query()->where('key', 'invoice.footer')->count())->toBe(1);

    // The consequence the guard exists for: one row, so one answer, whoever asks.
    expect(DocumentText::for('invoice.footer', null, ['days' => 7]))->toBe('Pay within 7 days.');

    // ── CONTROLS. A guard that refused everything would satisfy the refusal above. ──────────────
    $mall = makeAsset(['code' => 'AW']);

    expect(DocumentTemplate::create(['key' => 'invoice.footer', 'asset_id' => $mall->id, 'body_en' => 'AW override'])->exists)
        ->toBeTrue('A mall override is a different row and must still be writable.');

    expect(DocumentTemplate::create(['key' => 'invoice.terms', 'asset_id' => null, 'body_en' => 'House terms'])->exists)
        ->toBeTrue('A different block on the house scope is a different row.');

    // And a row is not a clash with ITSELF — rewording the house footer has to keep working, or the
    // guard would make every existing template uneditable (the `#[NeverDeletable]` trap). The guard
    // only looks at a write that creates or MOVES the pair, for exactly that reason.
    $house = DocumentTemplate::query()->where('key', 'invoice.footer')->whereNull('asset_id')->firstOrFail();
    $house->update(['body_en' => 'Pay within 14 days.']);

    expect($house->fresh()->body_en)->toBe('Pay within 14 days.');

    // The other side of the same clash, and the tooth that proves the dirty-only check has not
    // swallowed the rule: MOVING the mall override onto the house scope is a duplicate too. The
    // form disables `asset_id` on edit — and a disabled input is a statement of intent, never a
    // gate, so this is the path an import or a crafted payload takes.
    $override = DocumentTemplate::query()->where('key', 'invoice.footer')->whereNotNull('asset_id')->firstOrFail();

    expect(fn () => $override->update(['asset_id' => null]))->toThrow(DomainException::class);

    expect((int) $override->fresh()->asset_id)->toBe($mall->id);
});
