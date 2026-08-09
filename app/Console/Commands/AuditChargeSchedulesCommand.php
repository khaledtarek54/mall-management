<?php

namespace App\Console\Commands;

use App\Models\Charge;
use App\Models\Lease;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Find every lease whose charge rows the schedule model would now REFUSE (story LS-06).
 *
 * **The hazard this exists for.** Before phase 1 a lease's charges were an unordered bag: two
 * active `base_rent` rows meant the monthly run billed *both*, silently, and someone downstream
 * noticed the rent had doubled. Phase 1 made the schedule authoritative, so
 * `MonthlyBillingService::assertScheduleUnambiguous()` now throws on exactly that shape — which is
 * the right refusal and a much better failure, but it turns a quiet over-bill into a **hard stop on
 * the whole invoice**. A lease carrying that shape bills nothing at all on the first run after
 * deploy.
 *
 * Nothing had ever checked whether such leases exist. That is the gap: "zero behaviour change" is a
 * claim about data nobody had looked at.
 *
 * **Read-only, and it exits non-zero when it finds something**, so it can gate a deploy or a data
 * import rather than being a report someone remembers to read. Run it before go-live, and again
 * after importing the operator's real leases.
 *
 * It reports three shapes, worst first:
 *  - **overlap** — two rows of the same type covering the same day. Billing REFUSES the invoice.
 *  - **gap** — a lease whose rent stops and restarts mid-term. Billing produces no rent line for
 *    the months in between, quietly.
 *  - **undated** — an open-ended row with no `start_date`. Harmless to billing (a null start has
 *    always meant "from the beginning"), but it sorts inconsistently across MySQL and SQLite, so a
 *    rent roll's "next step" can differ by database. The migration stamps these.
 */
class AuditChargeSchedulesCommand extends Command
{
    protected $signature = 'atriom:audit-charge-schedules
        {--lease= : Restrict to one lease id}
        {--all-statuses : Include draft, expired and terminated leases (default: active + pending only)}';

    protected $description = 'Report leases whose charge rows overlap, leave a gap, or carry no start date.';

    public function handle(): int
    {
        $leases = Lease::query()
            ->with(['charges' => fn ($q) => $q->where('is_active', true)->where('frequency', '!=', 'one_time')])
            ->when(! $this->option('all-statuses'), fn ($q) => $q->whereIn('status', ['active', 'pending_approval']))
            ->when($this->option('lease'), fn ($q, $id) => $q->whereKey($id))
            ->get();

        $findings = $leases->flatMap(fn (Lease $lease) => $this->auditLease($lease));

        if ($findings->isEmpty()) {
            $this->info("Checked {$leases->count()} lease(s). Every charge schedule is unambiguous.");

            return self::SUCCESS;
        }

        $this->table(
            ['Lease', 'Charge type', 'Problem', 'Detail'],
            $findings->map(fn (array $f) => [$f['lease'], $f['type'], $f['problem'], $f['detail']])->all(),
        );

        $overlaps = $findings->where('problem', 'overlap')->count();
        $gaps = $findings->where('problem', 'gap')->count();
        $undated = $findings->where('problem', 'undated')->count();

        if ($overlaps > 0) {
            $this->error("{$overlaps} OVERLAP(S): these leases will bill NOTHING on the next monthly run.");
            $this->line('  Close the earlier row the day before the later one starts, then re-run.');
        }
        if ($gaps > 0) {
            $this->warn("{$gaps} gap(s): these leases bill no rent for the months in between.");
        }
        if ($undated > 0) {
            $this->warn("{$undated} undated row(s): harmless to billing; the LS-06 migration stamps them.");
        }

        // Non-zero on any finding, so this can gate a deploy rather than be a report someone reads.
        return self::FAILURE;
    }

    /** @return Collection<int, array{lease: string, type: string, problem: string, detail: string}> */
    private function auditLease(Lease $lease): Collection
    {
        $findings = collect();

        foreach ($lease->charges->groupBy('type') as $type => $rows) {
            /** @var Collection<int, Charge> $rows */
            // Nulls first: an undated row starts at the beginning of time.
            $sorted = $rows
                ->sortBy(fn (Charge $c) => $c->start_date === null ? PHP_INT_MIN : $c->start_date->getTimestamp())
                ->values();

            foreach ($sorted as $charge) {
                if ($charge->start_date === null) {
                    $findings->push([
                        'lease' => $lease->reference,
                        'type' => $type,
                        'problem' => 'undated',
                        'detail' => "charge #{$charge->id} has no start_date",
                    ]);
                }
            }

            for ($i = 0; $i < $sorted->count() - 1; $i++) {
                $earlier = $sorted[$i];
                $later = $sorted[$i + 1];

                // An open-ended earlier row swallows everything after it.
                if ($earlier->end_date === null) {
                    $findings->push([
                        'lease' => $lease->reference,
                        'type' => $type,
                        'problem' => 'overlap',
                        'detail' => "#{$earlier->id} is open-ended and #{$later->id} starts "
                            .($later->start_date?->toDateString() ?? 'undated'),
                    ]);

                    continue;
                }

                if ($later->start_date === null || $earlier->end_date->gte($later->start_date)) {
                    $findings->push([
                        'lease' => $lease->reference,
                        'type' => $type,
                        'problem' => 'overlap',
                        'detail' => "#{$earlier->id} ends {$earlier->end_date->toDateString()}, "
                            ."#{$later->id} starts ".($later->start_date?->toDateString() ?? 'undated'),
                    ]);

                    continue;
                }

                // Contiguous means the next row starts the very next day. A wider jump is a month
                // this lease bills no rent for — which nobody asked for and nobody would notice.
                if ($earlier->end_date->addDay()->lt($later->start_date)) {
                    $findings->push([
                        'lease' => $lease->reference,
                        'type' => $type,
                        'problem' => 'gap',
                        'detail' => "nothing in force between {$earlier->end_date->addDay()->toDateString()} "
                            ."and {$later->start_date->subDay()->toDateString()}",
                    ]);
                }
            }
        }

        return $findings;
    }
}
