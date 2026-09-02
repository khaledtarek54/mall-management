<?php

use App\Services\Assistant\AnswerQuestionService;
use App\Support\Assistant\AssistantCorpus;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * THE EVALUATION SET — the thing a chatbot otherwise has no way to fail.
 *
 * Every other test here pins a mechanism: that a scope refuses, that a cache hits, that a gate
 * bites. None of them notices RANKING getting worse, and ranking is most of what this feature is.
 * The scoring was changed four times in a day and the only thing checking whether the answers
 * improved was somebody reading them.
 *
 * **No model is called, so this is free and deterministic.** The wording is the model's job and
 * varies; WHICH SOURCE the question lands on is ours, and it is the half that decides whether the
 * answer can be right at all. A question whose top result is the wrong screen cannot be rescued by
 * good prose.
 *
 * **Adding a question here is the point.** When somebody reports a bad answer, it becomes a row —
 * that is what turns one complaint into a permanent guard.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    AssistantCorpus::flush();
});

afterEach(fn () => AssistantCorpus::flush());

/**
 * [question, the key that must be the TOP result, the reader's locale].
 *
 * Top rather than "somewhere in the five": the first result is the one the reader acts on, and the
 * model is handed the passages in this order.
 *
 * **The locale is part of the case, not scaffolding.** The corpus is built per language and the
 * create-intent verbs are read in the reader's own — so an Arabic question asked in an Arabic panel
 * and the same question typed into an English one legitimately rank differently. Pinning the pair
 * is the only honest way to state what is expected.
 */
dataset('leads_with', [
    // ── Tasks: "how do I make one" ────────────────────────────────────────────────────────────
    'create an invoice' => ['how do I create an invoice', 'App\Filament\Admin\Resources\Invoices\InvoiceResource', 'en'],
    'raise an invoice' => ['how do I raise a new invoice', 'App\Filament\Admin\Resources\Invoices\InvoiceResource', 'en'],
    'add a tenant' => ['how to add a new tenant', 'App\Filament\Admin\Resources\Tenants\TenantResource', 'en'],
    'new lease' => ['create a new lease', 'App\Filament\Admin\Resources\Leases\LeaseResource', 'en'],
    'record a payment' => ['how do I record a payment', 'App\Filament\Admin\Resources\Payments\PaymentResource', 'en'],

    // ── Arabic, including the plural that used to miss ────────────────────────────────────────
    'ar credit note' => ['ازاي اعمل اشعار خصم', 'App\Filament\Admin\Resources\CreditNotes\CreditNoteResource', 'ar'],
    'ar new tenant' => ['اضافة مستاجر جديد', 'App\Filament\Admin\Resources\Tenants\TenantResource', 'ar'],

    // ── Reports and screens: "what do the numbers say" ────────────────────────────────────────
    'who owes money' => ['who owes us money', 'ar_aging', 'en'],
    'ar arrears' => ['المتأخرات على المستأجرين', 'ar_aging', 'ar'],
    'vat return' => ['vat return', 'vat_return', 'en'],
    'rent roll' => ['rent roll', 'rent_roll', 'en'],
    'trial balance' => ['trial balance', 'trial_balance', 'en'],
]);

it('leads with the right source', function (string $question, string $expected, string $locale) {
    $asset = makeAsset();
    $this->actingAs(makeUser('super_admin'));

    app()->setLocale($locale);

    asTenant($asset, function () use ($question, $expected) {
        $results = app(AnswerQuestionService::class)->answer($question)['results'];

        expect($results)->not->toBeEmpty("'{$question}' found nothing at all");

        expect($results[0]['key'])->toBe(
            $expected,
            "'{$question}' led with '{$results[0]['key']}' ({$results[0]['title']}) instead of '{$expected}'"
        );
    });
})->with('leads_with');

/**
 * Questions the assistant should NOT answer confidently, because nothing in the system does.
 */
it('stays quiet on a question this system cannot answer', function (string $question) {
    $asset = makeAsset();
    $this->actingAs(makeUser('super_admin'));

    asTenant($asset, function () use ($question) {
        // Retrieval finding nothing is the honest outcome; the alternative is a confident pointer
        // at whichever screen shares a common word, which is the failure the score floor exists for.
        expect(app(AnswerQuestionService::class)->answer($question)['matched'])
            ->toBeFalse("'{$question}' should not have matched anything");
    });
})->with([
    'weather' => ['what is the weather in Cairo tomorrow'],
    'gibberish' => ['zzqq wibble frotz'],
    'stop words only' => ['how do I'],
]);
