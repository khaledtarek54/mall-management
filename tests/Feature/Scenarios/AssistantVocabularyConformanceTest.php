<?php

use App\Models\LedgerAccount;
use App\Services\Assistant\AnswerQuestionService;
use App\Support\Assistant\AssistantCorpus;
use App\Support\ScreenGuides;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Lang;

/**
 * The operator vocabulary is a REGISTRY, so it is gated like one.
 *
 * `admin.assistant.synonyms` carries the words people actually type — "receipt" for the Payments
 * screen, «شكوى» for Requests — keyed by screen-guide key. A key that is not a real screen key is
 * SILENTLY INERT: `AssistantCorpus::synonyms()` asks `trans()->has()`, gets false, and returns an
 * empty string, so the entry reads as configuration and does nothing at all. Three of the first
 * twenty-two were exactly that (`facility_work_orders` for `work_orders`, `deposit_transactions`
 * for `deposits`, `cam_pools` for `cam`), and the only symptom was a question routing to the wrong
 * screen — indistinguishable from the vocabulary simply being incomplete.
 *
 * The same shape this codebase already gates for `ValueSets`, `ScreenGuides` and the module keys.
 */
it('never carries a synonym under a screen key that does not exist', function (string $locale) {
    $keys = array_keys((array) Lang::get('admin.assistant.synonyms', [], $locale));
    $screens = array_values(ScreenGuides::SCREENS);

    expect($keys)->not->toBeEmpty();
    expect(array_values(array_diff($keys, $screens)))->toBe([]);
})->with(['en', 'ar']);

it('offers the same vocabulary in both languages', function () {
    // Not a translation of one another — an operator's word is written, not translated — but a
    // screen explained to one half of the office and not the other is the bilingual defect this
    // project keeps finding, so the KEYS must match.
    expect(array_keys((array) Lang::get('admin.assistant.synonyms', [], 'ar')))
        ->toBe(array_keys((array) Lang::get('admin.assistant.synonyms', [], 'en')));
});

it('writes the arabic vocabulary in arabic', function () {
    // `Lang::has()` cannot see an English string sitting in an Arabic key, which is the realistic
    // failure when a whole block is added in one pass and reviewed in English.
    foreach ((array) Lang::get('admin.assistant.synonyms', [], 'ar') as $key => $words) {
        expect((string) $words)->toMatch('/\p{Arabic}/u', "admin.assistant.synonyms.{$key} carries no Arabic");
    }
});

it('splits a hyphen so a screen can be found by the words in its own name', function () {
    // `SearchText::words()` welds a hyphen — right for a search box, wrong for natural language.
    // **Month-End Close** indexed as the single token `monthend`, so nobody typing the screen's own
    // name in words could reach it.
    expect(AssistantCorpus::tokenise('Month-End Close'))->toBe(['month', 'end', 'close']);
});

it('knows the verbs of operating the mall, in both languages', function (string $locale) {
    foreach (['admin.assistant.task.verbs', 'admin.assistant.act_verbs'] as $key) {
        // `Lang::has(..., fallback: false)`, never "is the string non-empty": a MISSING key returns
        // the key itself, which is non-empty and truthy — so the obvious assertion passes on
        // exactly the case it exists to catch. It did: an editing pass dropped `act_verbs` from the
        // English file and this test stayed green while every act-ordering question regressed.
        expect(Lang::has($key, $locale, fallback: false))->toBeTrue("{$key} is missing in {$locale}");
        expect(trim((string) Lang::get($key, [], $locale)))->not->toBe('');
    }
})->with(['en', 'ar']);

it('still lets somebody ask about a chart account by its code', function () {
    // A first attempt at the two misroutes above EXCLUDED the chart of accounts from the record
    // tier outright, on the theory that an account name is ordinary business vocabulary. Measured,
    // it fixed nothing — the act-verb ordering is what fixed them — and it broke a legitimate
    // question: "account 51109" could no longer reach account 51109. A restriction that costs a
    // real answer has to earn its place by measurement, and this one could not.
    $this->seed(RolesPermissionsSeeder::class);
    $asset = makeAsset();
    $this->actingAs(makeUser('super_admin'));

    LedgerAccount::query()->firstOrCreate(
        ['code' => '51109'],
        ['name_en' => 'Bad debt expense', 'name_ar' => 'مصروف ديون معدومة', 'type' => 'expense'],
    );

    asTenant($asset, function () {
        $results = app(AnswerQuestionService::class)->answer('account 51109')['results'];

        expect(collect($results)->pluck('title')->implode(' '))->toContain('51109');
    });
});
