<?php

use App\Support\ApiVocabulary;
use App\Support\ValueSets;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

/**
 * **The mobile API answers in Arabic to the same standard the panel does.**
 *
 * `ArabicPanelHasNoEnglishChromeConformanceTest` holds that line for the three Filament panels. The
 * API had no counterpart, and it fails differently: a panel renders words, while an API sends CODES
 * and leaves the words to the client. So "is it bilingual?" is really two questions —
 *
 *   1. is every SENTENCE it sends (`message`, refusals, validation) present in both languages, and
 *   2. can the client render every CODE it sends in both languages *without maintaining its own
 *      table* — which for the five operator-editable catalogues is not a preference but the only
 *      workable answer, since the accountant adds a charge code with no deploy on either side.
 *
 * `GET /me/vocabulary` answers (2), and this gate keeps it honest from the other end: it discovers
 * the classification fields the API resources actually EMIT and fails on one the registry does not
 * cover. A registry checked only against itself cannot see what it omits — the shape that let the
 * `ValueSets` suffix list drift behind its own registry.
 */

/** Classification-looking keys each API resource emits, as `resource.field` in camelCase. */
function apiClassificationFields(): array
{
    $suffixes = ['status', 'type', 'method', 'channel', 'category', 'reason', 'priority',
        'audience', 'mode', 'basis', 'frequency', 'platform', 'kind'];

    $fields = [];

    foreach (glob(base_path('app/Http/Resources/Api/V1/{,PublicFeed/}*.php'), GLOB_BRACE) as $file) {
        $resource = Str::of(basename($file, '.php'))->replaceLast('Resource', '')->camel()->toString();
        $source = file_get_contents($file);

        preg_match_all("/^\s{8,}'([a-z0-9_]+)'\s*=>/m", $source, $m);

        foreach ($m[1] as $key) {
            $tail = Str::afterLast($key, '_');

            if (! in_array($tail, $suffixes, true) && ! in_array($key, $suffixes, true)) {
                continue;
            }

            $fields[] = $resource.'.'.Str::camel($key);
        }
    }

    return array_values(array_unique($fields));
}

it('covers every classification the API emits, or says why it is not one', function () {
    $unexplained = [];

    foreach (apiClassificationFields() as $field) {
        if (array_key_exists($field, ApiVocabulary::VOCABULARIES)) {
            continue;
        }

        if (array_key_exists($field, ApiVocabulary::NOT_A_VOCABULARY)) {
            continue;
        }

        $unexplained[] = $field;
    }

    expect($unexplained)->toBe([], implode("\n", array_merge(
        ['These fields ship a machine code the app cannot render in Arabic.'],
        ['Add them to ApiVocabulary::VOCABULARIES, or to NOT_A_VOCABULARY with a reason:'],
        $unexplained,
    )));
});

it('resolves every closed set from ValueSets, and every code to a real word in BOTH languages', function () {
    $problems = [];

    foreach (ApiVocabulary::VOCABULARIES as $path => $spec) {
        if (! isset($spec['set'])) {
            continue;
        }

        [$table, $column] = explode('.', $spec['set']);
        $codes = ValueSets::allowed($table, $column);

        // The values must come from the registry the COLUMN is enforced against. A set this gate
        // cannot resolve is one the API would publish as an empty vocabulary — a lookup table with
        // no rows, which reads to the client as "no such status" rather than as a broken registry.
        if ($codes === null || $codes === []) {
            $problems[] = "{$path}: ValueSets has nothing for {$spec['set']}";

            continue;
        }

        foreach ($codes as $code) {
            foreach (['en', 'ar'] as $locale) {
                // **`fallback: false` is the whole assertion.** `Lang::has()` falls back to English
                // by default, so the obvious form of this check only ever catches a key missing
                // from BOTH catalogues — which is the one case that never happens in practice. This
                // project has been bitten by it enough times to make it a rule.
                if (! Lang::has("{$spec['group']}.{$code}", $locale, fallback: false)) {
                    $problems[] = "{$path}: {$spec['group']}.{$code} has no {$locale} translation";
                }
            }
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

it('writes real Arabic, not an English string sitting in the Arabic key', function () {
    $suspects = [];

    foreach (ApiVocabulary::VOCABULARIES as $path => $spec) {
        if (! isset($spec['set'])) {
            continue;
        }

        [$table, $column] = explode('.', $spec['set']);

        foreach (ValueSets::allowed($table, $column) ?? [] as $code) {
            $key = "{$spec['group']}.{$code}";

            if (! Lang::has($key, 'ar', fallback: false)) {
                continue; // the case above owns that failure
            }

            $ar = (string) trans($key, [], 'ar');
            $en = (string) trans($key, [], 'en');

            // `Lang::has()` proves a key EXISTS and can never prove somebody put Arabic in it —
            // the realistic failure when a hundred labels are added in one pass and reviewed in
            // English. Two tells: it is byte-identical to the English, or it carries no Arabic
            // script at all. Acronyms are the honest exception and are spelt the same either way,
            // so anything with no letters is left alone.
            if (! preg_match('/\p{Arabic}/u', $ar) && preg_match('/\p{L}/u', $ar) && $ar === $en) {
                $suspects[] = "{$key} → \"{$ar}\" (identical to the English, no Arabic script)";
            }
        }
    }

    expect($suspects)->toBe([], implode("\n", $suspects));
});

it('serves the whole vocabulary in both languages over the real route', function () {
    $tenant = makeTenant();

    $data = test()->getJson('/api/v1/me/vocabulary', apiHeaders($tenant))->assertOk()->json('data');

    expect($data['vocabularies'])->toHaveCount(count(ApiVocabulary::VOCABULARIES))
        ->and($data['version'])->toBeString()->not->toBeEmpty();

    // Every entry, in both languages, non-empty — over HTTP rather than by calling the resolver,
    // because a vocabulary that renders correctly in a unit test and empty through the middleware
    // stack is the failure that reaches the app.
    foreach ($data['vocabularies'] as $path => $codes) {
        expect($codes)->not->toBe([], "{$path} came back empty");

        foreach ($codes as $code => $labels) {
            expect($labels['en'] ?? '')->not->toBe('', "{$path}.{$code} has no English")
                ->and($labels['ar'] ?? '')->not->toBe('', "{$path}.{$code} has no Arabic")
                // A leaked translation key on a retailer's screen is the failure `Translate` was
                // written for; it must never be the answer.
                ->and($labels['ar'])->not->toStartWith('admin.');
        }
    }
});

it('names the operator-editable catalogues, because a shipped table cannot cover them', function () {
    $open = test()->getJson('/api/v1/me/vocabulary', apiHeaders(makeTenant()))->json('data.openCatalogues');

    // These are the ones where a hardcoded client list is not merely stale but structurally wrong:
    // the accountant adds a charge code and the mall adds a payment rail with no deploy either side.
    expect($open)->toContain('invoiceItem.type', 'payment.method', 'publicStore.retailCategory')
        ->and($open)->toBe(ApiVocabulary::openCatalogues());
});

it('rejects a stale NOT_A_VOCABULARY entry', function () {
    $emitted = apiClassificationFields();

    // NOT `toContain($field, $message)` — Pest's `toContain` is VARIADIC, so a message passed
    // there becomes a second value the array must also hold, and the failure reads as though the
    // sentence itself were missing from the data. Collect and compare instead.
    $stale = array_values(array_diff(array_keys(ApiVocabulary::NOT_A_VOCABULARY), $emitted));

    expect($stale)->toBe([], 'exempted, but the API no longer emits them: '.implode(', ', $stale));
});

it('requires a reviewable reason on every exemption', function () {
    foreach (ApiVocabulary::NOT_A_VOCABULARY as $field => $reason) {
        expect(strlen($reason))->toBeGreaterThan(40, "{$field} needs a reason somebody can review");
    }
});

it('is not sweeping an empty set', function () {
    // Every gate in this project that went blind reported a clean result over a population it had
    // silently stopped collecting. Count before believing.
    expect(count(apiClassificationFields()))->toBeGreaterThanOrEqual(25)
        ->and(count(ApiVocabulary::VOCABULARIES))->toBeGreaterThanOrEqual(25);
});
