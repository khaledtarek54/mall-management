<?php

use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Pages\IncomeStatement;
use App\Models\AssistantQuestion;
use App\Services\Assistant\AnswerQuestionService;
use App\Settings\ModulesSettings;
use App\Support\Assistant\AssistantCorpus;
use App\Support\ReportCatalogue;
use App\Support\ScreenGuides;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Lang;

/**
 * "Ask Atriom" — A0 of the in-app assistant (docs/integrations/AI-ASSISTANT.md).
 *
 * There is no language model behind any of this. The properties worth pinning are therefore not
 * about answer QUALITY — they are about the three things a search box over privileged material can
 * get silently wrong: it can name a screen the reader may not open, it can work in one language and
 * not the other, and it can quietly stop recording the questions it failed to answer, which is the
 * only reason the miss list exists.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    AssistantCorpus::flush();
});

afterEach(function () {
    AssistantCorpus::flush();
});

function askAtriom(string $question): array
{
    return app(AnswerQuestionService::class)->answer($question);
}

/** The keys a set of results points at, in order. */
function askAtriomKeys(string $question): array
{
    return array_column(askAtriom($question)['results'], 'key');
}

it('answers an everyday English question with the screen that handles it', function () {
    $this->actingAs(makeUser('super_admin'));

    // Deliberately NOT the screen's name — "who owes us money" is how an operator asks, and the
    // whole point is that the wording does not have to match a screen title.
    expect(askAtriomKeys('who owes us money overdue arrears'))->toContain('ar_aging');
});

it('answers the same question asked in Arabic', function () {
    $this->actingAs(makeUser('super_admin'));
    app()->setLocale('ar');

    // «المتأخرات» — arrears. The Arabic corpus must be built from the Arabic guides, which is only
    // true because AssistantCorpus switches the application locale before resolving them.
    expect(askAtriomKeys('المتأخرات على المستأجرين'))->not->toBeEmpty();
});

it('finds an English term typed into an Arabic panel, and answers in Arabic', function () {
    $this->actingAs(makeUser('super_admin'));
    app()->setLocale('ar');

    // The case this feature exists for: an operator works in Arabic and types the word on the
    // contract. Matching only the panel's locale would answer nothing, which reads as "the system
    // does not have this feature".
    $answer = askAtriom('credit note');

    expect($answer['matched'])->toBeTrue()
        ->and(array_column($answer['results'], 'key'))->toContain('credit_notes');

    // And the ANSWER is still Arabic. The match returns a KEY; the guide is resolved from that key
    // in the reader's own locale, so a cross-locale match can never produce a half-translated page.
    expect(ScreenGuides::purpose('credit_notes'))->toMatch('/\p{Arabic}/u');
});

it('never offers a screen the reader may not open — and still offers one they may', function () {
    // The refusal.
    $this->actingAs(makeUser('technician'));
    expect(askAtriomKeys('payroll salaries staff pay run'))->not->toContain('payrolls');

    // The control, in the SAME shape. Without it, a service that returned nothing at all would
    // satisfy the refusal and read as a pass.
    $this->actingAs(makeUser('super_admin'));
    expect(askAtriomKeys('payroll salaries staff pay run'))->toContain('payrolls');
});

it('records every question, and marks the ones nothing answered', function () {
    $this->actingAs(makeUser('super_admin'));

    askAtriom('who owes us money overdue arrears');
    askAtriom('zzzzqqq nonsense that matches nothing at all');

    expect(AssistantQuestion::count())->toBe(2)
        ->and(AssistantQuestion::where('matched', true)->count())->toBe(1)
        ->and(AssistantQuestion::unanswered()->count())->toBe(1);

    // The folded form is what groups two spellings of one question as one question.
    $miss = AssistantQuestion::unanswered()->first();
    expect($miss->question_folded)->not->toBe('')
        ->and($miss->top_key)->toBeNull()
        ->and($miss->locale)->toBe(app()->getLocale());
});

it('does not answer a question made only of stop words', function () {
    $this->actingAs(makeUser('super_admin'));

    // "how do I" against a corpus containing every guide in the system would otherwise return the
    // longest guide, confidently. The floor plus the stop list is what stops that.
    expect(askAtriom('how do I')['matched'])->toBeFalse();
});

it('does not answer on a generic word that happens to sit in a screen title', function () {
    $this->actingAs(makeUser('super_admin'));

    // The defect this pins, found on the first run: the report hub's own label is "All Reports", so
    // the word `all` carried TITLE weight — the STRONGEST weight in the corpus — and any sentence
    // containing it got a confident top hit on the report hub. The floor cannot catch that, because
    // the floor exists to suppress weak body matches. The stop list can, and it is applied to the
    // corpus as well as the query, so the term stops existing on both sides.
    expect(askAtriom('show me all of them')['matched'])->toBeFalse();

    // The control: the word that SHOULD find that screen still does. A stop list that had swallowed
    // "reports" too would satisfy the assertion above and break the feature.
    expect(askAtriomKeys('reports'))->not->toBeEmpty();
});

it('never answers a question by offering itself', function () {
    $this->actingAs(makeUser('super_admin'));

    // Its own guide is written in the vocabulary of asking questions, which is the vocabulary every
    // question is made of — so left in the corpus it would rank on everything, and "open Ask
    // Atriom" is a dead end for somebody already looking at it.
    expect(askAtriomKeys('how do I ask a question about a screen'))->not->toContain('assistant');
});

it('carries its own screen guide and its own chrome in BOTH languages', function () {
    // `Lang::has()` FALLS BACK to English by default, so the obvious parity check passes for every
    // key that exists in English only — the exact failure this is meant to catch.
    $keys = [
        'admin.assistant.question_label',
        'admin.assistant.ask',
        'admin.assistant.no_answer_heading',
        'admin.assistant.no_answer_body',
        'admin.settings.modules.assistant',
    ];

    foreach ($keys as $key) {
        foreach (['en', 'ar'] as $locale) {
            expect(Lang::has($key, $locale, fallback: false))
                ->toBeTrue("{$key} is missing in {$locale}");
        }
    }

    // Present is not the same as translated: an English sentence sitting in the Arabic file
    // satisfies `Lang::has()` and is the realistic failure when keys are added in one pass.
    foreach ($keys as $key) {
        expect(__($key, [], 'ar'))->toMatch('/\p{Arabic}/u', "{$key} carries no Arabic script");
    }
});

it('builds a different corpus per language', function () {
    // The bug this pins: `entries($locale)` took a locale and did not switch the application
    // locale, so both corpora were built from English strings — the cross-locale fallback compared
    // English to English and could never find anything new.
    $en = AssistantCorpus::entries('en');
    $ar = AssistantCorpus::entries('ar');

    $titlesEn = array_column($en, 'title');
    $titlesAr = array_column($ar, 'title');

    expect($en)->not->toBeEmpty()
        ->and(count($en))->toBe(count($ar))
        ->and($titlesAr)->not->toBe($titlesEn);
});

it('stays silent rather than answering confidently from one weak word', function () {
    $this->actingAs(makeUser('super_admin'));

    // Measured against the demo books, not reasoned: at the old floor this answered *AR aging* —
    // a single common word landing in a purpose sentence, presented with the same confidence as a
    // title match scoring 16. The reader follows it, finds the wrong screen, and concludes the box
    // does not work. No answer is honest and lands the question on the unanswered list.
    expect(askAtriom('where do I set the late fee cap')['matched'])->toBeFalse();

    // The control: a real title/keyword hit must still clear the raised floor. A floor set too high
    // satisfies the assertion above by answering nothing at all, ever.
    expect(askAtriomKeys('vat return'))->not->toBeEmpty();
});

it('finds a report by its ARABIC name, not only its English one', function () {
    $this->actingAs(makeUser('super_admin'));
    app()->setLocale('ar');

    // 22 of the 26 reports carried English-only keywords, so an Arabic-speaking operator could not
    // reach a report by typing its Arabic name — in this box or in the report hub's own filter,
    // which reads the same list. Pinned per report rather than as a count, because a count is
    // satisfied by one Arabic word anywhere.
    foreach (ReportCatalogue::REPORTS as $page => $meta) {
        expect(implode(' ', $meta['keywords'] ?? []))
            ->toMatch('/\p{Arabic}/u', "{$meta['key']} has no Arabic keyword");
    }

    // And it actually resolves end to end — «المتأخرات» is arrears.
    expect(askAtriomKeys('المتأخرات'))->toContain('ar_aging');
});

it('disappears from every page when the module is switched off', function () {
    $asset = makeAsset();
    $this->actingAs(makeUser('super_admin', [$asset->id]));

    // Asserted through a REAL page, because the switch is consulted in the panel's render hook. A
    // Livewire component has no `shouldRender()` convention — Livewire never calls one — so the
    // gate the chat used to carry was dead code and the toggle hid nothing.
    $url = Dashboard::getUrl(tenant: $asset);

    $this->get($url)->assertOk()->assertSee('assistant-chat');

    app(ModulesSettings::class)->fill(['assistant' => false])->save();

    $this->get($url)->assertOk()->assertDontSee('assistant-chat');
});

// ── A1: records, and the links that carry a parameter ──────────────────────────────────────────

it('answers a question that NAMES a record with that record, first', function () {
    $asset = makeAsset();
    $tenant = makeTenant(['name' => 'Zarqoun Trading']);
    makeLease(makeUnit($asset), $tenant);
    $this->actingAs(makeUser('super_admin'));

    asTenant($asset, function () {
        $results = app(AnswerQuestionService::class)->answer('how much does Zarqoun owe')['results'];

        // The record leads: a question naming one is asking about it, and the screen that explains
        // the concept is the follow-up.
        expect($results[0]['kind'])->toBe('record')
            ->and($results[0]['title'])->toContain('Zarqoun')
            ->and($results[0]['url'])->not->toBeNull();

        // And the screen half still answers, so records did not displace the explanation.
        expect(array_column($results, 'kind'))->toContain('screen');
    });
});

it('never searches records for a bare year', function () {
    $asset = makeAsset();
    // A unit's search blob carries dates, so "2026" matched three of them — three UNITS offered as
    // the answer to "income statement 2026". A four-digit year is the one token that is certainly
    // not a record name, and it is already read as a report parameter.
    makeUnit($asset);
    $this->actingAs(makeUser('super_admin'));

    asTenant($asset, function () {
        $results = app(AnswerQuestionService::class)->answer('income statement 2026')['results'];

        expect(array_column($results, 'kind'))->not->toContain('record');

        // The control: the year is not merely ignored — it reaches the report link.
        $incomeStatement = collect($results)->firstWhere('key', 'income_statement');
        expect($incomeStatement)->not->toBeNull()
            ->and($incomeStatement['url'])->toContain('year=2026');
    });
});

it('shows one card per destination, not one per registry it appears in', function () {
    $asset = makeAsset();
    $this->actingAs(makeUser('super_admin'));

    asTenant($asset, function () {
        $results = app(AnswerQuestionService::class)->answer('income statement')['results'];

        $screens = array_column($results, 'screen');
        expect($screens)->toBe(array_unique($screens));

        // The merge keeps the SCREEN's identity, because its key is what resolves the guide.
        $incomeStatement = collect($results)->firstWhere('screen', IncomeStatement::class);
        expect($incomeStatement['kind'])->toBe('screen');
    });
});

it('never returns a record from a property the reader does not hold', function () {
    $mine = makeAsset(['name' => 'Held Mall']);
    $theirs = makeAsset(['name' => 'Other Mall']);

    $ours = makeTenant(['name' => 'Qamaria Coffee']);
    makeLease(makeUnit($mine), $ours);

    $theirTenant = makeTenant(['name' => 'Qamaria Roasters']);
    makeLease(makeUnit($theirs), $theirTenant);

    $this->actingAs(makeUser('manager', [$mine->id]));

    asTenant($mine, function () {
        $titles = array_column(
            app(AnswerQuestionService::class)->answer('Qamaria')['results'],
            'title'
        );

        // The refusal.
        expect(implode(' ', $titles))->not->toContain('Roasters');

        // The control, in the same shape: a scope that returned nothing at all would satisfy the
        // refusal and read as a pass.
        expect(implode(' ', $titles))->toContain('Qamaria Coffee');
    });
});
