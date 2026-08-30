<?php

declare(strict_types=1);

use App\Models\LeaseEvent;
use App\Support\LeaseEventNarrative;
use Illuminate\Support\Facades\App;
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

it('never discards what an operator typed in favour of a generic sentence', function (): void {
    // A service stamps a key on EVERY event it writes, including the ones a human explained. So a
    // resolver that tests the key first throws away the only part of the row that carries the WHY.
    // Measured on the demo books: a relief the operator explained as "Trading concession while the
    // north entrance is closed for works" rendered as "Rent relief granted — 54,000.00 reduced to
    // 40,500.00", which the figures in the same table already said.
    $typed = 'Trading concession while the north entrance is closed for works.';

    $explained = new LeaseEvent([
        'type' => 'abatement',
        'reason' => $typed,
        'payload' => [LeaseEventNarrative::KEY => 'relief_granted', 'amount_from' => 54000, 'amount_to' => 40500],
    ]);

    // Their words, unchanged, in either language — a person's account of what happened is not
    // something to translate or to replace.
    expect(LeaseEventNarrative::resolve($explained, 'en'))->toBe($typed)
        ->and(LeaseEventNarrative::resolve($explained, 'ar'))->toBe($typed);

    // …and the composed sentence is still what a row with NO words falls back to. Paired, because
    // a resolver that returned the stored column unconditionally would satisfy the assertion above
    // and quietly undo the whole change.
    $unexplained = new LeaseEvent([
        'type' => 'abatement',
        'reason' => null,
        'payload' => [LeaseEventNarrative::KEY => 'relief_granted', 'amount_from' => 54000, 'amount_to' => 40500],
    ]);

    expect(LeaseEventNarrative::resolve($unexplained, 'en'))->toContain('54,000.00')
        ->and(LeaseEventNarrative::resolve($unexplained, 'ar'))->toMatch('/\p{Arabic}/u');
});

it('resolves a classification token in the READER\'s language, not the session\'s', function (): void {
    // The half-translated shape: the sentence composes in the requested locale while a token
    // inside it resolves through a bare `trans()` and answers in whoever's session is running.
    // Measured on the real books with the panel in Arabic, an English read came back as
    // `خيار التجديد exercised — notice served 30/07/2026` — a sentence in two languages at once.
    // Exactly what `DocumentLocale::in()` exists to prevent on the PDFs: wrap the DATA, not just
    // the template, or you get an Arabic body under English headings.
    $event = new LeaseEvent([
        'type' => 'extension',
        'payload' => [
            LeaseEventNarrative::KEY => 'option_exercised',
            'option_type' => 'renewal',
            'notice_given_at' => '2026-07-30',
        ],
    ]);

    foreach (['en' => 'ar', 'ar' => 'en'] as $reader => $ambient) {
        // The ambient locale is deliberately the OTHER one, so a token reading it leaks visibly.
        App::setLocale($ambient);

        $sentence = LeaseEventNarrative::resolve($event, $reader);
        $optionType = trans('admin.lease_options.types.renewal', [], $reader);

        expect($sentence)->toContain($optionType)
            ->and($sentence)->not->toContain(trans('admin.lease_options.types.renewal', [], $ambient));
    }
});

it('has a service writing every narrative it defines', function (): void {
    // A key in the vocabulary that nothing stamps is a sentence nobody will ever read. The first
    // version of this gate checked parity and rendering and had exactly that hole: `rent_escalated`
    // was catalogued in both languages while the escalation sweep went on storing raw English
    // beside it, so the vocabulary looked complete and the timeline was not.
    $source = '';

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        $source .= $file->getContents();
    }

    $unwritten = array_values(array_filter(
        LeaseEventNarrative::KEYS,
        fn (string $key): bool => ! str_contains($source, "'{$key}'"),
    ));

    expect($unwritten)->toBe([], 'narratives nothing writes: '.implode(', ', $unwritten));
});

it('leaves no service composing prose into a lease event', function (): void {
    // The call graph, DERIVED: the services that write an event, plus the ones that hand them a
    // reason. One hop matters — `RentEscalationService` never names `RecordLeaseEventService`, it
    // goes through `LeaseRentChangeService`, so a sweep of the writers alone could not see it and
    // did not: it stored `Automatic rent escalation +10%` in raw English for the whole of its life.
    $files = [];

    foreach (Finder::create()->files()->in(app_path('Services'))->name('*.php') as $file) {
        $files[$file->getRelativePathname()] = $file->getContents();
    }

    $writers = array_keys(array_filter($files, fn (string $src): bool => str_contains($src, 'RecordLeaseEventService')));
    $classes = array_map(fn (string $path): string => basename($path, '.php'), $writers);

    $inGraph = array_filter($files, function (string $src, string $path) use ($classes): bool {
        foreach ($classes as $class) {
            if ($path !== $class.'.php' && str_contains($src, $class)) {
                return true;
            }
        }

        return in_array(basename($path, '.php'), $classes, true);
    }, ARRAY_FILTER_USE_BOTH);

    // A quoted string carrying two adjacent letters and a space is a SENTENCE, not a key or a
    // column name. Raw English is the worse half of this defect — it is not even translated — and
    // the first version of this gate matched `__(` alone and walked straight past it.
    $prose = '/(__\(|[\'"][^\'"]*[A-Za-z]{2,}[^\'"]*\s[^\'"]*[\'"])/';

    $offenders = [];

    foreach ($inGraph as $path => $src) {
        // (a) the reason ARGUMENT of a direct write. Bounded at `payload`, because a refusal
        // message and a transaction note elsewhere in the same file are translated at write time
        // quite correctly, and a gate that fired on those would be weakened rather than fixed.
        foreach (array_slice(preg_split('/->record\(/', $src), 1) as $call) {
            $payloadAt = strpos($call, 'payload');
            $head = substr($call, 0, $payloadAt === false ? 400 : $payloadAt);

            if (preg_match('/__\([\'"]admin\./', $head)) {
                $offenders[] = $path;
            }
        }

        // (b) a reason COMPOSED anywhere in a file that reaches an event — assigned to a variable
        // or handed over in a data array. This is the shape the escalation sweep had.
        foreach (preg_split('/\R/', $src) as $line) {
            if (str_starts_with(ltrim($line), '*') || str_starts_with(ltrim($line), '//')) {
                continue;
            }

            if (preg_match('/(\$reason[a-zA-Z_]* *=[^=]|[\'"]reason[\'"] *=>)(.*)$/', $line, $m) && preg_match($prose, $m[2])) {
                $offenders[] = $path;
            }
        }
    }

    expect(count($inGraph))->toBeGreaterThan(9, 'the sweep found almost no callers');

    expect(array_values(array_unique($offenders)))->toBe([], implode("\n", array_merge(
        ['These compose a lease-event reason at WRITE time and store the result, so it freezes in'],
        ['whichever language the run happened to be in — or, worse, in raw English that was never'],
        ['translatable at all. Stamp a narrative key instead:'],
        array_unique($offenders),
    )));
});
