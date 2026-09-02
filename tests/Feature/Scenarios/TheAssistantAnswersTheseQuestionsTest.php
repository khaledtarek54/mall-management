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

    // ── The operator's own words, measured against docs/training/OPERATOR-PLAYBOOK.md ─────────
    //
    // Thirty-three real operating tasks were driven through retrieval and SEVEN went confidently
    // to the wrong place — because operators say "receipt", "complaint", "reading", "bad debt",
    // and the screens are called Payments, Requests, Utility Meters, Invoices. Reports had had
    // curated keywords since day one, which is exactly why report questions were already the most
    // accurate tier. These are the seven, pinned so the vocabulary cannot quietly rot back.
    'a receipt is a payment' => ['record a receipt from a tenant', 'payments', 'en'],
    'a complaint is a request' => ['log a tenant complaint', 'tenant_requests', 'en'],
    'a reading is a meter' => ['record a meter reading', 'utility_meters', 'en'],
    // Submitting one IS creating one, so the FORM is the right answer here — it used to lead with
    // New Owner Request, which is a different module and a different reader entirely.
    'a purchase request is procurement' => ['submit a purchase request', 'App\Filament\Admin\Resources\PurchaseRequests\PurchaseRequestResource', 'en'],
    'closing a period' => ['close the accounting period', 'accounting_periods', 'en'],
    'a rent free period is a lease term' => ['record a rent free period', 'leases', 'en'],

    // A hyphen used to weld the screen's own name into one token (`monthend`), so the words in the
    // title could not find the title.
    'month end close' => ['month end close', 'month_end_close', 'en'],

    // ── Arabic, where a one-word overlap decides it ───────────────────────────────────────────
    //
    // A work order is «أمر شغل» and a permit to work is «تصريح عمل»: «اصدار امر عمل» matched one
    // word of each, tied, and the alphabetical tie-break handed a breakdown to the permit form.
    'ar work order not permit' => ['اصدار امر عمل', 'work_orders', 'ar'],
    'ar a receipt is a payment' => ['تسجيل ايصال من مستاجر', 'payments', 'ar'],
    'ar a complaint is a request' => ['تسجيل شكوى مستاجر', 'tenant_requests', 'ar'],
    'ar closing a period' => ['اقفال الفترة المحاسبية', 'accounting_periods', 'ar'],

    // «مستحقات» means what is OWED, in either direction — so it belongs to receivables, and a
    // payables synonym claiming it sent "who owes us money" to the supplier bills.
    'ar dues are receivable' => ['من عليه مستحقات', 'ar_aging', 'ar'],
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
