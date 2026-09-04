<?php

/**
 * A late fee of nothing is not a fee — SW-030.
 *
 * `0%` with a `0` minimum is how an operator says *"this lease carries no late-fee clause"*: both
 * lease fields and both settings fields are `->minValue(0)`, so it is a supported, reachable
 * configuration and there is no other switch for it. `applyTo()` computed
 * `max(0.0, round($chargeable * 0 / 100, 2))` = **0.00**, `Vat::rateForType('late_fee')` is 0 so
 * the total was 0.00 too, and `IssueInvoiceService` refuses only an EMPTY line set — never a
 * zero-amount one. (All three measured 2026-09-04.)
 *
 * So every overdue invoice minted an EGP 0.00 AR document. Three consequences, and the middle one
 * is the money:
 *
 *  1. the tenant is notified of a penalty of nothing;
 *  2. the zero fee is stamped onto `invoices.late_fee_invoice_id`, and `mayChargeAgain()` refuses
 *     while a LIVE fee stands — recurrence ships at 0, meaning once per invoice — so a real fee can
 *     never be charged on that invoice again, however the clause is later corrected;
 *  3. `InvoiceJournalizer` logs *"has items but no positive revenue"* for each one on every sync.
 *
 * Rounding reaches it with a rate set too: 2% of a 0.20 residual is 0.00.
 *
 * **`false`, not a refusal.** The only callers are the nightly sweep (`ApplyLateFees`,
 * `billing:apply-late-fees`) — there is no per-invoice button — so a `DomainException` would count
 * a deliberate configuration as `failed` in the 04:00 log every night. Its sibling
 * `BillBouncedChequeFeeService` draws the same line the other way, and correctly: an operator
 * pressed a button there and is owed `nsf_fee_failed_not_configured`.
 */

use App\Models\Invoice;
use App\Notifications\LateFeeAppliedNotification;
use App\Services\LateFeeService;
use App\Settings\BillingSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    // The portfolio tier is deliberately NON-zero throughout, so every zero below is the LEASE's
    // own clause. Without this the tests could pass on a fallback that happened to be 0 and would
    // prove nothing about the three-tier resolution.
    $settings = app(BillingSettings::class);
    $settings->late_fee_percent = 2;
    $settings->late_fee_grace_days = 7;
    $settings->late_fee_minimum = 50;
    $settings->late_fee_maximum = 0;
    $settings->late_fee_recurrence_days = 0;
    $settings->save();

    CarbonImmutable::setTestNow('2028-02-01');

    $this->lease = makeLease(makeUnit(makeAsset()), null, [
        'late_fee_percent' => 0,
        'late_fee_grace_days' => 0,
        'late_fee_minimum' => 0,
        'late_fee_maximum' => 0,
        'late_fee_recurrence_days' => 0,
    ]);

    $this->overdue = fn (float $balance): Invoice => makeInvoice($this->lease, [
        'due_date' => '2028-01-01',
        'status' => 'overdue',
        'balance' => $balance,
    ]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('mints no invoice and tells nobody when the clause charges nothing', function () {
    Notification::fake();

    $invoice = ($this->overdue)(12_000);
    $before = Invoice::count();

    expect(app(LateFeeService::class)->applyTo($invoice))->toBeFalse()
        ->and(Invoice::count())->toBe($before)
        ->and($invoice->fresh()->late_fee_invoice_id)->toBeNull();

    Notification::assertNothingSent();
});

it('still charges a fee the clause does state — the control', function () {
    // A guard that refused everything would satisfy the test above just as happily.
    Notification::fake();

    $this->lease->update(['late_fee_percent' => 2]);
    $invoice = ($this->overdue)(12_000);

    expect(app(LateFeeService::class)->applyTo($invoice))->toBeTrue();

    $fee = $invoice->fresh()->lateFeeInvoice;

    expect($fee)->not->toBeNull()
        ->and((float) $fee->items()->where('type', 'late_fee')->sole()->amount)->toBe(240.0);

    Notification::assertSentTimes(LateFeeAppliedNotification::class, 1);
});

it('leaves the invoice chargeable once the operator sets a rate', function () {
    // The money. Under the bug the first sweep stamped a 0.00 fee onto `late_fee_invoice_id`, and
    // `mayChargeAgain()` refuses while a live fee stands — so the invoice was penalty-proof for
    // ever and the correction below could never reach it.
    $invoice = ($this->overdue)(12_000);

    expect(app(LateFeeService::class)->applyTo($invoice))->toBeFalse();

    $this->lease->update(['late_fee_percent' => 2]);

    expect(app(LateFeeService::class)->applyTo($invoice->fresh()))->toBeTrue()
        ->and((float) $invoice->fresh()->lateFeeInvoice->total)->toBe(240.0);
});

it('charges nothing when a real rate rounds to nothing', function () {
    // The other door, and it needs no zero rate at all: 2% of a 0.20 residual is 0.00 with the
    // clause's minimum at 0.
    Notification::fake();

    $this->lease->update(['late_fee_percent' => 2]);
    $invoice = ($this->overdue)(0.20);
    $before = Invoice::count();

    expect(app(LateFeeService::class)->applyTo($invoice))->toBeFalse()
        ->and(Invoice::count())->toBe($before)
        ->and($invoice->fresh()->late_fee_invoice_id)->toBeNull();

    Notification::assertNothingSent();
});

it('keeps charging the minimum when only the percentage is zero', function () {
    // The line is drawn at the FEE, not at the rate. A clause of "no percentage, EGP 50 flat" is a
    // real clause and must go on billing — a guard on `$percent` would have broken it.
    $this->lease->update(['late_fee_percent' => 0, 'late_fee_minimum' => 50]);
    $invoice = ($this->overdue)(12_000);

    expect(app(LateFeeService::class)->applyTo($invoice))->toBeTrue()
        ->and((float) $invoice->fresh()->lateFeeInvoice->items()->where('type', 'late_fee')->sole()->amount)->toBe(50.0);
});
