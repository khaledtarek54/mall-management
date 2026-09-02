<?php

use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Services\Assistant\AnswerQuestionService;
use App\Support\Assistant\AssistantCorpus;
use App\Support\Assistant\TaskCorpus;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * The TASK tier — "create one, and here is what the form asks for".
 *
 * The screen guides say what a screen is FOR and the handbook says how the system WORKS. Neither
 * names a field, and neither links to the form, so "how do I raise an invoice and what does it want
 * from me" was answered with a paragraph and a link to the list — true, and two clicks short.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    AssistantCorpus::flush();
});

afterEach(fn () => AssistantCorpus::flush());

it('sends a "how do I create one" question to the FORM, not the list', function () {
    $asset = makeAsset();
    $this->actingAs(makeUser('super_admin'));

    asTenant($asset, function () {
        $results = app(AnswerQuestionService::class)->answer('how do I create an invoice')['results'];

        $top = $results[0];

        expect($top['kind'])->toBe('task')
            // The whole point: the link is the create form, not the register.
            ->and($top['url'])->toContain('/invoices/create');
    });
});

it('reads the fields off the real form, so they cannot drift', function () {
    $fields = TaskCorpus::fieldsFor(InvoiceResource::class);

    expect($fields)->not->toBeEmpty();

    $names = array_column($fields, 'name');
    expect($names)->toContain('lease_id')->toContain('issue_date')->toContain('due_date');

    // Labelled from `admin.fields.*` — the catalogue the FORM labels from — so the assistant says
    // the same word the operator reads on screen, and says it in their language for free.
    $issueDate = collect($fields)->firstWhere('name', 'issue_date');
    expect($issueDate['label'])->toBe(__('admin.fields.issue_date'));
});

it('names the fields in the reader\'s own language, with their own separator', function () {
    app()->setLocale('ar');

    $sentence = TaskCorpus::fieldSentence(InvoiceResource::class);

    // Arabic separates with «،», not a Latin comma — a comma in an Arabic sentence reads the way a
    // full stop mid-word would in English. It was hardcoded to the Arabic one, which put it into
    // every English list too.
    expect($sentence)->toContain('، ')
        ->and($sentence)->toMatch('/\p{Arabic}/u');

    app()->setLocale('en');
    expect(TaskCorpus::fieldSentence(InvoiceResource::class))->toContain(', ')
        ->and(TaskCorpus::fieldSentence(InvoiceResource::class))->not->toContain('، ');
});

it('caps the field list so one long form cannot eat the whole answer', function () {
    $sentence = TaskCorpus::fieldSentence(InvoiceResource::class);

    // A form with forty fields would otherwise spend the model's entire prompt on field names and
    // leave no room for the guide beside it.
    expect(substr_count($sentence, ','))->toBeLessThan(TaskCorpus::MAX_LISTED_FIELDS * 2 + 2);
});

it('never offers a create form the reader may not open', function () {
    $asset = makeAsset();

    // The refusal. `viewer` holds no create rights anywhere.
    $this->actingAs(makeUser('viewer'));
    AssistantCorpus::flush();

    asTenant($asset, function () {
        $kinds = array_column(app(AnswerQuestionService::class)->answer('how do I create an invoice')['results'], 'kind');
        expect($kinds)->not->toContain('task');
    });

    // The control, in the same shape: a scope that offered nothing to anybody would satisfy the
    // refusal and read as a pass.
    auth()->forgetUser();
    $this->actingAs(makeUser('super_admin'));
    AssistantCorpus::flush();

    asTenant($asset, function () {
        $kinds = array_column(app(AnswerQuestionService::class)->answer('how do I create an invoice')['results'], 'kind');
        expect($kinds)->toContain('task');
    });
});
