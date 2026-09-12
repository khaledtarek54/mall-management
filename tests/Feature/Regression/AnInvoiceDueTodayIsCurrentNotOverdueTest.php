<?php

/*
|--------------------------------------------------------------------------
| An invoice due TODAY is current — it is late from tomorrow (SW-256)
|--------------------------------------------------------------------------
| Found on the staging soak, 2026-09-12, at 06:00: three Nile Gate invoices due that very day read
| **Overdue** on the register, in the portal and in the mobile app, while the AR ageing report
| beside them filed the same three under *Current*. Two surfaces disagreeing about one document.
|
| The status was the one departing from the market. Yardi's aging calls a document due today
| current, and so did every reader in this app except the status and four inline copies of the
| same test: `AgingBuckets::keyFor()` files `days <= 0` as current; the AR report, the AP list and
| both chase sweeps say `whereDate('<')`; the late-fee sweep waits out its grace. The status came
| from `due_date < now()` and `due_date->isPast()` — and `due_date` is a DATE cast, i.e. midnight,
| so both were true from 00:00 on the due day.
|
| `Invoice::isPastDue()` / `scopePastDue()` are the ONE boundary now, compared on DAYS, and the
| four inline `due_date->isPast()` copies — the mobile app's balance and summary, the tenant
| statement and the owner statement — read the predicate instead of restating it. Each of the four
| is a tooth here, because a predicate fixed in one place and restated in four is what shipped.
|
| The review found the fee's door: with `grace_days = 0` the late-fee sweep charged ON the due day
| — a penalty on a document every other reader now calls current, whose status the 06:00 sweep
| would then revert. Grace days are days AFTER the due date, and the fee lands on the day after
| the last of them (`due + grace + 1`) — which moves every fee one day later, on purpose, and is
| what the soak calendar had predicted for the September rent (8th due, 7 days, fee on the 16th).
*/

use App\Models\Invoice;
use App\Models\User;
use App\Services\AssetStatementPdfService;
use App\Services\LateFeeService;
use App\Services\Reports\ReportService;
use App\Services\TenantStatementPdfService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    // A fixed clock mid-morning, so "today" is unambiguous and the midnight boundary is real.
    Date::setTestNow(CarbonImmutable::parse('2026-09-12 09:30:00'));

    $this->asset = makeAsset(['code' => 'DUE']);
    $this->lease = makeLease(makeUnit($this->asset), null, ['status' => 'active']);
    $this->tenant = $this->lease->tenant;
    User::factory()->create();
});

afterEach(fn () => Date::setTestNow());

/** An unpaid, issued invoice with the given due date. */
function sw256Invoice(string $dueDate, float $amount = 10000): Invoice
{
    return makeInvoice(test()->lease, [
        'status' => 'issued',
        'issue_date' => '2026-09-01',
        'due_date' => $dueDate,
        'subtotal' => $amount,
        'total' => $amount,
        'paid_amount' => 0,
        'balance' => $amount,
    ]);
}

it('is not past due on its due day, and is from the next morning — the row twin', function () {
    $dueToday = sw256Invoice('2026-09-12');
    $dueYesterday = sw256Invoice('2026-09-11');

    expect($dueToday->isPastDue())->toBeFalse()
        ->and($dueToday->isOverdue())->toBeFalse()
        ->and($dueYesterday->isPastDue())->toBeTrue()
        ->and($dueYesterday->isOverdue())->toBeTrue();

    // 00:01 the next day is enough: the boundary is a DAY, not twenty-four hours.
    Date::setTestNow(CarbonImmutable::parse('2026-09-13 00:01:00'));
    expect($dueToday->isPastDue())->toBeTrue();
});

it('is excluded by the query twin on its due day, and included by its complement', function () {
    $dueToday = sw256Invoice('2026-09-12');
    $dueYesterday = sw256Invoice('2026-09-11');

    expect(Invoice::query()->pastDue()->pluck('id')->all())->toBe([$dueYesterday->id])
        ->and(Invoice::query()->notPastDue()->pluck('id')->all())->toBe([$dueToday->id])
        ->and(Invoice::query()->overdue()->pluck('id')->all())->toBe([$dueYesterday->id]);
});

it('keeps the STATUS at issued on the due day — the projection reads the same boundary', function () {
    $dueToday = sw256Invoice('2026-09-12');
    $dueYesterday = sw256Invoice('2026-09-11');

    // The projector: what `recomputeTotals()` writes, and what the tenant's badge shows.
    $dueToday->recomputeTotals();
    $dueYesterday->recomputeTotals();

    expect($dueToday->fresh()->status)->toBe('issued')
        ->and($dueYesterday->fresh()->status)->toBe('overdue');

    // The sweep that keeps the projection honest (SW-245) agrees: nothing to do for the one due
    // today, and it does NOT drag it to overdue.
    $this->artisan('billing:scan-overdue-invoices')->assertSuccessful();

    expect($dueToday->fresh()->status)->toBe('issued');
});

it('does not count a document due today in the mobile app\'s overdue figure', function (string $url) {
    // The app's home screen shows two numbers: what is outstanding and what is overdue. Before
    // this, a retailer whose rent was due today opened the app at breakfast and read the whole
    // amount as OVERDUE, while the operator's ageing report called it current.
    sw256Invoice('2026-09-12', 10000);
    sw256Invoice('2026-09-11', 2500);

    $d = $this->getJson($url, apiHeaders($this->tenant))->assertOk()->json('data');

    expect((float) $d['outstanding'])->toBe(12500.0)
        ->and((float) $d['overdue'])->toBe(2500.0);
})->with(['/api/v1/me/balance', '/api/v1/me/summary']);

it('does not count a document due today as overdue on the tenant statement', function () {
    sw256Invoice('2026-09-12', 10000);
    sw256Invoice('2026-09-11', 2500);

    $summary = app(TenantStatementPdfService::class)->data($this->tenant)['summary'];

    expect((float) $summary['outstanding'])->toBe(12500.0)
        ->and((float) $summary['overdue'])->toBe(2500.0);
});

it('does not count a document due today as overdue on the owner statement', function () {
    sw256Invoice('2026-09-12', 10000);
    sw256Invoice('2026-09-11', 2500);

    $summary = app(AssetStatementPdfService::class)->data($this->asset)['summary'];

    expect((float) $summary['outstanding'])->toBe(12500.0)
        ->and((float) $summary['overdue'])->toBe(2500.0);
});

it('files a document due today under Current on the ageing — the reader the status now agrees with', function () {
    sw256Invoice('2026-09-12', 10000);
    sw256Invoice('2026-09-11', 2500);

    $buckets = app(ReportService::class)->arAgingBuckets();

    expect((float) $buckets['current']['total'])->toBe(10000.0)
        ->and((float) $buckets['d_1_30']['total'])->toBe(2500.0);
});

it('charges no late fee on the due day even with zero grace, and does the next morning', function () {
    // The fee's door onto the same boundary. `LateFeeService::applyTo()` is the single-invoice
    // path the sweep also runs per row; a lease with `late_fee_grace_days = 0` is the sharpest case.
    $lease = makeLease(makeUnit($this->asset), null, ['status' => 'active', 'late_fee_grace_days' => 0]);
    $invoice = makeInvoice($lease, [
        'status' => 'issued', 'issue_date' => '2026-09-01', 'due_date' => '2026-09-12',
        'subtotal' => 10000, 'total' => 10000, 'paid_amount' => 0, 'balance' => 10000,
    ]);

    expect(app(LateFeeService::class)->applyTo($invoice))->toBeFalse()
        ->and(lateFeeItems($invoice)->count())->toBe(0)
        ->and($invoice->fresh()->status)->toBe('issued');

    Date::setTestNow(CarbonImmutable::parse('2026-09-13 04:00:00'));

    expect(app(LateFeeService::class)->applyTo($invoice->fresh()))->toBeTrue()
        ->and(lateFeeItems($invoice)->count())->toBe(1);
});

it('lands the fee on the day AFTER the last grace day — 8th due, 7 days, fee on the 16th', function () {
    $lease = makeLease(makeUnit($this->asset), null, ['status' => 'active', 'late_fee_grace_days' => 7]);
    $invoice = makeInvoice($lease, [
        'status' => 'issued', 'issue_date' => '2026-09-01', 'due_date' => '2026-09-08',
        'subtotal' => 10000, 'total' => 10000, 'paid_amount' => 0, 'balance' => 10000,
    ]);

    // The 15th is the seventh and last grace day.
    Date::setTestNow(CarbonImmutable::parse('2026-09-15 04:00:00'));
    expect(app(LateFeeService::class)->applyTo($invoice))->toBeFalse()
        ->and(lateFeeItems($invoice)->count())->toBe(0);

    Date::setTestNow(CarbonImmutable::parse('2026-09-16 04:00:00'));
    expect(app(LateFeeService::class)->applyTo($invoice->fresh()))->toBeTrue()
        ->and(lateFeeItems($invoice)->count())->toBe(1);
});

it('the BATCH does not select a document due today either — one predicate, not a restatement', function () {
    // The sweep's candidate set used `<= today`; a zero-grace lease due today would have been
    // selected AND charged by the batch. Now the batch reads `pastDue()`, the register's own boundary.
    $lease = makeLease(makeUnit($this->asset), null, ['status' => 'active', 'late_fee_grace_days' => 0]);
    $dueToday = makeInvoice($lease, [
        'status' => 'issued', 'issue_date' => '2026-09-01', 'due_date' => '2026-09-12',
        'subtotal' => 10000, 'total' => 10000, 'paid_amount' => 0, 'balance' => 10000,
    ]);
    $dueYesterday = makeInvoice($lease, [
        'status' => 'issued', 'issue_date' => '2026-09-01', 'due_date' => '2026-09-11',
        'subtotal' => 5000, 'total' => 5000, 'paid_amount' => 0, 'balance' => 5000,
    ]);

    DB::enableQueryLog();
    app(LateFeeService::class)->runForToday();
    $selection = collect(DB::getQueryLog())
        ->pluck('query')
        ->first(fn (string $sql) => str_contains($sql, '"invoices"') && str_contains($sql, 'due_date') && str_contains($sql, 'order by "id"'));
    DB::disableQueryLog();

    expect(lateFeeItems($dueToday)->count())->toBe(0)
        ->and(lateFeeItems($dueYesterday)->count())->toBe(1);

    // The outcome above is ALSO what the grace guard alone would produce — mutation showed the
    // batch back on `<= today` stays green through it — so the selection is pinned by its SQL
    // shape, the way the lock tests pin `join "payrolls"`: strictly before today, never on it.
    expect($selection)->not->toBeNull()
        // sqlite compiles `whereDate` to `strftime('%Y-%m-%d', "due_date") < cast(? as text)`;
        // the point is the operator after the column, on either driver.
        ->and($selection)->toMatch('/"due_date"\) < cast\(\? as text\)/')
        ->and($selection)->not->toMatch('/"due_date"\) <= /');
});
