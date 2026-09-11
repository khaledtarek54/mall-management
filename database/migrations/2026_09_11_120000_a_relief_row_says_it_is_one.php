<?php

use App\Models\Charge;
use App\Models\LeaseEvent;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A rent relief's own charge rows carry `origin = relief` (2026-09-11).
 *
 * `LeaseReliefService` wrote them `manual` — indistinguishable from a step the operator STATED —
 * so the projection adopted a relief segment standing on an anniversary as the contracted figure
 * and derived the levy from it, and the prune's chain re-link extended a relief row past its own
 * end. Both compounded the ladder from the relieved amount for the rest of the term.
 *
 * Every relief already granted named its rows in its own lease event (`payload.rows_opened[].id`,
 * written since the window shape shipped), which is the only honest key: a `manual` row with both
 * dates set is ALSO what a stated future step or a projection-closed base row looks like, and
 * guessing from shape would re-classify an operator's own figure. The resumed row after a window
 * is deliberately not touched — it continues the contract and stays `manual`.
 *
 * Nothing about billing moves: the origin is read by the schedule's re-true and by nothing that
 * prices a month.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ids = LeaseEvent::query()
            ->where('type', LeaseEvent::TYPE_ABATEMENT)
            ->get(['payload'])
            ->flatMap(fn (LeaseEvent $e) => collect($e->payload['rows_opened'] ?? [])->pluck('id'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return;
        }

        DB::table('charges')
            ->whereIn('id', $ids)
            ->where('origin', Charge::ORIGIN_MANUAL)
            ->update(['origin' => Charge::ORIGIN_RELIEF]);
    }

    public function down(): void
    {
        DB::table('charges')
            ->where('origin', Charge::ORIGIN_RELIEF)
            ->update(['origin' => Charge::ORIGIN_MANUAL]);
    }
};
