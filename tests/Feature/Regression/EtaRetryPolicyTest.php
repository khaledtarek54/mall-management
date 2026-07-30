<?php

/**
 * The retry policy protecting tax submissions was never asserted.
 *
 * `SubmitInvoiceToEta` carries `$tries`, `backoff()` and `failed()`, all three
 * chosen for stated reasons — and no test read any of them. A refactor could have
 * dropped `$tries` (back to the worker's default: 3 attempts back-to-back within
 * seconds, hammering ETA's OAuth endpoint and overwriting `eta_response` with each
 * fresh error, losing the diagnostic trail), or dropped `failed()`, and everything
 * would still have been green. Roadmap P1: "the policy protecting tax submissions
 * is unverified".
 *
 * These are deliberately about the POLICY, not about what the job does on a happy
 * path — that is covered elsewhere. A failed tax submission that nobody hears
 * about is the outcome being guarded against.
 */

use App\Jobs\SubmitInvoiceToEta;
use App\Models\Invoice;
use App\Services\Eta\EtaSubmissionService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->tenant = makeTenant(['type' => 'company', 'tax_id' => '123456789']);
    $this->invoice = makeInvoice(makeLease(makeUnit($this->asset), $this->tenant));
});

it('retries a failing submission rather than giving up on the first error', function () {
    // ETA being briefly unreachable must not cost a filing.
    expect((new SubmitInvoiceToEta($this->invoice))->tries)->toBe(3);
});

it('backs off between attempts instead of re-storming the auth endpoint', function () {
    // The default is back-to-back within seconds. Against an OAuth endpoint that
    // rate-limits, three instant attempts are one attempt plus two guaranteed
    // failures — and the retries would be spent before the outage ends.
    $backoff = (new SubmitInvoiceToEta($this->invoice))->backoff();

    expect($backoff)->toBe([60, 300, 900]);

    // Each wait longer than the last, and the whole schedule long enough to ride
    // out a short outage AND give an operator time to fix a rejected field.
    expect($backoff)->toBe(collect($backoff)->sort()->values()->all())
        ->and(array_sum($backoff))->toBeGreaterThanOrEqual(900);
});

it('shouts when the retries are exhausted', function () {
    // The failure that matters: a tax document that never filed. It lands in
    // failed_jobs, but nobody reads failed_jobs — so it also goes to ops.log,
    // which /health and the alerting watch.
    $logPath = storage_path('logs/ops-test-'.uniqid().'.log');
    config()->set('logging.channels.ops', ['driver' => 'single', 'path' => $logPath, 'level' => 'debug']);

    (new SubmitInvoiceToEta($this->invoice))->failed(new RuntimeException('ETA gateway timeout'));

    expect(File::exists($logPath))->toBeTrue('The exhausted submission was never logged.');

    $contents = File::get($logPath);

    expect($contents)
        ->toContain('eta.job_exhausted')
        // Useless without knowing WHICH invoice.
        ->toContain($this->invoice->number)
        // ...and why it failed.
        ->toContain('ETA gateway timeout');

    File::delete($logPath);
});

it('survives being handed no exception at all', function () {
    // failed(?Throwable) is nullable — the queue can call it without one (a job
    // killed by a timeout or a worker restart). If that path threw, the alert
    // would be lost in exactly the case where the job died mysteriously.
    $logPath = storage_path('logs/ops-test-'.uniqid().'.log');
    config()->set('logging.channels.ops', ['driver' => 'single', 'path' => $logPath, 'level' => 'debug']);

    (new SubmitInvoiceToEta($this->invoice))->failed(null);

    expect(File::get($logPath))->toContain('eta.job_exhausted');

    File::delete($logPath);
});

it('is the job the submit action actually dispatches', function () {
    // The policy is worth nothing if the admin action calls the service inline —
    // then there are no retries at all, and the operator's request blocks on ETA.
    Queue::fake();

    SubmitInvoiceToEta::dispatch($this->invoice);

    Queue::assertPushed(
        SubmitInvoiceToEta::class,
        fn ($job) => $job->invoice->is($this->invoice),
    );
});

it('does not swallow the failure inside handle()', function () {
    // handle() must let the exception out, or the queue never counts an attempt
    // and $tries/backoff() are decoration — the job would "succeed" once while
    // the invoice was never filed.
    $this->mock(EtaSubmissionService::class)
        ->shouldReceive('submit')
        ->once()
        ->andThrow(new RuntimeException('ETA rejected the document'));

    expect(fn () => (new SubmitInvoiceToEta($this->invoice))->handle(app(EtaSubmissionService::class)))
        ->toThrow(RuntimeException::class);
});

it('carries the invoice by reference, so a retry re-reads its current state', function () {
    // SerializesModels stores the ID, not a snapshot. That is what lets an operator
    // fix a missing tax_id between attempts and have the next retry pick it up —
    // the reason backoff() is minutes rather than seconds.
    $job = new SubmitInvoiceToEta($this->invoice);

    $revived = unserialize(serialize($job));

    $this->invoice->update(['notes' => 'fixed after the first failure']);

    expect($revived->invoice->id)->toBe($this->invoice->id)
        ->and(Invoice::find($revived->invoice->id)->notes)->toBe('fixed after the first failure');
});
