<?php

/*
|--------------------------------------------------------------------------
| A field's unit suffix is written in the reader's language (SW-053)
|--------------------------------------------------------------------------
| The lease-clause form printed the English word `days` after the notice-period box, and Settings →
| SLA printed the English abbreviation `hrs` after all eight deadline boxes. On the Arabic panel
| that is an English word inside an Arabic form, and `ArabicPanelHasNoEnglishChromeConformanceTest`
| cannot see any of it: that gate sweeps `getLabel()` on columns, filters, actions, tabs and empty
| states, and an AFFIX is none of those.
|
| Measured at HEAD 2026-09-04 by building the clause schema and reading `getSuffixLabel()`:
|     threshold_pct => '%'   radius_km => 'km'   notice_days => 'days'
| and by a sweep of every literal affix in `app/`: 184 of them, of which exactly 10 were English
| words — 1 `days` and 8 `hrs`, plus `km`.
|
| ## Half of the row is refused, and this is the reason
|
| `km` STAYS verbatim. It is the SI symbol for kilometre, not an English word, and this app already
| prints the SI symbol `m²` verbatim in eight places (assets ×3, units, CAM pools, the rent roll,
| the expiration schedule, the unit remeasure action). Translating one SI symbol and not the other
| would make the panel inconsistent with itself, and the sweep below records that decision per
| symbol so the next person does not have to re-derive it. The line drawn is: an ISO code, an SI
| symbol or a punctuation mark is verbatim; a natural-language word is not.
|
| The gate is what closes the class — a fix to two call sites closes two call sites.
*/

use App\Filament\Admin\RelationManagers\LeaseClausesRelationManager;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Lang;
use Symfony\Component\Finder\Finder;

afterEach(fn () => app()->setLocale('en'));

it('writes the lease clause notice period in the reader’s own language', function () {
    app()->setLocale('ar');

    $suffixes = [];

    // Built with no Livewire container on purpose: `withHidden: true` skips the `visible()`
    // closures, which are the only thing on this schema that needs one. Driving the real relation
    // manager would need an owner record and a page class and would prove nothing more about a
    // string constant.
    foreach ((new LeaseClausesRelationManager)->form(Schema::make())->getComponents(withActions: false, withHidden: true) as $component) {
        if ($component instanceof TextInput) {
            $suffixes[$component->getName()] = $component->getSuffixLabel();
        }
    }

    expect($suffixes['notice_days'])->toBe(__('admin.fields.days'))
        // Named literally as well as through the key: `->toBe(__(...))` alone passes if the key
        // itself resolves to the English word, which is exactly what it does in `en`.
        ->and($suffixes['notice_days'])->not->toBe('days')
        // The CONTROL for the refused half — `km` and `%` are symbols and must NOT have moved.
        ->and($suffixes['radius_km'])->toBe('km')
        ->and($suffixes['threshold_pct'])->toBe('%');
});

it('gives the unit words it uses a real Arabic translation', function () {
    // `Lang::has()` falls back to English by default, so a parity check written the obvious way
    // passes for every key present in `en`. `fallback: false` is what asks the Arabic question.
    foreach (['admin.fields.days', 'admin.fields.hrs'] as $key) {
        expect(Lang::has($key, 'en', fallback: false))->toBeTrue()
            ->and(Lang::has($key, 'ar', fallback: false))->toBeTrue()
            // And a key present in `ar` holding an English string is the realistic failure when
            // labels are added in one pass and reviewed in English.
            ->and((string) __($key, [], 'ar'))->toMatch('/\p{Arabic}/u');
    }
});

it('never puts an English word in a field affix, anywhere in the app', function () {
    // A prefix or suffix may be a literal string only when it is not language: an ISO code, an SI
    // unit symbol, or punctuation. Everything else goes through `__()`.
    //
    // This lives in the test rather than in `app/Support` deliberately — it is a classification
    // used by nothing at runtime, and a registry class read only by its own gate is dead
    // production code.
    $verbatim = [
        'EGP' => 'ISO 4217 currency code — an accountant reconciles it against a bank statement, so it stays raw in every language (the rule ActivityVocabulary::VERBATIM_VALUES already states for the `currency` column)',
        '%' => 'per-cent sign — punctuation, not a word',
        ' %' => 'per-cent sign, spaced for a table cell',
        'm²' => 'SI symbol for square metre',
        ' m²' => 'SI symbol for square metre, spaced for a table cell',
        'km' => 'SI symbol for kilometre — the same decision as m², taken once here so it is not re-argued per screen',
        '@' => 'the at sign in front of a social handle',
    ];

    $offenders = [];
    $used = [];
    $seen = 0;

    // A literal affix only: `('` or `("` straight after the call and `)` straight after the closing
    // quote. A composed one (`->suffix('/m²/'.__(...))`) is excluded by refusing a quote inside the
    // value — matching it loosely runs to the next `')` and reports the translated tail as a
    // hardcoded string, which is a gate firing on correct code.
    $pattern = '/->(?:suffix|prefix)\(\s*(?:\'([^\'\\\\]*)\'|"([^"\\\\$]*)")\s*\)/';

    foreach (Finder::create()->files()->in(base_path('app'))->name('*.php') as $file) {
        preg_match_all($pattern, $file->getContents(), $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $seen++;
            // A single-quoted hit omits the trailing group; a double-quoted one sets group 1 to ''.
            $value = $match[2] ?? $match[1];
            $used[$value] = true;

            if (! array_key_exists($value, $verbatim)) {
                $offenders[] = str_replace(base_path().'/', '', $file->getPathname())." → '{$value}'";
            }
        }
    }

    // The premise. 184 literal affixes at HEAD on 2026-09-04, 175 after this change; a sweep that
    // silently stopped matching would otherwise report a clean panel it never read.
    expect($seen)->toBeGreaterThan(150)
        ->and($offenders)->toBe([]);

    // And the allowlist must not go stale in the other direction: a symbol nothing prints any more
    // is a decision nobody has to keep making, and a dead exemption is how a real one gets added
    // beside it without argument.
    expect(array_diff(array_keys($verbatim), array_keys($used)))->toBe([]);
});
