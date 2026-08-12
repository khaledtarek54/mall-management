<?php

use App\Support\CategorySuggestions;

/**
 * A free-form category column has to do two things at once, and getting either wrong is
 * invisible until an Arabic operator opens the dropdown:
 *
 *   · the categories WE seed must read in the operator's language;
 *   · a category THEY typed must come back exactly as typed — it has no translation, and
 *     silently substituting one (or blanking it) would corrupt what they see.
 *
 * The English suggestions used to be inlined in the form as the option label AND the table
 * cell, so the Arabic panel showed "furniture / HVAC / spare_parts".
 */
it('translates a seeded category', function () {
    app()->setLocale('ar');
    expect(CategorySuggestions::label('fixed_asset', 'HVAC'))->toBe('تكييف وتهوية');
    expect(CategorySuggestions::label('warehouse', 'spare_parts'))->toBe('قطع غيار');

    app()->setLocale('en');
    expect(CategorySuggestions::label('fixed_asset', 'HVAC'))->toBe('HVAC');
    expect(CategorySuggestions::label('warehouse', 'spare_parts'))->toBe('Spare parts');
});

it('returns an operator-invented category exactly as typed, in both locales', function () {
    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);

        expect(CategorySuggestions::label('fixed_asset', 'مولدات كهربائية'))->toBe('مولدات كهربائية');
        expect(CategorySuggestions::label('warehouse', 'Cold storage'))->toBe('Cold storage');
    }
});

it('keeps the STORED value as the option key so no row is orphaned', function () {
    app()->setLocale('ar');

    $options = CategorySuggestions::options('fixed_asset', CategorySuggestions::FIXED_ASSET, []);

    // Keys are what lands in the database; labels are only what is shown.
    expect(array_keys($options))->toBe(CategorySuggestions::FIXED_ASSET);
    expect($options['HVAC'])->toBe('تكييف وتهوية');
});

it('keeps the current value selectable even when it is not a suggestion', function () {
    // Filament applies an implicit `in:options` rule, so a stored-but-unlisted category would
    // be rejected on save — an operator editing an old record could not press Save at all.
    $options = CategorySuggestions::options(
        'warehouse',
        CategorySuggestions::WAREHOUSE,
        [],
        'legacy free text',
    );

    expect($options)->toHaveKey('legacy free text');
});

it('never emits a null or empty option', function () {
    $options = CategorySuggestions::options('warehouse', CategorySuggestions::WAREHOUSE, [null, '', 'in_use'], null);

    expect(array_keys($options))->not->toContain('')
        ->and(array_filter($options, fn ($v) => $v === null || $v === ''))->toBe([]);
    expect($options)->toHaveKey('in_use');
});
