<?php

namespace App\Support;

/**
 * What a TENANT may see of their own documents — the one place that answers it.
 *
 * A **draft is not a document**. It is the operator's working copy: unissued, uncommitted, and
 * as far as the counterparty is concerned it does not exist yet. Yardi, MRI and Entrata all
 * withhold an unposted charge from the tenant surface for the same reason — showing one asserts
 * a debt that has not been raised, and the tenant can neither pay it nor dispute it.
 *
 * **Why a registry and not a `whereIn` per controller.** `invoices.status` and
 * `credit_notes.status` both DEFAULT to `'draft'` at the column, so any create that doesn't set
 * the status explicitly produces one — `CreditUnearnedBillingService` does exactly that. The leak
 * was live on seven surfaces at once (list, show, PDF, statement, two payment initiations and the
 * portal table), which is precisely the shape that a scattered filter produces and a single seam
 * prevents.
 *
 * The visible set is **derived**, never listed: it is the column's own value set minus [HIDDEN].
 * A status added to `ValueSets` is therefore visible by default and has to be hidden deliberately
 * — the safe direction for a *reader*, and the opposite of what a hand-kept allowlist does, which
 * is to silently drop a new status out of the tenant's history.
 */
final class TenantVisibility
{
    /**
     * Statuses a tenant may never see, per table.
     *
     * Only `draft` — deliberately. Everything else was issued at some point and is part of the
     * tenant's own history: a `cancelled` or `written_off` invoice still explains a number they
     * remember, and hiding it would make the statement unreconcilable against their own records.
     * Withholding is for documents that never existed to them, not for ones that ended badly.
     */
    public const HIDDEN = [
        'invoices' => ['draft'],
        'credit_notes' => ['draft'],
        // A lease the tenant can see is one that was actually put to them. `draft` is terms still
        // being written — the retailer reading their own rent, term and deposit off a negotiation
        // and reasonably treating it as settled.
        //
        // `pending_approval` is deliberately NOT hidden, and that is the interesting half. It reads
        // like "not agreed yet", but twelve places treat it as a LIVE tenancy: it may be terminated,
        // granted rent relief, extended, re-priced, space-changed, take a CAM estimate, hold a
        // parking bay (and mark it off-market), it makes the unit `reserved`, and it counts as
        // committed revenue. Hiding it would leave a retailer holding a bay under a lease they
        // cannot see. Nobody grants rent relief on terms nobody agreed — so whatever the name
        // suggests, the system already treats it as real.
        'leases' => ['draft'],
    ];

    /** Statuses a tenant may never see for this table. Empty when the table isn't registered. */
    public static function hiddenFor(string $table): array
    {
        return self::HIDDEN[$table] ?? [];
    }

    /**
     * The statuses a tenant MAY see — the value set minus [HIDDEN].
     *
     * Null when the column has no registered value set, which callers read as "no restriction to
     * apply" rather than "nothing is visible".
     */
    public static function visibleFor(string $table, string $column = 'status'): ?array
    {
        $all = ValueSets::allowed($table, $column);

        if ($all === null) {
            return null;
        }

        return array_values(array_diff($all, self::hiddenFor($table)));
    }
}
