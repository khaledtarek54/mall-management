<?php

namespace App\Support;

use App\Models\Lease;
use App\Models\Unit;

/**
 * Stored columns whose correct value depends on TODAY, and the sweep that keeps each one honest.
 *
 * **Why this registry exists (pre-staging QA, F-04 / F-05).** Two columns in this system are
 * projections: `units.status` is derived from the leases currently holding the unit, and
 * `leases.status` should stop being `active` once the term has run out. Both are stored, because
 * every list, filter, map and occupancy figure reads them; and both are functions of the date.
 *
 * A stored value that is a function of the date goes wrong **on a day when nothing happened**. That
 * is the whole failure mode, and it is invisible to every other kind of check:
 *
 *  - `Unit::recomputeStatus()` is correctly date-aware — a future-dated give-back reads `reserved`,
 *    a past-dated one reads `vacant`. But it only ever ran from a lease observer event, the unit
 *    pages, or `LeaseSpaceChangeService`. **Nothing ran on a schedule.** A give-back effective
 *    1 January, recorded in August, left `units.status = 'occupied'` on 1 January and every day
 *    after, until something unrelated touched that lease.
 *  - Nothing moved a lease from `active` to `expired` at all. Measured on a lease that expired
 *    2026-01-31, seven months later: still `active`, its unit still `occupied`, the shop
 *    un-relettable because `LeaseCreationService` refused with *"this unit already has an active
 *    lease"*, and `RentEscalationService` still stepping its rent.
 *
 * Neither was a calculation error. Both were a stored answer that had simply stopped being true.
 *
 * **What the gate checks**, in `ProjectedStateConformanceTest`: that every entry's projector still
 * exists, that its sweep is a registered artisan command, that the sweep is actually **scheduled**
 * (an unscheduled sweep is the F-04 state exactly — the code existed for `recomputeStatus()` all
 * along), and that running it twice changes nothing.
 *
 * **Adding a third projection is a deliberate act.** There is no way to discover "a stored column
 * derived from dates" automatically — it is a property of intent, not of schema — so this is a
 * classification registry like `PropertyIsolation` and `DeletionPolicy`, and the same rule applies:
 * write the entry, or explain in `NOT_PROJECTED` why the column looks like one and is not.
 */
final class ProjectedState
{
    /**
     * @var array<string, array{
     *     model: class-string,
     *     column: string,
     *     projector: string,
     *     sweep: string,
     *     stale_when: string,
     * }>
     */
    public const PROJECTIONS = [
        'unit.occupancy' => [
            'model' => Unit::class,
            'column' => 'status',
            // Idempotent and date-aware; `maintenance` is a manual override it deliberately never
            // overwrites, which is why the sweep re-asserts nothing there either.
            'projector' => 'recomputeStatus',
            'sweep' => 'leases:expire',
            'stale_when' => 'a future-dated expansion or give-back reaches its effective date, or a '.
                'lease term ends — none of which is a write, so nothing fires an observer',
        ],

        'lease.term' => [
            'model' => Lease::class,
            'column' => 'status',
            // `expired` is reached by the sweep, not by an operator: typing it would skip the acts
            // that a real ending performs (settle the deposit, credit unearned billing, close the
            // schedule), which is why `terminated` and `renewed` are not offered on the form either.
            'projector' => 'hasExpiredTerm',
            'sweep' => 'leases:expire',
            'stale_when' => 'the expiry date passes with nobody renewing, terminating or holding over',
        ],
    ];

    /**
     * Columns that look like projections and are deliberately not swept, each with its reason.
     *
     * The registry is only worth having if the exceptions are written down: an unexplained absence
     * and a considered one look identical from the outside.
     *
     * @var array<string, string>
     */
    public const NOT_PROJECTED = [
        'invoices.status' => 'Derived from the four settlement channels by `Invoice::recomputeTotals()`, '.
            'which every channel calls. `overdue` is the one date-sensitive value and the daily '.
            '`billing:scan-overdue-invoices` already re-reads it.',

        'vendor_contracts.status' => 'Swept by `vendors:expire-contracts`, which predates this registry '.
            'and is the pattern `leases:expire` was modelled on.',

        'marketing_posts.status' => 'Swept by `marketing:expire-posts` on the display window.',

        'lease_options.status' => 'A lapsed option is REPORTED by `leases:scan-option-windows` and '.
            'resolved by a person. Auto-lapsing a contractual right on a date would resolve a '.
            'negotiation the system is not party to.',
    ];

    /** @return array<int, string> the distinct sweeps this registry depends on */
    public static function sweeps(): array
    {
        return array_values(array_unique(array_column(self::PROJECTIONS, 'sweep')));
    }
}
