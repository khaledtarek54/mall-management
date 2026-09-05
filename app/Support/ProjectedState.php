<?php

namespace App\Support;

use App\Models\Lease;
use App\Models\RentableItem;
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
     *     declarable: array<int, string>,
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
            // `occupied` and `reserved` are the PROJECTION's answers and were offered on the unit
            // form anyway, so an operator could pick one, be told "Saved", and watch `afterSave()`
            // put it straight back — reported by the tester exactly that way. Only these two are a
            // person's statement: `vacant` means "in service, follow the leases", `maintenance` is
            // the override the projector honours.
            'declarable' => ['vacant', 'maintenance'],
        ],

        'rentable_item.occupancy' => [
            'model' => RentableItem::class,
            'column' => 'status',
            // Asks whether a LIVE agreement holds the item open-endedly, which is the meaning
            // `status` has always carried (a bay released effective 30 June is available to re-let
            // from the moment the release is recorded). `out_of_service` is a manual override the
            // projector deliberately never overwrites.
            'projector' => 'recomputeStatus',
            'sweep' => 'leases:expire',
            'stale_when' => 'the lease holding the item reaches its expiry date — nothing closes the '.
                'holding row and a lease expiring is not a write, so the item kept reading `assigned` '.
                'and the register under-reported what was free to let',
            // Same shape as the unit above, found by grepping for it rather than waiting for it to
            // be reported: `assigned` is the projector's answer and the form offered it.
            'declarable' => ['available', 'out_of_service'],
        ],

        'lease.term' => [
            'model' => Lease::class,
            'column' => 'status',
            // `expired` is reached by the sweep, not by an operator: typing it would skip the acts
            // that a real ending performs (settle the deposit, credit unearned billing, close the
            // schedule), which is why `terminated` and `renewed` are not offered on the form either.
            //
            // REVERSIBLE, and it is the only one of the three that needed saying (2026-09-01). The
            // projected value `expired` is ALSO a member of `Lease::TERMINAL_STATUSES` — a machine's
            // guess about today, written into a column whose other values are decisions that closed
            // the record — so every downstream predicate read the guess as a decision. Its two
            // siblings here each carve out a human's statement (`maintenance`, `out_of_service`) and
            // this one had none: the sweep's candidate set is exactly the holdover-conversion
            // candidate set, so at 05:15 it made the whole LE-04 workflow unreachable, permanently.
            // `Lease::isResumingFromExpiry()` is that carve-out, recognised by the SHAPE of the
            // write rather than by trusting a caller.
            'projector' => 'hasExpiredTerm',
            'sweep' => 'leases:expire',
            'stale_when' => 'the expiry date passes with nobody renewing, terminating or holding over',
            // The lease form already got this right — it never offered `expired`, `terminated` or
            // `renewed`, for the reason in the comment above. Recorded here so the registry states
            // the same fact for all three projections rather than leaving one implicit.
            'declarable' => ['draft', 'pending_approval', 'active'],
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

        'work_permits.status' => 'The one that looks most like a projection and most deliberately is '.
            'not. An issued permit whose window has passed is EXPIRED by the clock, and a sweep '.
            'flipping it to `expired` would quietly close the audit question the register exists to '.
            'ask: nobody recorded that the work stopped and the area was made safe. '.
            '`facility:scan-open-permits` reports it hourly and writes nothing; `WorkPermit::hasLapsed()` '.
            'derives it for the badge and the filter.',

        'lease_options.status' => 'A lapsed option is REPORTED by `leases:scan-option-windows` and '.
            'resolved by a person. Auto-lapsing a contractual right on a date would resolve a '.
            'negotiation the system is not party to.',
    ];

    /**
     * The values an OPERATOR may state on this column, as opposed to the ones the projector writes.
     *
     * A form offering a projected value is the defect the tester found on units: pick `Occupied`,
     * press Save, read "Saved", and the projection puts it back to `Vacant` on the same request.
     * That silent discard is worse than refusing the choice, because nothing on the screen says the
     * entry was thrown away. Narrow the options to this list instead.
     *
     * **The record's CURRENT value is not added here** and callers must handle it themselves:
     * Filament derives a Select's `Rule::in` from the options it resolved, so a unit already
     * `occupied` whose form offers only the declarable pair cannot be labelled and every save of it
     * is refused on a field nobody touched — the catalogue-lockout trap this codebase has already
     * shipped once. Render the field DISABLED on a projected value: it then shows the truth, is not
     * dehydrated, and leaves the column to the projector.
     *
     * @return array<int, string>
     */
    public static function declarable(string $model, string $column = 'status'): array
    {
        foreach (self::PROJECTIONS as $projection) {
            if ($projection['model'] === $model && $projection['column'] === $column) {
                return $projection['declarable'];
            }
        }

        return [];
    }

    /** Is this stored value one the PROJECTOR owns rather than one a person stated? */
    public static function isProjected(string $model, ?string $value, string $column = 'status'): bool
    {
        return filled($value) && ! in_array($value, self::declarable($model, $column), true);
    }

    /** @return array<int, string> the distinct sweeps this registry depends on */
    public static function sweeps(): array
    {
        return array_values(array_unique(array_column(self::PROJECTIONS, 'sweep')));
    }
}
