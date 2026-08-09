<?php

namespace App\Services\Accounting;

use App\Support\PostingDate;
use App\Support\PostMonth;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Move a document's GL entry into a different month, leaving the document's own date alone
 * (story MF-05).
 *
 * **What it is for.** A February vendor bill that arrives after February closes cannot post — its
 * entry date comes from the document, and that period is sealed. The two remedies before this were
 * both bad: re-date the document, falsifying what the vendor actually invoiced and what the tenant
 * and the ETA payload will show; or leave it unposted and let the books drift from the file.
 * Yardi carries a document date AND a post month on every transaction and runs its reports on the
 * post month (02-yardi-money-flow.md); this is that separation.
 *
 * **The refusal it does NOT relax.** Posting into a CLOSED month is still refused —
 * `PostingDateGuards` is untouched, and this asserts the target period is open before writing.
 * The point is to let a correctly-dated document reach an OPEN month, not to reopen a sealed one.
 *
 * **It re-posts immediately.** Setting the month and leaving the entry where it was would be the
 * worst of both: the screen says March, the trial balance says February. `LedgerPoster::sync()`
 * voids the stale entry and re-posts on the new date through the same path the sweep uses.
 */
class SetPostMonthService
{
    public function __construct(private LedgerPoster $poster) {}

    public function set(Model $source, mixed $month, string $reason): void
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException(__('admin.posting.errors.post_month_reason_required'));
        }

        if (! in_array($source->getMorphClass(), $this->postableSources(), true)) {
            throw new DomainException(__('admin.posting.errors.post_month_not_a_gl_source'));
        }

        $target = CarbonImmutable::parse($month)->startOfMonth();

        // The same guard every operator-typed GL date goes through. A closed target is refused here,
        // where the operator is standing, rather than inside the best-effort sync job that only logs.
        PostingDate::assertOpen($target, 'post_month');

        DB::transaction(function () use ($source, $target, $reason) {
            DB::table('posting_month_overrides')->updateOrInsert(
                ['source_type' => $source->getMorphClass(), 'source_id' => $source->getKey()],
                [
                    'post_month' => $target->toDateString(),
                    'reason' => $reason,
                    'set_by_id' => Auth::id(),
                    'updated_at' => CarbonImmutable::now(),
                    'created_at' => CarbonImmutable::now(),
                ],
            );

            $this->poster->sync($source->refresh());
        });
    }

    /** Put the document back on its own date. */
    public function clear(Model $source): void
    {
        if (! PostMonth::isOverridden($source)) {
            throw new DomainException(__('admin.posting.errors.post_month_not_set'));
        }

        DB::transaction(function () use ($source) {
            DB::table('posting_month_overrides')
                ->where('source_type', $source->getMorphClass())
                ->where('source_id', $source->getKey())
                ->delete();

            $this->poster->sync($source->refresh());
        });
    }

    /**
     * The morph classes that actually post to the GL.
     *
     * Derived from `LedgerPoster::JOURNALIZERS` rather than listed: a post month on a document that
     * posts nothing is a setting with no effect, and the one-registry rule says never re-list.
     *
     * @return array<int, string>
     */
    private function postableSources(): array
    {
        return array_map(
            fn (string $class) => (new $class)->getMorphClass(),
            LedgerPoster::sources(),
        );
    }
}
