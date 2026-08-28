<?php

/*
|--------------------------------------------------------------------------
| Every reason the billing engine can give must be a sentence, in both languages
|--------------------------------------------------------------------------
| An operator pressed "Bill this period" on the lease's Billing forecast tab and read:
|
|     Nothing was billed
|     admin.billing_preview.reason.lease_not_billable
|
| `MonthlyBillingService` answers a refusal as a machine CODE. `admin.billing_preview.reason.*` is
| the short vocabulary a preview TABLE CELL renders, and it covered only the codes a PLAN can
| produce — not the three `generateForLease()` adds on top of one (`lease_not_billable`,
| `run_in_progress`, `exception`). The forecast's button routed those into that group anyway.
|
| **Why the existing gate could not see it.** TranslationKeyConformanceTest resolves an interpolated
| key to its PREFIX — `__('admin.billing_preview.reason.'.$reason)` is checked as
| `admin.billing_preview.reason`, which exists in both catalogues. Every leaf under a dynamic prefix
| is invisible to it, in every locale. That is the gate-checks-a-weaker-property shape, so the check
| here is on the LEAVES, and it derives them from the service's own source rather than from a list
| beside them — a gate that reads only the catalogue it guards cannot see what that catalogue omits.
|
| Test B is the one that found a second live defect: `not_billable_expired` reads "…so :period falls
| after its term" and the call site passed only `date`, so the operator was shown the literal
| ":period" in the middle of an otherwise complete sentence, in both languages.
*/

use App\Models\Lease;
use App\Support\BillingRefusal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;

/**
 * Every reason code `MonthlyBillingService` can answer, read off its own source.
 *
 * Two emission shapes: the `['status' => …, 'reason' => …]` returns of `generateForLease()`, and
 * the `$nothing('…')` helper `planInvoiceForLease()` refuses through.
 *
 * @return array<int, string>
 */
function billingReasonCodesFromSource(): array
{
    $source = File::get(app_path('Services/MonthlyBillingService.php'));

    preg_match_all("/'reason' => '([a-z_]+)'/", $source, $returned);
    preg_match_all("/\\\$nothing\('([a-z_]+)'\)/", $source, $planned);

    $codes = array_unique([...$returned[1], ...$planned[1]]);
    sort($codes);

    return $codes;
}

/**
 * One case per refusal the operator can actually be shown — which is NOT one per reason code.
 *
 * `lease_not_billable` reads three different ways depending on the lease (wrong status / term
 * ended / not yet commenced), and a gate with one fixture per CODE exercises whichever branch its
 * fixture happens to land in. Proved by mutation: with the `:period` fill deleted from
 * `not_billable_expired` — the live defect this file was written for — a per-code sweep stayed
 * green, because its `lease_not_billable` fixture was a draft lease and never reached that branch.
 *
 * @return array<string, array{code: string, attrs: array<string, mixed>}>
 */
function billingRefusalCases(): array
{
    return [
        'not active' => ['code' => 'lease_not_billable', 'attrs' => ['status' => 'draft']],
        'term ended' => ['code' => 'lease_not_billable', 'attrs' => ['status' => 'active', 'commencement_date' => '2025-01-01', 'expiry_date' => '2026-06-30']],
        'not commenced' => ['code' => 'lease_not_billable', 'attrs' => ['status' => 'active', 'commencement_date' => '2027-01-01', 'expiry_date' => '2028-12-31']],
        'already billed' => ['code' => 'already_billed', 'attrs' => []],
        'no charges' => ['code' => 'no_applicable_charges', 'attrs' => []],
        'fit-out' => ['code' => 'fit_out', 'attrs' => []],
        'off cycle' => ['code' => 'off_cycle', 'attrs' => ['billing_frequency' => 'quarterly']],
        'off cycle annually' => ['code' => 'off_cycle', 'attrs' => ['billing_frequency' => 'annual']],
        'lease ended' => ['code' => 'lease_ended', 'attrs' => ['expiry_date' => '2026-06-30']],
        'run in progress' => ['code' => 'run_in_progress', 'attrs' => []],
        'threw' => ['code' => 'exception', 'attrs' => []],
    ];
}

/** The lease a case describes. Persisted, because a refusal reads real columns off a real row. */
function leaseForRefusalCase(array $case): Lease
{
    return makeLease(makeUnit(makeAsset()), makeTenant(), $case['attrs'] + [
        'status' => 'active',
        'commencement_date' => '2025-01-01',
        'expiry_date' => '2027-12-31',
    ]);
}

it('0: the cases below cover every reason the service can answer', function () {
    // The sweep is only as wide as its fixture list, so the list is checked against the source
    // rather than trusted. A new reason code fails HERE, naming itself, rather than going unswept.
    $covered = array_values(array_unique(array_column(billingRefusalCases(), 'code')));
    sort($covered);

    expect($covered)->toBe(billingReasonCodesFromSource());
})->group('conformance');

it('A: every refusal the billing engine can answer is worded in English AND Arabic', function () {
    $codes = billingReasonCodesFromSource();

    // Vacuity guard: a regex that silently stopped matching would pass this file end to end.
    expect($codes)->toContain('lease_not_billable', 'already_billed', 'fit_out', 'off_cycle')
        ->and(count($codes))->toBeGreaterThanOrEqual(8);

    $period = CarbonImmutable::parse('2026-08-01');
    $problems = [];

    foreach (billingRefusalCases() as $label => $case) {
        $code = $case['code'];
        $lease = leaseForRefusalCase($case);

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);

            $refusal = BillingRefusal::explain($lease, $period, ['status' => 'skipped', 'reason' => $code]);

            foreach (['title', 'body'] as $part) {
                $text = $refusal[$part];

                if (trim($text) === '') {
                    $problems[] = "[{$locale}] {$code} ({$label}): empty {$part}";

                    continue;
                }

                // The literal key, which is what Laravel returns for a key that does not exist.
                if (preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+){2,}$/i', $text) === 1) {
                    $problems[] = "[{$locale}] {$code} ({$label}): {$part} is a raw key — {$text}";
                }
            }
        }
    }

    app()->setLocale('en');

    expect($problems)->toBe([], "A refusal the operator cannot read:\n  ".implode("\n  ", $problems));
})->group('conformance');

it('B: no refusal shows the operator an unfilled :placeholder', function () {
    // `not_billable_expired` said "…so :period falls after its term" while its only call site passed
    // `date`. Both languages, on the message an operator reads when a lease will not bill.
    $period = CarbonImmutable::parse('2026-08-01');
    $leftovers = [];

    foreach (billingRefusalCases() as $label => $case) {
        $lease = leaseForRefusalCase($case);

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);

            $refusal = BillingRefusal::explain($lease, $period, ['status' => 'skipped', 'reason' => $case['code']]);

            foreach (['title', 'body'] as $part) {
                if (preg_match_all('/:[a-z_]{3,}/', $refusal[$part], $found)) {
                    $leftovers[] = "[{$locale}] {$case['code']} ({$label}).{$part}: ".implode(', ', $found[0]);
                }
            }
        }
    }

    app()->setLocale('en');

    expect($leftovers)->toBe([], "Placeholders the call site never filled:\n  ".implode("\n  ", $leftovers));
})->group('conformance');

it('C: the outcome column can name every reason too, and carries no stale one', function () {
    // The short vocabulary, beside the long one: a preview row's badge renders this group directly.
    // `unknown` is the deliberate fallback for a row carrying no reason at all, so it is expected
    // to have no emitter.
    $codes = billingReasonCodesFromSource();
    $problems = [];

    foreach ($codes as $code) {
        foreach (['en', 'ar'] as $locale) {
            // `fallback: false` — with Laravel's default, every key present in English passes.
            if (! Lang::has("admin.billing_preview.reason.{$code}", $locale, fallback: false)) {
                $problems[] = "[{$locale}] admin.billing_preview.reason.{$code} is missing";
            }
        }
    }

    foreach (array_keys(__('admin.billing_preview.reason')) as $offered) {
        if ($offered !== 'unknown' && ! in_array($offered, $codes, true)) {
            $problems[] = "admin.billing_preview.reason.{$offered} is stale — nothing answers that reason any more";
        }
    }

    expect($problems)->toBe([], implode("\n  ", array_merge([''], $problems)));
})->group('conformance');

it('D: every screen that bills by hand explains a refusal the same way', function () {
    // The defect was not the missing string — it was that two screens turned one machine code into
    // words independently, so only one of them was updated when the vocabulary grew. Derived from
    // the call, not from a list of the two screens that happen to do it today.
    $offenders = [];

    foreach (File::allFiles(app_path('Filament')) as $file) {
        $source = $file->getContents();

        if (! str_contains($source, '->generateForLease(')) {
            continue;
        }

        if (! str_contains($source, 'BillingRefusal::explain(')) {
            $offenders[] = $file->getRelativePathname();
        }
    }

    expect($offenders)->toBe([], 'These screens raise an invoice by hand and word the refusal themselves: '.implode(', ', $offenders));

    // …and the sweep must actually have found the screens it is reporting on.
    $callers = collect(File::allFiles(app_path('Filament')))
        ->filter(fn ($f): bool => str_contains($f->getContents(), '->generateForLease('));

    expect($callers)->toHaveCount(2);
})->group('conformance');
