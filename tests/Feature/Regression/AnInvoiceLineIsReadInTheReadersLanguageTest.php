<?php

declare(strict_types=1);

use App\Models\Charge;
use App\Models\InvoiceItem;
use App\Services\MonthlyBillingService;
use App\Support\LineNarrative;
use Carbon\CarbonImmutable;

/**
 * **A MONEY DOCUMENT'S LINES ARE WRITTEN IN THE LANGUAGE ITS READER READS.** — UX-30
 *
 * `DocumentLocale` (2026-08-27) made every PDF render in its reader's language, and
 * `JournalNarrative` / `LeaseEventNarrative` made a stored row keep DATA rather than prose. What
 * neither reached is the line text on the documents a TENANT reads.
 *
 * Two failures, and they look nothing alike from inside the services that had them. Five services
 * resolved a perfectly good lang key with `__()` at the moment the line was raised, so the sentence
 * froze in whichever language that run happened to be in — `config('app.locale')` on a scheduled
 * sweep. And `MonthlyBillingService` appended raw-English ` (in arrears)` and ` (75% pro-rated)`
 * with a month from `format('F Y')`, which is not localised at all — on every monthly invoice in
 * the portfolio.
 *
 * The fix is the shape the other two took: `description_key` + `description_data`, resolved when
 * the document is read. `description` stays as the FLOOR.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

it('renders one stored line in both languages', function () {
    CarbonImmutable::setTestNow('2026-09-05 09:00:00');

    $lease = makeLease(makeUnit(makeAsset()), null, [
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2027-12-31',
        'base_rent_monthly' => 30000,
        'service_charge_monthly' => 0,
        'has_marketing_levy' => false,
        'escalation_type' => 'none',
    ]);

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'amount' => 30000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0,
        'start_date' => '2026-01-01', 'is_active' => true,
    ]);

    $result = app(MonthlyBillingService::class)
        ->generateForLease($lease->fresh(), CarbonImmutable::parse('2026-09-01'));

    expect($result['status'])->toBe('created');

    $line = InvoiceItem::query()->latest('id')->firstOrFail();

    // The DATA is what is stored — never the sentence.
    expect($line->description_key)->toBe('billing.period');
    expect($line->description_data['period'])->toBe('2026-09-01');

    // …and ONE row reads correctly for both readers, including the MONTH, which was
    // `format('F Y')` and therefore English whatever the reader's language.
    expect($line->narrative('en'))->toBe('Base Rent - September 2026');

    $arabic = $line->narrative('ar');
    expect($arabic)->toContain('Base Rent');          // the operator's own words, never translated
    expect($arabic)->not->toContain('September');     // the month is the READER's
    expect($arabic)->toMatch('/\p{Arabic}/u');
});

it('names both ends of a multi-month cycle in the reader\'s language', function () {
    // A quarterly, semi-annual or annual lease bills a CYCLE, and the first pass handed
    // `cycleLabel()` through as verbatim text. That method uses `format('M Y')` — `DateTime::format`,
    // never localised — so every Arabic invoice for every non-monthly lease in the portfolio read
    // `Base Rent - Aug–Oct 2026`. Found by review; reproduced here, which is why this case exists.
    CarbonImmutable::setTestNow('2026-09-05 09:00:00');

    $lease = makeLease(makeUnit(makeAsset()), null, [
        'status' => 'active',
        'commencement_date' => '2026-08-01',
        'expiry_date' => '2027-12-31',
        'billing_frequency' => 'quarterly',
        'base_rent_monthly' => 30000,
        'service_charge_monthly' => 0,
        'has_marketing_levy' => false,
        'escalation_type' => 'none',
    ]);

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'amount' => 30000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0,
        'start_date' => '2026-08-01', 'is_active' => true,
    ]);

    $result = app(MonthlyBillingService::class)
        ->generateForLease($lease->fresh(), CarbonImmutable::parse('2026-08-01'));

    expect($result['status'])->toBe('created');

    $line = InvoiceItem::query()->latest('id')->firstOrFail();

    expect($line->description_key)->toBe('billing.cycle');
    // BOTH ENDS as dates — never a label the writer already formatted.
    expect($line->description_data)->toHaveKeys(['from', 'to']);
    expect($line->description_data['from'])->toBe('2026-08-01');

    expect($line->narrative('en'))->toBe('Base Rent - August 2026 – October 2026');

    $arabic = $line->narrative('ar');
    expect($arabic)->toContain('Base Rent');
    expect($arabic)->not->toContain('August');
    expect($arabic)->not->toContain('Aug–Oct');
    expect($arabic)->toMatch('/\p{Arabic}/u');
});

it('lets an operator who words a line themselves keep their words', function () {
    // The precedence `LeaseEventNarrative` learned the expensive way: testing the key first throws
    // away the only part of the row carrying what a person meant. Typing a description clears the
    // key, so their sentence is what every reader gets, in every language.
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset())));

    $line = InvoiceItem::create([
        'invoice_id' => $invoice->id,
        'description' => 'Templated',
        'description_key' => 'billing.period',
        'description_data' => ['name' => 'Base Rent', 'period' => '2026-09-01'],
        'type' => 'base_rent', 'amount' => 100, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 100,
    ]);

    expect($line->narrative('en'))->toBe('Base Rent - September 2026');

    $line->update(['description' => 'Agreed with the tenant by email on 3 September']);

    expect($line->fresh()->description_key)->toBeNull();
    expect($line->fresh()->narrative('ar'))->toBe('Agreed with the tenant by email on 3 September');
});

it('falls back to the stored prose for a line that carries no key', function () {
    // The floor. Every line raised before this existed has prose and no key, and a money document
    // with an unnamed line is worse than one in the wrong language.
    expect(LineNarrative::resolve(null, null, 'Service charge - August 2026', 'ar'))
        ->toBe('Service charge - August 2026');

    // A key nobody registered is not a licence to render `:placeholder` on a tax invoice either.
    expect(LineNarrative::resolve('billing.invented', ['name' => 'x'], 'Fallback', 'en'))
        ->toBe('Fallback');
});

it('resolves a classification inside the sentence for the reader too', function () {
    // The trap `LeaseEventNarrative` hit on screen and no test caught: a token resolved with a bare
    // `trans()` at write time produced ONE SENTENCE IN TWO LANGUAGES. Here the meter TYPE is stored
    // as a code and worded for whoever reads the line.
    $data = [
        'type' => 'electric', 'meter' => 'MTR-1', 'consumption' => '120.00',
        'uom' => 'kWh', 'period' => '2026-08-01',
    ];

    $en = LineNarrative::resolve('utility.recharge', $data, null, 'en');
    $ar = LineNarrative::resolve('utility.recharge', $data, null, 'ar');

    // The TOKEN itself, which is the whole point — asserting only the month and the meter number
    // left this passing with the read-time resolution deleted, because an unresolved placeholder
    // falls back to the raw code and nothing was looking at it. Found by mutation.
    expect($en)->toContain(trans('admin.enums.meter_type', [], 'en')['electric']);
    expect($ar)->toContain(trans('admin.enums.meter_type', [], 'ar')['electric']);
    expect($ar)->not->toContain(trans('admin.enums.meter_type', [], 'en')['electric']);

    // …and the raw code never reaches the page in either language.
    expect($en)->not->toContain('electric,');
    expect($ar)->not->toContain('electric ');

    expect($en)->toContain('August');
    expect($ar)->not->toContain('August');

    // The meter number is an identifier the operator reads off the device — never translated.
    expect($en)->toContain('MTR-1');
    expect($ar)->toContain('MTR-1');
});
