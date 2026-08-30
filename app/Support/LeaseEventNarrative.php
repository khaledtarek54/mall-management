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
 * **The stored `reason` column stays and is still the floor.** Every pre-existing row has prose and
 * no key, a reason an operator TYPED is theirs and must never be replaced, and a reader nobody has
 * converted degrades to today's wording rather than to a blank cell — which on a lease history
 * reads as an event nobody explained.
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

        // An operator's reason is theirs. It is stored in `reason` with no key beside it, so a
        // narrative key present means the service composed it and the stored copy is only a floor.
        if ($key === null) {
            return $event->reason;
        }

        $locale = $locale ?? App::getLocale();
        $path = 'admin.lease_events.narratives.'.$key;

        if (! Lang::has($path, $locale, fallback: false) && ! Lang::has($path, 'en', fallback: false)) {
            return $event->reason;
        }

        return trans($path, self::tokens($payload), $locale);
    }

    /**
     * The payload, minus the plumbing, with dates and money already formatted.
     *
     * A missing placeholder renders an em dash rather than a leftover `:amount` — the trap
     * `JournalNarrative` records, and the reason every token is filled even when absent.
     */
    private static function tokens(array $payload): array
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
                $tokens[$name] = trans($group.$tokens[$name]);
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
