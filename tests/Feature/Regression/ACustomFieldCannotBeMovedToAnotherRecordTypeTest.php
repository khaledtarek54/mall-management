<?php

use App\Models\CustomField;
use App\Support\CustomFields;

/**
 * SW-123 — `custom_fields.model` was not immutable, while three places said it was.
 *
 * The ADDRESS of every answer already recorded is the PAIR: `model` says which table's `metadata`
 * holds them and `key` says under which JSON key. The class docblock on `CustomField`,
 * `CustomFieldForm`'s docblock and docs/modules/38 §4 all state that both are frozen once the row
 * exists — §4 in so many words: *"immutable once the row exists (`CustomField::saving()` refuses the
 * change; the form disables both)"*.
 *
 * Measured 2026-09-04 at HEAD (83624504): `saving` refused `key` and said nothing about `model`. The
 * form does disable both — and both are `->dehydrated()`, so the value still arrives in the Livewire
 * payload, and a disabled input is a statement of intent and never a gate.
 *
 * The consequence is worse than a stranded key, and it is why this is a model guard rather than a
 * form rule. `deletionBlockers()` counts records of the model the row NOW names, so re-pointing a
 * definition from `tenant` to `lease` left 200 tenant answers in place under a key nothing offers or
 * reads AND emptied the blocker list — making the definition freely deletable, which is the one act
 * that turns a recoverable mistake into permanent orphaning. That is precisely what this model's
 * `#[DeletableWhenUnused]` exists to prevent.
 *
 * The honest way to move a field is the one the refusal names: define it on the other record type
 * and retire this one, which keeps both sets of answers readable.
 */
it('refuses to move a custom field to another record type, because the pair is the address', function () {
    $field = CustomField::create([
        'model' => 'tenant',
        'key' => 'parent_group',
        'label_en' => 'Parent group',
        'label_ar' => 'المجموعة الأم',
        'type' => 'text',
    ]);

    $tenant = makeTenant();
    $tenant->fillCustomFields(['parent_group' => 'Alpha Holdings']);
    $tenant->save();

    expect($field->deletionBlockers())->not->toBeEmpty();

    expect(fn () => $field->update(['model' => 'lease']))->toThrow(DomainException::class);

    // Refreshed, because a refused save leaves the rejected value on the in-memory instance — the
    // same note the key-immutability test beside this one carries.
    $field = $field->fresh();

    expect($field->model)->toBe('tenant');
    expect($tenant->fresh()->customFieldValue('parent_group'))->toBe('Alpha Holdings');

    // The half no form rule could have protected: the answer still counts, so the definition is
    // still undeletable.
    expect($field->deletionBlockers())->not->toBeEmpty();

    // ── CONTROLS. A guard that refused every update would satisfy the refusal above. ────────────
    $field->update(['label_en' => 'Buying group']);
    expect($field->fresh()->label())->toBe('Buying group');

    // Deactivating the field is the other half of the escape the refusal names.
    $field->update(['is_active' => false]);
    expect($field->fresh()->is_active)->toBeFalse();

    // And the honest way to move it: define it on the other record type. Both sets of answers stay
    // readable, which is the whole reason the move is refused rather than allowed.
    $onLease = CustomField::create([
        'model' => 'lease',
        'key' => 'parent_group',
        'label_en' => 'Parent group',
        'label_ar' => 'المجموعة الأم',
        'type' => 'text',
    ]);

    expect($onLease->exists)->toBeTrue();
    expect(CustomFields::for('lease')->pluck('key')->all())->toContain('parent_group');

    // And the retired tenant field still LABELS the answers already recorded — which is the whole
    // reason the move is refused rather than allowed.
    expect(CustomFields::including('tenant')->pluck('key')->all())->toContain('parent_group');
});
