<?php

use App\Models\AssistantDocChunk;
use App\Services\Assistant\AnswerQuestionService;
use App\Support\Assistant\AssistantCorpus;
use App\Support\Assistant\DocCorpus;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * A2 — the documentation tier (docs/modules/39-assistant.md).
 *
 * The properties that matter here are about DISCIPLINE rather than recall: which documents may be
 * quoted to an operator at all, that prose never outranks a screen, and that a source published
 * nowhere does not invent a link.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    AssistantCorpus::flush();
});

afterEach(fn () => AssistantCorpus::flush());

it('classifies every documentation directory as indexed or excluded, with a reason', function () {
    $directories = collect(glob(base_path('docs').'/*', GLOB_ONLYDIR))
        ->map(fn (string $path): string => basename($path))
        ->all();

    expect($directories)->not->toBeEmpty();

    $classified = array_merge(
        array_keys(DocCorpus::SOURCES),
        array_keys(DocCorpus::TECHNICAL_SOURCES),
        array_keys(DocCorpus::NOT_INDEXED),
    );

    // A NEW documentation area must force the decision. Defaulting to invisible is the failure
    // that would go unnoticed; defaulting to indexed would quote developer notes at an operator.
    expect(array_values(array_diff($directories, $classified)))
        ->toBe([], 'Add it to DocCorpus::SOURCES, TECHNICAL_SOURCES or NOT_INDEXED with a reason.');

    // And a stale entry is a failure too — a directory that has been deleted or renamed leaves a
    // reason describing something that no longer exists.
    expect(array_values(array_diff($classified, $directories)))->toBe([]);

    foreach (DocCorpus::NOT_INDEXED as $directory => $reason) {
        expect(mb_strlen($reason))->toBeGreaterThan(40, "{$directory} needs a real reason");
    }
});

it('quotes the developer documentation only when it is switched on', function () {
    // The whole point of the allowlist. `docs/modules` explains the CODE — quoting it to a retail
    // manager answers a business question with an implementation, which reads as though the system
    // has no business answer at all. It is a SWITCH rather than a permanent exclusion, because the
    // same corpus is exactly right for a technical audience asking a technical question.
    expect(DocCorpus::SOURCES)->not->toHaveKey('modules')
        ->and(DocCorpus::TECHNICAL_SOURCES)->toHaveKey('modules');

    config()->set('assistant.index_technical_docs', false);

    $off = array_keys(DocCorpus::files(base_path('docs')));
    expect(collect($off)->filter(fn (string $p): bool => str_starts_with($p, 'modules/'))->all())->toBe([]);

    // The control, in the same shape: switched on it really is indexed. Without it the refusal
    // above would pass just as happily if the switch did nothing at all.
    config()->set('assistant.index_technical_docs', true);

    $on = array_keys(DocCorpus::files(base_path('docs')));
    expect(collect($on)->filter(fn (string $p): bool => str_starts_with($p, 'modules/'))->all())->not->toBeEmpty();
});

it('links a handbook section and leaves an unpublished one without a link', function () {
    // The handbook is built one HTML page per markdown file, so the mapping is mechanical.
    expect(DocCorpus::urlFor('visual/leasing/lease-lifecycle.md', '/handbook'))
        ->toBe('/handbook/leasing/lease-lifecycle.html')
        ->and(DocCorpus::urlFor('visual/index.md', '/handbook'))->toBe('/handbook/')
        ->and(DocCorpus::urlFor('visual/leasing/index.md', '/handbook'))->toBe('/handbook/leasing/')
        ->and(DocCorpus::urlFor('visual/ar/map.md', '/handbook'))->toBe('/handbook/ar/map.html');

    // A training walkthrough is a repository file served nowhere. Inventing a link would 404;
    // its excerpt IS the answer.
    expect(DocCorpus::urlFor('training/RECEIVABLES-WALKTHROUGH.md', null))->toBeNull();
});

it('reads the Arabic handbook as Arabic', function () {
    expect(DocCorpus::localeOf('visual/ar/money/index.md'))->toBe('ar')
        ->and(DocCorpus::localeOf('visual/money/index.md'))->toBe('en')
        ->and(DocCorpus::localeOf('training/OPERATOR-PLAYBOOK.md'))->toBe('en');
});

it('splits on headings without being fooled by a fenced code block', function () {
    $sections = DocCorpus::split(<<<'MD'
        # The document
        Intro prose that is long enough to be worth keeping in the index at all.

        ## First section
        Body of the first.

        ```bash
        ## this is a shell comment, not a heading
        ```

        ## Second section
        Body of the second.
        MD);

    expect(array_column($sections, 'heading'))
        ->toBe(['The document', 'First section', 'Second section']);
});

it('widens a word to its stem so a plural finds the singular', function () {
    // "What happens when a cheque BOUNCES" found nothing while the walkthrough answering it says
    // "bounced". Safe here for a structural reason: the blob is matched with LIKE %stem%, so a
    // shorter stem only ever matches MORE — precision is taken back by the all-words AND.
    expect(AssistantDocChunk::stem('bounces'))->toBe('bounce')
        ->and(AssistantDocChunk::stem('invoices'))->toBe('invoice')
        ->and(AssistantDocChunk::stem('billing'))->toBe('bill')
        ->and(AssistantDocChunk::stem('الفواتير'))->toBe('فواتير')
        // Short words are left alone: over-stripping them would match almost anything.
        ->and(AssistantDocChunk::stem('cam'))->toBe('cam')
        ->and(AssistantDocChunk::stem('fees'))->toBe('fees');
});

it('answers from the documentation only when the guides could not', function () {
    $asset = makeAsset();
    $this->actingAs(makeUser('super_admin'));

    AssistantDocChunk::create([
        'path' => 'training/RECEIVABLES-WALKTHROUGH.md',
        'locale' => 'en',
        'heading' => 'When a cheque bounces',
        'url' => null,
        'excerpt' => 'The bank returns the cheque unpaid and the invoice goes back to outstanding.',
        'search_blob' => 'when a cheque bounced the bank returns it unpaid zzqrare',
    ]);

    asTenant($asset, function () {
        $service = app(AnswerQuestionService::class);

        // The guides have nothing on this, so the documentation answers.
        // ONLY the rare token now: "cheque" matches the post-dated-cheque screen since the task
        // tier shipped, so the guides really did answer and the fallback correctly did not run —
        // the fixture, not the rule, was what had gone stale.
        $fallback = $service->answer('zzqrare unpaid')['results'];

        // A task legitimately matches "cheque" now, so this asserts the documentation tier RAN —
        // a task is a link to a form and must never silence the passage that explains something.
        expect(array_column($fallback, 'kind'))->toContain('doc');

        // The control, and the rule: a question the GUIDES answer must not be answered with prose.
        // A screen guide links to the screen that does the job; a paragraph only describes it.
        //
        // The chunk below is seeded to match that very question, so it WOULD rank if the tier ran.
        // Without it this assertion passed for the wrong reason — the five-result slice was hiding
        // the documentation rather than the guard, and deleting the guard left the test green.
        AssistantDocChunk::create([
            'path' => 'training/RECEIVABLES-WALKTHROUGH.md',
            'locale' => 'en',
            'heading' => 'The VAT return, end to end',
            'url' => null,
            'excerpt' => 'How the VAT return is prepared.',
            'search_blob' => 'the vat return end to end how it is prepared and filed',
        ]);

        $guided = $service->answer('vat return')['results'];

        expect(count($guided))->toBeLessThan(AnswerQuestionService::MAX_RESULTS);
        expect(array_column($guided, 'kind'))->not->toContain('doc')
            ->and(array_column($guided, 'kind'))->toContain('screen');
    });
});

it('indexes the real documentation, in both languages, and is idempotent', function () {
    $this->artisan('atriom:rebuild-assistant-index')->assertSuccessful();

    $first = AssistantDocChunk::count();

    expect($first)->toBeGreaterThan(100)
        ->and(AssistantDocChunk::where('locale', 'ar')->count())->toBeGreaterThan(20)
        ->and(AssistantDocChunk::where('locale', 'en')->count())->toBeGreaterThan(50);

    // A rebuild replaces wholesale rather than appending — otherwise every deploy doubles it.
    $this->artisan('atriom:rebuild-assistant-index')->assertSuccessful();
    expect(AssistantDocChunk::count())->toBe($first);

    // And nothing from the developer documentation got in.
    expect(AssistantDocChunk::where('path', 'like', 'modules/%')->count())->toBe(0);
});

it('refuses to empty the index when it can read nothing', function () {
    AssistantDocChunk::create([
        'path' => 'training/X.md', 'locale' => 'en', 'heading' => 'Kept',
        'url' => null, 'excerpt' => 'x', 'search_blob' => 'x',
    ]);

    // A partial or empty index is worse than a stale one: the assistant would answer confidently
    // from whatever survived, with nothing on screen to say a whole tier had gone.
    $this->artisan('atriom:rebuild-assistant-index', ['--path' => base_path('storage/framework')])
        ->assertFailed();

    expect(AssistantDocChunk::count())->toBe(1);
});
