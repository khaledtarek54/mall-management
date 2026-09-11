<?php

namespace App\Support;

use Closure;

/**
 * **THE COLOUR A CLASSIFICATION VALUE RENDERS IN — ONE ANSWER PER VALUE, WHATEVER SCREEN SHOWS IT.**
 *
 * A status badge's colour is a property of the STATUS, not of the screen. Filament's own idiom
 * says so (`HasColor` on an enum: the value carries its colour), and so does every reference
 * system — Voyager colours a lease status the same on the tenant's lease list, the unit's lease
 * history and the lease register, because an operator reads the colour before the word.
 *
 * ## What was measured (2026-09-11)
 *
 * This application holds its vocabularies as `ValueSets` strings, not enums, so nothing carried
 * the colour and every screen wrote its own `match ($state) { … }`. A sweep of every Filament file
 * found **87** such maps over **41** vocabularies; **15** vocabularies were coloured in more than
 * one file and **7 of those disagreed with themselves**:
 *
 *   - `leases.status` — FIVE maps, five different answers. A lease reads `future` as blue on the
 *     register and grey on the tenant's leases tab; `renewed` is blue, grey or amber depending on
 *     which tab is open; `expired` is grey on four screens and RED on the tenant portal.
 *   - `units.status` — `vacant` is red on the unit register and the occupancy map, amber on the
 *     property's own Units tab; `reserved` amber vs grey; `maintenance` grey vs RED.
 *   - `rentable_items.status` — the register colours a free bay AMBER (unlet is the leasing
 *     signal) and a let one green; the property's Parking tab colours a free bay GREEN and a let
 *     one grey. The same fact with its meaning inverted, one click apart.
 *   - `invoices.status` — the tenant's invoices tab colours `issued`, `draft`, `credited` and
 *     `written_off` amber (its `default`), where the register and the lease tab colour `issued`
 *     blue and the rest grey; the register colours `disputed` amber where four other screens
 *     colour it grey.
 *   - `credit_notes.status` — the operator's register and the tenant's portal disagree on every
 *     value but `applied`.
 *   - `tenant_requests.status` — `in_progress` is blue on the tenant's requests tab and primary
 *     on the six other screens.
 *   - `payrolls.status` — a cancelled run is red on the employee's payslips tab and grey on the
 *     payroll register.
 *
 * None of it was reported, because each file is right on its own and nobody opens two tabs to
 * compare a badge. `FacilityVocabulary::statusColor()` was the one seam that already existed —
 * for work orders, read by the operator's board and the contractor portal — and it is the shape
 * generalised here.
 *
 * ## The rule for which colour won
 *
 * **The resource's own register is the definition; tabs, widgets, maps and the portal adopt it.**
 * The register is the screen the module doc and the screen guide describe and the one an operator
 * sees most; a tab is a narrowed copy of it and a portal twin is the same document read by the
 * other party. Where the register itself had no opinion (a value falling to its `default`), the
 * majority of the other screens decided.
 *
 * ## What is here and what is deliberately not
 *
 * Registered: every vocabulary coloured in MORE THAN ONE file — the repo's rule is to extract on
 * the second real call site, and a value coloured on exactly one screen has nothing to disagree
 * with yet — plus the four whose VALUES are shared with a twin table (`tenants`/`vendors`
 * `.status`, `deposit_transactions`/`expenses` `.status`), each read by one screen today,
 * registered as pairs so the twins cannot drift apart either. `BadgeColorsConformanceTest` is what
 * makes the second site the moment to move both: it fails on an inline map for a registered
 * vocabulary, and on any vocabulary that acquires a second inline map anywhere in the panel.
 *
 * Every value of a registered set is named explicitly, and the gate checks the entry against
 * `ValueSets` in both directions — because `future` was added to `leases.status` on 2026-09-10 and
 * got a colour on the register only, which is exactly how five answers came to exist. An unknown
 * value (a legacy or imported row) still renders, in grey: a badge that throws on a status the
 * column happens to hold would take the list down, and the gate is where completeness is
 * enforced, not the render.
 */
final class BadgeColors
{
    /** What an unregistered or unknown value renders in — neutral, never an alarm. */
    public const FALLBACK = 'gray';

    /**
     * Keyed by the `ValueSets` key, one colour per value.
     *
     * @var array<string, array<string, string>>
     */
    public const MAP = [
        // ── Money documents ──────────────────────────────────────────────────────────────
        'invoices.status' => [
            'draft' => 'gray',
            'issued' => 'info',
            'partially_paid' => 'warning',
            'paid' => 'success',
            'overdue' => 'danger',
            // Amber on the register (a contested line still needs an answer), grey on four other
            // screens; the register wins, and the tenant's own copy now says so too.
            'disputed' => 'warning',
            'cancelled' => 'gray',
            'credited' => 'gray',
            'written_off' => 'gray',
        ],
        'credit_notes.status' => [
            'draft' => 'warning',
            'issued' => 'info',
            'applied' => 'success',
            'void' => 'gray',
        ],
        'payments.status' => [
            'initiated' => 'warning',
            'authorized' => 'warning',
            'captured' => 'success',
            'reconciled' => 'success',
            'settled' => 'success',
            'failed' => 'danger',
            'refunded' => 'danger',
            'bounced' => 'danger',
            'voided' => 'danger',
        ],
        // Two tables, one lifecycle: a recorded movement and a cancelled one.
        'deposit_transactions.status' => ['recorded' => 'success', 'cancelled' => 'gray'],
        'expenses.status' => ['recorded' => 'success', 'cancelled' => 'gray'],
        'payrolls.status' => [
            'draft' => 'warning',
            'approved' => 'success',
            // Grey on the register, red on the employee's payslips tab. A cancelled run is a
            // document that left the books, which is grey everywhere else in the panel.
            'cancelled' => 'gray',
        ],
        'cam_allocations.status' => [
            'pending' => 'warning',
            'billed' => 'success',
            'disputed' => 'danger',
            'closed' => 'gray',
        ],
        'tenant_sales_declarations.status' => [
            'submitted' => 'warning',
            'locked' => 'success',
            'disputed' => 'danger',
        ],

        // ── Leasing and space ────────────────────────────────────────────────────────────
        'leases.status' => [
            'draft' => 'gray',
            'pending_approval' => 'warning',
            // Signed, term not started — the sixth status (2026-09-10). Its own colour rather
            // than sharing `info` with `renewed`: a badge whose whole job is to be told apart at
            // a glance. It got that colour on the register alone, which is how this map came to
            // be needed.
            'future' => 'primary',
            'active' => 'success',
            'expired' => 'gray',
            'renewed' => 'info',
            'terminated' => 'danger',
            'cancelled' => 'danger',
        ],
        // Unlet is the leasing signal: a vacant shop is red, a reserved one amber, an occupied
        // one green, and one out of service is neutral — it is not lost letting.
        'units.status' => [
            'vacant' => 'danger',
            'reserved' => 'warning',
            'occupied' => 'success',
            'maintenance' => 'gray',
        ],
        // The same reading for a bay, kiosk or sign: available is the thing to act on.
        'rentable_items.status' => [
            'available' => 'warning',
            'assigned' => 'success',
            'out_of_service' => 'danger',
        ],

        // ── Parties ──────────────────────────────────────────────────────────────────────
        'tenants.status' => ['active' => 'success', 'inactive' => 'gray', 'blacklisted' => 'danger'],
        'vendors.status' => ['active' => 'success', 'inactive' => 'gray', 'blacklisted' => 'danger'],
        'violations.status' => ['open' => 'warning', 'resolved' => 'success'],

        // ── Service desk and facility ────────────────────────────────────────────────────
        'tenant_requests.status' => [
            'submitted' => 'info',
            'acknowledged' => 'warning',
            'in_progress' => 'primary',
            'awaiting_tenant' => 'warning',
            'resolved' => 'success',
            'closed' => 'gray',
            'cancelled' => 'danger',
        ],
        'tenant_requests.priority' => ['low' => 'gray', 'medium' => 'info', 'high' => 'warning', 'urgent' => 'danger'],
        // Verbatim what `FacilityVocabulary` carried; that class now reads these two entries.
        'facility_work_orders.status' => [
            'open' => 'info',
            'in_progress' => 'warning',
            'done' => 'success',
            'cancelled' => 'gray',
        ],
        'facility_work_orders.priority' => ['low' => 'gray', 'medium' => 'info', 'high' => 'warning', 'urgent' => 'danger'],

        // ── Marketing ────────────────────────────────────────────────────────────────────
        'marketing_posts.status' => [
            'draft' => 'info',
            'pending' => 'warning',
            'published' => 'success',
            'rejected' => 'danger',
            'archived' => 'gray',
        ],
    ];

    /**
     * The colour for one value of one vocabulary.
     *
     * Accepts a backed enum as well as its string, because `ValueSets` declares some sets from an
     * enum and a column cast to one hands the badge the case rather than the value.
     */
    public static function for(string $set, mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        if ($value === null || $value === '') {
            return self::FALLBACK;
        }

        return self::MAP[$set][(string) $value] ?? self::FALLBACK;
    }

    /**
     * The closure a `->color()` takes: `->badge()->color(BadgeColors::of('invoices.status'))`.
     *
     * @return Closure(mixed): string
     */
    public static function of(string $set): Closure
    {
        return fn (mixed $state): string => self::for($set, $state);
    }
}
