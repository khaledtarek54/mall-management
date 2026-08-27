<?php

/**
 * A logged code renders as WORDS, in the reader's language — including the ones the denylist flip
 * brought into the trail.
 *
 * Inverting the audit trail to a denylist on 2026-08-24 widened it from 598 operator-settable
 * columns to 1,034. The vocabulary never followed, and the gate that should have said so
 * (`LoggedValuesResolveConformanceTest`) discovers columns by reading `$options->logAttributes` —
 * which the same flip had emptied, because `ActivityLogging::for()` composes `logFillable()` and
 * names almost nothing explicitly. So the sweep walked 85 models, found ONE column, and passed.
 *
 * Measured once it could see again: **38 logged code-valued columns had no vocabulary**, so an
 * Arabic reader met `straight_line`, `per_day` and `arrears` sitting in an otherwise Arabic diff —
 * on the screen whose entire design principle is that a row stores DATA and resolves prose at READ
 * time, precisely so it can be read in either language.
 *
 * This renders the real thing rather than asserting the registry: three columns, three different
 * resolution paths, both locales.
 */

use App\Filament\Admin\Resources\FixedAssets\Schemas\FixedAssetForm;
use App\Models\CamExpensePool;
use App\Models\PaymentMethod;
use App\Support\ActivityVocabulary;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\App;

afterEach(fn () => App::setLocale('en'));

it('renders a lang-group code in both languages', function (string $locale, string $expected) {
    App::setLocale($locale);

    // `fixed_assets.method` — one of the 38. Before the fix this printed `straight_line`.
    expect(app(ActivityVocabulary::class)->value('fixed_asset', null, 'method', 'straight_line'))
        ->toBe($expected);
})->with([
    ['en', 'Straight line'],
    ['ar', 'القسط الثابت'],
]);

it('renders a CAM basis through the same words its form shows', function () {
    // The CAM bases were labelled by individual keys (`admin.cam.basis_ledger`), which
    // `valueKey()` can never resolve — it looks up `{prefix}.{value}`. The value-keyed groups reuse
    // the SAME strings, so the trail and the form cannot drift into two vocabularies.
    App::setLocale('ar');

    $rendered = app(ActivityVocabulary::class)
        ->value('cam_pool', null, 'expense_basis', CamExpensePool::BASIS_LEDGER);

    expect($rendered)->toBe(__('admin.cam.basis_ledger'))
        ->and($rendered)->not->toBe(CamExpensePool::BASIS_LEDGER);
});

it('labels a payment rail the operator added after this code shipped', function () {
    // The case no lang group can ever cover, and the reason these columns resolve through the
    // CATALOGUE instead: `payment_methods` is a register an operator extends without a deploy.
    // Pointed at `admin.enums.method`, the trail would print `admin.enums.method.fawry`.
    PaymentMethod::create([
        'code' => 'fawry',
        'name_en' => 'Fawry',
        'name_ar' => 'فوري',
        'for_inbound' => true,
        'is_active' => true,
    ]);

    expect(ActivityVocabulary::catalogueFor('payment', 'method'))->toBe(PaymentMethod::class);

    // Through `value()` — the method the activity-log TABLE calls — not through `labelFor()`
    // directly. Asserting the catalogue in isolation proves the catalogue works; it says nothing
    // about whether the trail consults it, and a first version of this test stayed green with the
    // whole branch deleted.
    App::setLocale('ar');
    expect(app(ActivityVocabulary::class)->value('payment', null, 'method', 'fawry'))->toBe('فوري');

    App::setLocale('en');
    expect(app(ActivityVocabulary::class)->value('payment', null, 'method', 'fawry'))->toBe('Fawry');
});

it('leaves a currency verbatim, and says why', function () {
    // The deliberate non-translation, registered rather than silently skipped: an ISO 4217 code is
    // an identifier the operator reconciles against a bank statement, not a word.
    expect(ActivityVocabulary::verbatimReason('currency'))->not->toBeNull()
        ->and(ActivityVocabulary::catalogueFor('invoice', 'currency'))->toBeNull()
        ->and(app(ActivityVocabulary::class)->value('invoice', null, 'currency', 'EGP'))->toBe('EGP');
});

it('keeps the fixed-asset funding field in the reader\'s language too', function () {
    // Found while giving that column a vocabulary: the FORM rendered
    // `['cash' => 'Cash', 'bank' => 'Bank']` — hardcoded English on the Arabic panel. The trail and
    // the form now read the same group.
    App::setLocale('ar');

    $options = FixedAssetForm::configure(app(Schema::class))->getComponents();

    $funded = collect($options)
        ->first(fn ($component) => method_exists($component, 'getName') && $component->getName() === 'funded_from');

    expect($funded)->not->toBeNull('The funded_from field has moved — this test no longer proves anything.')
        ->and($funded->getOptions())->toBe(['cash' => 'نقدًا', 'bank' => 'بنك']);
});
