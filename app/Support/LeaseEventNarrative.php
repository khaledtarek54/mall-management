<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\LeaseEvent;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Lang;

/**
 * A lease event's reason is a KEY plus DATA, resolved for whoever is reading it.
 *
 * The twin of {@see JournalNarrative}, under the rule this codebase states for the activity log
 * and the ledger: **a row stores DATA, never PROSE.** Ten services compose a reason and every one
 * of them ran it through `__()` at WRITE time and stored the result — so the sentence froze in
 * whichever language the panel happened to be in when the button was pressed.
 *
 * Measured on the demo books: 8 of 9 stored reasons were English, including one written by the
 * termination fix earlier the same day, and one that came out ARABIC only because that run happened
 * to be in Arabic. An Egyptian accountant reading the lease history sees whichever language a
 * colleague was using months ago, and no language switch can help them.
 *
 * **The stored `reason` column stays, and an operator's own words WIN over the key.** A service
 * stamps a key on every event it writes, including the ones a human explained, so the composed
 * sentence is the fallback for a row with no words rather than a replacement for one that has
 * them. Every pre-existing row has prose and no key, and a reader nobody has converted degrades to
 * today's wording rather than to a blank cell — which on a lease history reads as an event nobody
 * explained.
 *
 * The KEY lives in the payload rather than in a new column: the payload already carries every
 * figure these sentences quote, so a key beside them needs no migration and cannot drift from the
 * data it is describing.
 */
class LeaseEventNarrative
{
    /** The payload key that names the sentence. */
    public const KEY = 'narrative';

    /**
     * Every narrative a SERVICE composes. An operator's own words are not in here — those are
     * stored verbatim in `reason` and returned untouched.
     *
     * Adding one means adding its key here and to both lang files;
     * `LeaseEventNarrativeIsAKeyNotProseTest` fails on a key missing either language.
     */
    public const KEYS = [
        'option_exercised',
        'option_waived',
        'option_lapsed',
        'rent_escalated',
        'rent_escalated_collared',
        'rent_escalated_amount',
        'rent_changed',
        'relief_granted',
        'term_extended',
        'space_expanded',
        'space_contracted',
        'converted_to_holdover',
        'lease_terminated',
        'move_out_settled',
        'cam_estimate_applied',
    ];

    /**
     * What to show for this event: the operator's own words, else the composed sentence, else the
     * prose the row was written with.
     */
    public static function resolve(LeaseEvent $event, ?string $locale = null): ?string
    {
        $payload = (array) ($event->payload ?? []);
        $key = $payload[self::KEY] ?? null;

        // AN OPERATOR'S OWN WORDS WIN, whether or not a key sits beside them. A service stamps a
        // key on every event it writes, including the ones where a human typed the reason — so
        // testing the key first discarded exactly the sentence that carries the WHY. Measured on
        // the demo books: a relief the operator explained as "Trading concession while the north
        // entrance is closed for works" rendered as the generic "Rent relief granted — 54,000.00
        // reduced to 40,500.00", which the figures beside it in the same table already said.
        //
        // The composed sentence is what a row with NO words falls back to; the key is never a
        // reason to throw away a person's account of what happened.
        if (filled($event->reason)) {
            return $event->reason;
        }

        if ($key === null) {
            return $event->reason;
        }

        $locale = $locale ?? App::getLocale();
        $path = 'admin.lease_events.narratives.'.$key;

        if (! Lang::has($path, $locale, fallback: false) && ! Lang::has($path, 'en', fallback: false)) {
            return $event->reason;
        }

        return trans($path, self::tokens($payload, $locale), $locale);
    }

    /**
     * The payload, minus the plumbing, with dates and money already formatted.
     *
     * A missing placeholder renders an em dash rather than a leftover `:amount` — the trap
     * `JournalNarrative` records, and the reason every token is filled even when absent.
     *
     * **The locale is threaded in, not read off the app.** A classification token resolved through
     * a bare `trans()` answers in whoever's session is running, so an English sentence came back
     * reading `خيار التجديد exercised — notice served 30/07/2026` — composed in the requested
     * language with its own noun in the ambient one. The same half-translated shape
     * `DocumentLocale::in()` exists to prevent on the PDFs: wrapping the template and not the DATA
     * yields an Arabic body under English headings.
     */
    private static function tokens(array $payload, ?string $locale = null): array
    {
        $tokens = [];

        foreach ($payload as $name => $value) {
            if ($name === self::KEY || is_array($value)) {
                continue;
            }

            $tokens[$name] = match (true) {
                $value === null => '—',
                is_bool($value) => $value ? '✓' : '—',
                is_numeric($value) && str_contains((string) $name, 'amount') => number_format((float) $value, 2),
                default => (string) $value,
            };
        }

        // Classifications read as words, not as codes: `renewal` is a key the operator never sees
        // anywhere else in the panel.
        foreach (['option_type' => 'admin.lease_options.types.', 'rent_basis' => 'admin.enums.rent_basis.'] as $name => $group) {
            if (isset($tokens[$name])) {
                $tokens[$name] = trans($group.$tokens[$name], [], $locale);
            }
        }

        foreach (['notice_given_at', 'effective_from', 'contracted_expiry'] as $name) {
            if (isset($tokens[$name]) && $tokens[$name] !== '—') {
                $tokens[$name] = \Carbon\CarbonImmutable::parse($tokens[$name])->format('d/m/Y');
            }
        }

        return $tokens;
    }
}
