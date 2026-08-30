<?php

declare(strict_types=1);

use App\Support\LeaseEventNarrative;
use Illuminate\Support\Facades\Lang;
use Symfony\Component\Finder\Finder;

/**
 * A LEASE EVENT'S REASON IS A KEY, RESOLVED FOR WHOEVER IS READING IT.
 *
 * Ten services compose a reason and every one of them ran it through `__()` at WRITE time and
 * stored the result — so the sentence froze in whichever language the panel was in when the button
 * was pressed. Measured on the demo books: 8 of 9 stored reasons were English, one of them written
 * by a fix made the same day, and one that came out ARABIC only because that run happened to be.
 *
 * The rule this repo already states for the activity log and the ledger, arriving through a third
 * door: **a row stores DATA, never PROSE.**
 */
it('has both languages for every narrative it can write', function (): void {
    $missing = [];

    foreach (LeaseEventNarrative::KEYS as $key) {
        foreach (['en', 'ar'] as $locale) {
            // `fallback: false` — `Lang::has()` falls back to English by default, so the obvious
            // parity check only ever catches a key missing from BOTH.
            if (! Lang::has("admin.lease_events.narratives.{$key}", $locale, fallback: false)) {
                $missing[] = "{$key} ({$locale})";
            }
        }
    }

    expect($missing)->toBe([]);
});

it('writes a real sentence in each language, with no placeholder left behind', function (string $key): void {
    foreach (['en', 'ar'] as $locale) {
        $rendered = trans("admin.lease_events.narratives.{$key}", [], $locale);

        expect($rendered)->not->toBe("admin.lease_events.narratives.{$key}")
            // A leftover `:amount` on a lease history is the trap `JournalNarrative` records.
            ->and($rendered)->not->toContain(': ');
    }

    // And the Arabic must actually be Arabic — an English string in the ar file passes every
    // `Lang::has()` check ever written.
    expect(trans("admin.lease_events.narratives.{$key}", [], 'ar'))->toMatch('/\p{Arabic}/u');
})->with(LeaseEventNarrative::KEYS);

it('leaves no service composing prose into a lease event', function (): void {
    $offenders = [];
    $swept = 0;

    foreach (Finder::create()->files()->in(app_path('Services'))->name('*.php') as $file) {
        $source = $file->getContents();

        if (! str_contains($source, 'RecordLeaseEventService')) {
            continue;
        }

        $swept++;

        // ONLY the reason ARGUMENT, not every `__()` in the file — a refusal message and a
        // transaction note are translated at write time quite correctly, and a gate that fired on
        // those would be weakened rather than fixed. The reason is what sits between the effective
        // date and the payload, so the window is bounded at both ends.
        $calls = preg_split('/->record\(/', $source);
        array_shift($calls);

        foreach ($calls as $call) {
            $payloadAt = strpos($call, 'payload');
            $head = substr($call, 0, $payloadAt === false ? 400 : $payloadAt);

            if (preg_match('/__\([\'"]admin\./', $head)) {
                $offenders[] = $file->getRelativePathname();
                break;
            }
        }
    }

    expect($swept)->toBeGreaterThan(5, 'the sweep found almost no callers');

    expect(array_unique($offenders))->toBe([], implode("\n", array_merge(
        ['These translate a lease-event reason at WRITE time and store the result, so it freezes'],
        ['in whichever language the run happened to be in. Stamp a narrative key instead:'],
        array_unique($offenders),
    )));
});
