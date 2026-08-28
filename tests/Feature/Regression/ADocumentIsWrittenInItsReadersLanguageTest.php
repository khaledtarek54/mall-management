<?php

use App\Models\CreditNote;
use App\Models\Employee;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PurchaseRequest;
use App\Models\Vendor;
use App\Services\CreditNotePdfService;
use App\Services\InvoicePdfService;
use App\Services\PurchaseOrderPdfService;
use App\Services\ReceiptPdfService;
use App\Services\TenantStatementPdfService;
use App\Support\Pdf\Bidi;
use App\Support\Pdf\DocumentLocale;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;

/**
 * **A document is written in the language its READER reads, not its sender's.**
 *
 * Every PDF this system issued rendered in `app()->getLocale()` — the language of whoever pressed
 * the button, or of `config('app.locale')` when a scheduled billing run pressed it. So an operator
 * working the panel in Arabic e-mailed an Arabic invoice to a retailer whose accountant files in
 * English, and had no way to produce the other copy short of changing their own UI language,
 * downloading again, and changing it back.
 *
 * These assertions read the DOCUMENT (`PdfDocument::html()`), not the service's inputs. Asserting
 * that `build()` was handed a locale proves the test agrees with itself; asserting that the invoice
 * says «فاتورة» proves what the tenant receives.
 *
 * Every refusal is paired with a control that must succeed, because a resolver that answered one
 * fixed language would satisfy half of these on its own.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
});

/**
 * An issued invoice with a line, a lease and a tenant.
 *
 * A local name rather than a shared helper: `tests/Pest.php` is where shared ones belong, and a
 * second file-scope `makeInvoice` would be a fatal redeclaration that exits the whole suite 255
 * with no output (`TestHelperUniquenessConformanceTest` is the gate that names both files).
 */
function readersLanguageInvoice(array $tenantAttributes = []): Invoice
{
    $tenant = makeTenant($tenantAttributes);
    $unit = makeUnit(makeAsset());
    $lease = makeLease($unit, $tenant);

    $invoice = makeInvoice($lease, ['status' => 'issued']);

    $invoice->items()->create([
        'description' => 'Monthly Rent',
        'type' => 'base_rent',
        'amount' => 10_000,
        'vat_rate' => 0,
        'vat_amount' => 0,
        'total' => 10_000,
    ]);

    return $invoice->fresh(['items', 'tenant', 'lease.unit.floor', 'asset']);
}

it('renders the invoice in the language it was asked for, in both directions', function () {
    $invoice = readersLanguageInvoice();
    $service = app(InvoicePdfService::class);

    $arabic = $service->document($invoice, 'ar')->html();
    $english = $service->document($invoice, 'en')->html();

    // The document TITLE is the assertion that matters: it is the line the reader uses to decide
    // what this piece of paper is.
    expect($arabic)->toContain('فاتورة')
        ->and($arabic)->toContain('dir="rtl"')
        ->and($english)->toContain('Invoice')
        ->and($english)->toContain('dir="ltr"')
        // The control: not one fixed language dressed up. Each must NOT be the other.
        ->and($english)->not->toContain('فاتورة')
        ->and($arabic)->not->toContain('dir="ltr"');
});

it('defaults to the tenant\'s own stored language when nobody picks one', function () {
    $arabicReader = readersLanguageInvoice(['locale' => 'ar']);
    $englishReader = readersLanguageInvoice(['locale' => 'en']);

    // The operator is working in English throughout — which is the whole point. Before this, the
    // operator's locale decided both documents.
    App::setLocale('en');

    $service = app(InvoicePdfService::class);

    expect($service->document($arabicReader)->html())->toContain('فاتورة')
        ->and($service->document($englishReader)->html())->not->toContain('فاتورة');
});

it('lets an explicit pick beat the tenant\'s stored language', function () {
    // The reason the picker exists at all: a tenant whose stored language is Arabic may have a
    // foreign auditor who asked for the English copy, and the operator can see that and the column
    // cannot.
    $invoice = readersLanguageInvoice(['locale' => 'ar']);

    expect(app(InvoicePdfService::class)->document($invoice, 'en')->html())
        ->not->toContain('فاتورة');
});

it('clamps a language it has no catalogue for instead of rendering a blank document', function () {
    // A stored `locale` is a five-character column, and `__()` fails SILENTLY into the fallback
    // locale rather than erroring — so an unsupported value is invisible at every layer except the
    // finished document.
    //
    // Written with a RAW update, deliberately: since 2026-08-28 `ValueSets` refuses `fr-CA` on save,
    // so the model layer can no longer produce this row. The clamp is still what defends the rows
    // that PREDATE that registration, and anything written by a `saveQuietly()` or a migration —
    // which is precisely the state a guard at the write cannot reach backwards into.
    $invoice = readersLanguageInvoice();
    DB::table('tenants')->where('id', $invoice->tenant_id)->update(['locale' => 'fr-CA']);
    $invoice = $invoice->fresh(['items', 'tenant', 'lease.unit.floor', 'asset']);

    App::setLocale('ar');

    expect(DocumentLocale::resolve('klingon'))->toBeIn(DocumentLocale::supported())
        // Falls through the unsupported stored value to the request's own language.
        ->and(app(InvoicePdfService::class)->document($invoice)->html())->toContain('فاتورة');
});

it('restores the request\'s language after rendering, even when the template throws', function () {
    // Without the `finally`, an operator presses Download, sees a refusal, and finds their panel has
    // silently switched to the tenant's language for the rest of the request.
    App::setLocale('en');

    expect(fn () => DocumentLocale::in('ar', fn () => throw new RuntimeException('boom')))
        ->toThrow(RuntimeException::class);

    expect(App::getLocale())->toBe('en');

    // And on the ordinary path.
    DocumentLocale::in('ar', fn () => null);
    expect(App::getLocale())->toBe('en');
});

it('carries the language through the credit note, receipt and statement too', function () {
    // One tenant, four documents. The invoice and its credit note are filed together, and a
    // difference in language between them is a difference the reader has to account for.
    $invoice = readersLanguageInvoice(['locale' => 'ar']);
    $tenant = $invoice->tenant;

    App::setLocale('en');

    $note = CreditNote::create([
        'tenant_id' => $tenant->id,
        'invoice_id' => $invoice->id,
        'asset_id' => $invoice->asset_id,
        'status' => 'issued',
        'issue_date' => '2026-02-05',
        'reason' => 'other',
        'subtotal' => 1_000,
        'vat_amount' => 0,
        'total' => 1_000,
        'applied_amount' => 0,
        'balance' => 1_000,
        'currency' => 'EGP',
    ]);

    $payment = Payment::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'captured',
    ]);

    expect(app(CreditNotePdfService::class)->document($note)->html())->toContain('إشعار دائن')
        ->and(app(ReceiptPdfService::class)->document($payment)->html())->toContain('سند قبض')
        ->and(app(TenantStatementPdfService::class)->document($tenant)->html())->toContain('كشف حساب');
});

it('fences operator-typed text so it keeps its own direction', function () {
    // The bidi algorithm resolves a NEUTRAL character — a full stop, a plus — by what surrounds it,
    // so inside an Arabic document an English sentence rendered ".Issued in error" and a phone
    // number rendered "201808046413+". Both were on the shipped documents. Intermittent, too: it
    // depends on what else shares the line box, which is why it survived review.
    expect(Bidi::isolate('Issued in error.'))->toStartWith(Bidi::LRM)->toEndWith(Bidi::LRM)
        ->and(Bidi::isolate('+201808046413'))->toStartWith(Bidi::LRM)
        // FIRST strong character, not "contains any Arabic": «الغرير للتجارة LLC» is an Arabic name.
        ->and(Bidi::isolate('الغرير للتجارة LLC'))->toStartWith(Bidi::RLM)
        // An empty value comes back untouched — two marks around nothing is a string a template's
        // `@if` would read as present.
        ->and(Bidi::isolate(''))->toBe('')
        ->and(Bidi::isolate(null))->toBe('');

    // Per LINE for a block, so a paragraph whose first line is Arabic and whose second is an IBAN
    // does not resolve the IBAN as right-to-left.
    $block = Bidi::isolateLines("تحويل بنكي\nEG38 0019 0005 0000 0000 2631 8");
    expect(substr_count($block, Bidi::RLM))->toBe(2)
        ->and(substr_count($block, Bidi::LRM))->toBe(2);
});

it('renders a real PDF in both languages, not only HTML', function () {
    // The HTML assertions above are the readable ones; this is the one that proves mpdf accepts what
    // the shared renderer hands it — a font it can load, a footer it can place, a watermark it can
    // shape. A template change that only breaks inside mpdf would pass everything above.
    $invoice = readersLanguageInvoice();
    $service = app(InvoicePdfService::class);

    foreach (['en', 'ar'] as $locale) {
        $bytes = $service->build($invoice, $locale);

        expect($bytes)->toStartWith('%PDF-')
            ->and(strlen($bytes))->toBeGreaterThan(2000);
    }
});

it('follows the SUPPLIER and the EMPLOYEE, once they have stated a language', function () {
    // `2026_08_12_260000` gave `locale` to everyone a NOTIFICATION is addressed to — users, portal
    // logins, tenants. That is the right set for a notification and the wrong one for a DOCUMENT: a
    // purchase order and a withholding certificate go to a supplier, a payslip goes to a person, and
    // both fell through to the operator's language with the download picker as the only remedy.
    $arabicVendor = Vendor::create(['name' => 'الغرير للتجارة', 'status' => Vendor::STATUS_ACTIVE, 'locale' => 'ar']);
    $silentVendor = Vendor::create(['name' => 'Cool-Air HVAC', 'status' => Vendor::STATUS_ACTIVE]);

    $arabicEmployee = Employee::create([
        'asset_id' => makeAsset()->id,
        'code' => 'EMP-'.uniqid(),
        'name' => 'منى سعد',
        'position' => 'HR Officer',
        'hire_date' => '2025-11-01',
        'base_salary' => 9000,
        'status' => 'active',
        'locale' => 'ar',
    ]);

    // The operator is working in English throughout.
    App::setLocale('en');

    expect(DocumentLocale::resolve(null, $arabicVendor))->toBe('ar')
        ->and(DocumentLocale::resolve(null, $arabicEmployee))->toBe('ar')
        // The control: null is the normal state and must NOT be read as a preference. Without this
        // the two assertions above pass on a resolver that answers 'ar' for anything with a `locale`
        // attribute at all.
        ->and(DocumentLocale::resolve(null, $silentVendor))->toBe('en')
        // And an explicit pick still beats a stated preference, on these parties as on a tenant.
        ->and(DocumentLocale::resolve('en', $arabicVendor))->toBe('en');
});

it('refuses a language it has no catalogue for at the MODEL layer', function () {
    // Registered in `ValueSets` because the failure is silent in both directions: `__()` falls
    // through an unknown locale into the fallback language without erroring, and
    // `DocumentLocale::resolve()` skips the tier entirely — so a typo'd or imported `fr-CA` leaves
    // the column looking set and every document rendering in English. Before registration the
    // column accepted anything.
    expect(fn () => Vendor::create([
        'name' => 'Wrong Language Ltd',
        'status' => Vendor::STATUS_ACTIVE,
        'locale' => 'fr-CA',
    ]))->toThrow(DomainException::class);

    // Paired with the control that must SUCCEED — a guard that refused everything would satisfy the
    // refusal above on its own.
    expect(Vendor::create([
        'name' => 'Right Language Ltd',
        'status' => Vendor::STATUS_ACTIVE,
        'locale' => 'ar',
    ])->locale)->toBe('ar');
});

it('writes the supplier\'s purchase order in the supplier\'s language', function () {
    // End to end through the real service, because the resolver being right says nothing about
    // whether the SERVICE hands it the right party — that is the half a unit test on
    // `DocumentLocale` cannot see.
    $vendor = Vendor::create(['name' => 'الغرير للتجارة', 'status' => Vendor::STATUS_ACTIVE, 'locale' => 'ar']);

    $po = PurchaseRequest::create([
        'asset_id' => makeAsset()->id,
        'reference' => 'PR-'.uniqid(),
        'status' => PurchaseRequest::STATUS_ORDERED,
        'justification' => 'Filters low.',
        'vendor_id' => $vendor->id,
        'total_value' => 500,
    ]);

    App::setLocale('en');

    expect(app(PurchaseOrderPdfService::class)->document($po)->html())
        ->toContain('أمر شراء')
        // …and the operator can still ask for the other copy.
        ->and(app(PurchaseOrderPdfService::class)->document($po, 'en')->html())
        ->not->toContain('أمر شراء');
});
