<?php

namespace App\Services;

use App\Models\Lease;
use App\Models\PostDatedCheque;
use App\Notifications\ChequeCoverageEndingNotification;
use App\Support\OpsLog;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Which tenants are about to run out of lodged cheques while their lease still has term to run.
 *
 * **Why this needs a sweep of its own.** Egyptian practice is that a tenant lodges a YEAR of
 * post-dated cheques up front, against a lease that usually runs longer. So running dry mid-term
 * is not an edge case, it is the normal shape of the arrangement — and it is invisible, because
 * nothing about it looks wrong on any screen. Every cheque in the register clears on time, the
 * register is green, and one month the money simply stops arriving because there was never an
 * instrument for it. `pdc:scan-maturing` cannot see this: it reports cheques that EXIST and are
 * late. This reports the cheques that do not exist yet.
 *
 * There is no benchmark for it. The Western tools this project measures itself against do not
 * model post-dated cheques at all, so this is judged against Egyptian practice and says so — see
 * the gap analysis §0 on module 33 having no yardstick.
 *
 * **The definition of coverage**, which is the only interesting decision here: a lease is covered
 * to the LATEST `cheque_date` among its cheques that are still awaiting collection (`held` or
 * `deposited` — `PostDatedCheque::AWAITING_STATUSES`, shared with the maturity scopes so the two
 * readings cannot drift).
 *
 * A `cleared` cheque is deliberately EXCLUDED from the coverage horizon even though it is the
 * happy outcome. Coverage is a forward-looking question — *what is still lodged for the months
 * ahead?* — and a cheque that has already been banked answers nothing about them. Including
 * cleared cheques would make a lease look covered by the very instruments that have already been
 * consumed, which is the failure this exists to catch. `bounced` and `cancelled` are excluded for
 * the plainer reason that they are not going to pay anyone.
 *
 * **A lease with no cheques at all is not reported.** That is a tenant who pays by transfer, and
 * alerting on it would fire for most of the portfolio on the first run — an alert that is usually
 * noise is an alert that gets filtered to a folder nobody opens. Only a tenant who HAS lodged
 * cheques, and is running out, is a tenant whose arrangement is about to lapse.
 *
 * Idempotent per (lease, horizon): the notification payload carries the covered-to date, so a
 * fresh batch lodged after the warning is visibly a different alert rather than a repeat.
 */
class ScanChequeCoverageService
{
    /**
     * @return array{scanned:int, ending:int, leases:list<array{lease_id:int, covered_to:string, expiry:string, uncovered_months:int}>}
     */
    public function run(int $withinDays = 60, ?CarbonImmutable $today = null): array
    {
        $today = $today ?? CarbonImmutable::now()->startOfDay();
        $horizon = $today->addDays($withinDays);

        // One grouped query rather than a query per lease: the register is one row per cheque and
        // a portfolio can hold thousands, so the per-lease shape here is a max(), not an N+1.
        $coverage = PostDatedCheque::query()
            ->whereIn('status', PostDatedCheque::AWAITING_STATUSES)
            ->whereNotNull('lease_id')
            ->groupBy('lease_id')
            ->select('lease_id', DB::raw('MAX(cheque_date) as covered_to'))
            ->pluck('covered_to', 'lease_id');

        if ($coverage->isEmpty()) {
            return ['scanned' => 0, 'ending' => 0, 'leases' => []];
        }

        $leases = Lease::query()
            ->whereIn('id', $coverage->keys())
            ->where('status', 'active')
            ->whereNotNull('expiry_date')
            // The property is reached through the unit — `Lease` is `#[PropertyOwned(via: 'unit')]`
            // and carries no `asset_id` of its own. Eager-loaded because the recipient resolver
            // needs it per lease, and this sweep is the whole portfolio.
            ->with(['tenant', 'unit.asset'])
            ->get();

        $ending = [];

        foreach ($leases as $lease) {
            $coveredTo = CarbonImmutable::parse((string) $coverage[$lease->getKey()])->startOfDay();
            $expiry = CarbonImmutable::parse($lease->expiry_date)->startOfDay();

            // Covered past the end of the term: the arrangement is complete, nothing to ask for.
            if ($coveredTo->greaterThanOrEqualTo($expiry)) {
                continue;
            }

            // The last cheque is still further out than the horizon — there is time, and warning
            // now would train the operator to ignore this alert by the time it matters.
            if ($coveredTo->greaterThan($horizon)) {
                continue;
            }

            $ending[] = [
                'lease_id' => (int) $lease->getKey(),
                'covered_to' => $coveredTo->toDateString(),
                'expiry' => $expiry->toDateString(),
                // Whole months of term standing beyond the last lodged cheque — the size of the
                // ask, which is what makes this actionable rather than merely alarming.
                'uncovered_months' => max(1, (int) ceil($coveredTo->diffInMonths($expiry))),
                'lease' => $lease,
            ];
        }

        foreach ($ending as $row) {
            $this->notify($row);
        }

        if ($ending !== []) {
            OpsLog::warning('pdc.coverage_ending', [
                'count' => count($ending),
                'as_of' => $today->toDateString(),
            ]);
        }

        return [
            'scanned' => $leases->count(),
            'ending' => count($ending),
            'leases' => array_map(
                fn (array $r): array => array_diff_key($r, ['lease' => null]),
                $ending,
            ),
        ];
    }

    /**
     * Told to the people who chase it. Scoped to the lease's own property, because a cheque
     * arrangement is a mall's relationship with its tenant and a manager pinned to another mall
     * can do nothing with it.
     */
    private function notify(array $row): void
    {
        $lease = $row['lease'];

        // Through the shared resolver, not a hand-rolled role query: it is the one place that
        // knows property-team roles are unioned with every super_admin, and a second copy of that
        // rule is how one fan-out ends up quietly narrower than the rest.
        $recipients = app(AssetStaffRecipients::class)
            ->for($lease->unit?->asset_id, ['manager', 'accounting']);

        if ($recipients->isEmpty()) {
            return;
        }

        Notification::send($recipients, new ChequeCoverageEndingNotification(
            $lease,
            $row['covered_to'],
            $row['uncovered_months'],
        ));
    }
}
