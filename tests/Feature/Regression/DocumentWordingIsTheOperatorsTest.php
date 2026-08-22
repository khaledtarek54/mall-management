<?php

use App\Models\DocumentTemplate;
use App\Services\InvoicePdfService;
use App\Support\DocumentText;
use App\Support\ValueSets;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\View;

/**
 * EG-15 slice 1 — the standing wording on a tenant-facing document becomes the operator's.
 *
 * Every word on an invoice was a translation key, so changing the footer was a deploy. Two things
 * made that worse than the usual complaint about lang files:
 *
 *   1. **The footer names payment rails.** `admin.pdf.footer` reads *"Payment due within :days days
 *      of issue · Bank transfer / Card / InstaPay"* — three rails hardcoded on the one document
 *      every tenant reads every month, while EG-11 made rails an operator catalogue they add to and
 *      retire. The sentence can be wrong the moment they use the feature.
 *   2. **No invoice showed bank details at all**, so a tenant holding one could not know where to
 *      pay. There was nowhere to put it.
 *
 * The floor is what makes it safe: an install with no rows renders exactly what it rendered before.
 */
it('renders the built-in wording until somebody writes their own', function () {
    // The control, and the safety case for the whole change: nothing moves on deploy.
    expect(DocumentText::for('invoice.footer', null, ['days' => 7]))
        ->toBe(__('admin.pdf.footer', ['days' => 7]));

    // A block that did not exist before renders NOTHING rather than an empty heading.
    expect(DocumentText::for('invoice.payment_instructions'))->toBeNull()
        ->and(DocumentText::has('invoice.payment_instructions'))->toBeFalse();
});

it('prefers the operator house wording over the built-in text', function () {
    DocumentTemplate::create([
        'key' => 'invoice.footer',
        'asset_id' => null,
        'body_en' => 'Due in {days} days. Pay by InstaPay only.',
    ]);

    expect(DocumentText::for('invoice.footer', null, ['days' => 14]))
        ->toBe('Due in 14 days. Pay by InstaPay only.');
});

it('lets one mall override the house wording without touching the others', function () {
    $mallA = makeAsset(['code' => 'MALL-A']);
    $mallB = makeAsset(['code' => 'MALL-B']);

    DocumentTemplate::create(['key' => 'invoice.payment_instructions', 'asset_id' => null, 'body_en' => 'Head office account 111']);
    DocumentTemplate::create(['key' => 'invoice.payment_instructions', 'asset_id' => $mallA->id, 'body_en' => 'Mall A account 222']);

    expect(DocumentText::for('invoice.payment_instructions', $mallA->id))->toBe('Mall A account 222')
        // B has no row of its own and must still get the house default, not nothing. This is the
        // whole reason the model is `portfolioRowsWhenNull` rather than strictly property-owned.
        ->and(DocumentText::for('invoice.payment_instructions', $mallB->id))->toBe('Head office account 111');
});

it('falls back to the house wording when a property row is switched off', function () {
    $mall = makeAsset(['code' => 'MALL-C']);

    DocumentTemplate::create(['key' => 'invoice.terms', 'asset_id' => null, 'body_en' => 'House terms']);
    DocumentTemplate::create(['key' => 'invoice.terms', 'asset_id' => $mall->id, 'body_en' => 'Mall terms', 'is_active' => false]);

    expect(DocumentText::for('invoice.terms', $mall->id))->toBe('House terms');
});

it('reads the tenant the language they are reading in, and never a blank', function () {
    DocumentTemplate::create([
        'key' => 'invoice.terms',
        'asset_id' => null,
        'body_en' => 'Late payment attracts a fee.',
        'body_ar' => 'يُستحق رسم على التأخير في السداد.',
    ]);

    App::setLocale('ar');
    expect(DocumentText::for('invoice.terms'))->toBe('يُستحق رسم على التأخير في السداد.');

    App::setLocale('en');
    expect(DocumentText::for('invoice.terms'))->toBe('Late payment attracts a fee.');

    // Only one language written: the other reader gets THAT one rather than a gap. A missing
    // sentence on a document about money is worse than one in the wrong language.
    DocumentTemplate::query()->update(['body_ar' => null]);
    App::setLocale('ar');
    expect(DocumentText::for('invoice.terms'))->toBe('Late payment attracts a fee.');
});

it('prints an unknown token instead of silently deleting the sentence', function () {
    DocumentTemplate::create(['key' => 'invoice.footer', 'asset_id' => null, 'body_en' => 'Due in {days}, ref {amont}.']);

    // `{amont}` is a typo. Leaving it visible gets it reported; blanking it would quietly ship a
    // half sentence on every invoice.
    expect(DocumentText::for('invoice.footer', null, ['days' => 7]))->toBe('Due in 7, ref {amont}.');
});

it('refuses a template written for a slot no document renders', function () {
    // The inert-settings-screen failure, refused at the model layer rather than by the picker: a
    // crafted payload reaches the column, and a template nothing reads is one the operator writes,
    // saves, and never sees take effect.
    expect(fn () => DocumentTemplate::create(['key' => 'invoice.disclaimer', 'body_en' => 'x']))
        ->toThrow(DomainException::class);
});

it('keeps the registry and the value set from drifting apart', function () {
    // `ValueSets::expand()` resolves a `[Class, 'CONST']` reference through `constant()`, and a
    // const cannot have computed keys — so the flat list is its own constant and could drift from
    // the one the resolver actually reads.
    expect(DocumentText::KEY_NAMES)->toBe(array_keys(DocumentText::KEYS))
        ->and(ValueSets::allowed('document_templates', 'key'))->toBe(DocumentText::KEY_NAMES);
});

it('names a floor that exists, or says it has none', function () {
    foreach (DocumentText::KEYS as $key => $meta) {
        if ($meta['floor'] !== null) {
            expect(__($meta['floor']))->not->toBe($meta['floor'], "{$key} names a floor translation key that does not resolve");
        }
    }

    // The sweep must have found something — a registry that quietly emptied would pass the loop.
    expect(count(DocumentText::KEYS))->toBeGreaterThan(0);
});

it('puts the operator wording on the invoice itself, not just in the resolver', function () {
    // The resolver agreeing is a different claim from the DOCUMENT being right, and this project
    // has shipped a correct resolver behind a template that ignored it. Render the real blade.
    $lease = makeLease(makeUnit(makeAsset(['code' => 'MALL-PDF'])), makeTenant());
    $invoice = makeInvoice($lease, ['issue_date' => '2026-03-01', 'due_date' => '2026-03-08']);

    DocumentTemplate::create([
        'key' => 'invoice.footer',
        'asset_id' => null,
        'body_en' => 'Settle within {days} days by InstaPay.',
    ]);
    DocumentTemplate::create([
        'key' => 'invoice.payment_instructions',
        'asset_id' => null,
        'body_en' => 'CIB, account 1234567890, Atriom Walk',
    ]);

    $html = View::make('invoices.pdf', app(InvoicePdfService::class)->viewData($invoice->fresh()))->render();

    expect($html)->toContain('Settle within 7 days by InstaPay.')
        ->and($html)->toContain('CIB, account 1234567890, Atriom Walk')
        // …and the built-in sentence it replaced is gone, so this is a substitution rather than
        // the operator wording being appended under the old one.
        ->and($html)->not->toContain('Bank transfer / Card / InstaPay');
});

it('renders no payment-instructions heading until one is written', function () {
    // The control for the block that did not exist before: a heading over nothing on a document
    // about money reads as a missing instruction rather than an absent one.
    $lease = makeLease(makeUnit(makeAsset(['code' => 'MALL-PDF2'])), makeTenant());
    $invoice = makeInvoice($lease);

    $html = View::make('invoices.pdf', app(InvoicePdfService::class)->viewData($invoice->fresh()))->render();

    expect($html)->not->toContain(__('admin.pdf.payment_instructions'))
        // …while the footer, which HAS a floor, still prints.
        ->and($html)->toContain('Bank transfer / Card / InstaPay');
});
