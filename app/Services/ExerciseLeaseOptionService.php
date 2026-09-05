<?php

namespace App\Services;

use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\LeaseOption;
use App\Support\LeaseEventNarrative;
use App\Support\Translate;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Resolve a lease option — and make exercising it mean something (story OP-04).
 *
 * **What was wrong.** Exercising stamped `status = exercised` and stopped there. The option already
 * knew the contracted rent — `LeaseOption::projectedRent()` computes it from the basis, whether that
 * is a fixed figure or last-rent-plus-uplift — and the renewal form then asked the operator to type
 * a rent from scratch. So the system held the number the contract specifies and threw it away at the
 * moment it mattered, which is the same defect this whole cycle set out to remove.
 *
 * A five-year renewal at a contracted +10% typed as the old rent is a mis-priced tenancy that
 * surfaces at the next reconciliation, if at all.
 *
 * **Every resolution is a lease event now** (LE-01), typed to what actually happened rather than to
 * the mechanism: exercising a renewal EXTENDS the lease, an expansion option EXPANDS it, a
 * termination option TERMINATES it. `market` and `cpi` bases produce no rent — a valuation and an
 * index feed are numbers this system may not invent — so those exercise fine and simply pre-fill
 * nothing, exactly as the escalation sweep refuses to invent a CPI step.
 */
class ExerciseLeaseOptionService
{
    /**
     * What exercising each kind of option DOES to the lease, in the vocabulary of its history.
     *
     * `rofr`/`rofo` are deliberately absent: a right of first refusal that is taken up produces a
     * new letting or an expansion, which is recorded when that deal is struck — not here.
     *
     * `extension` is absent too, and that is the correction rather than the omission. It was handled
     * here and queried for below while never being one of `LeaseOption::TYPES`, so no picker offered
     * it and no `admin.lease_options.types.*` label existed for it — dead code a direct write was the
     * only way to reach. The right fix is removal, not widening the type list: an OPTION is an
     * unexercised RIGHT and `renewal` already IS the right to extend, so a second code for it would
     * split option reporting across two values meaning one thing. `extension` remains a LEASE EVENT
     * ({@see LeaseEvent::TYPE_EXTENSION}) — the thing that HAPPENED — which is what this map produces.
     */
    private const EVENT_FOR = [
        'renewal' => LeaseEvent::TYPE_EXTENSION,
        'expansion' => LeaseEvent::TYPE_EXPANSION,
        'contraction' => LeaseEvent::TYPE_CONTRACTION,
        'termination' => LeaseEvent::TYPE_TERMINATION,
    ];

    /**
     * @param  array{notice_given_at?:string|\DateTimeInterface|null, reason?:string|null, document_reference?:string|null}  $data
     */
    public function exercise(LeaseOption $option, array $data = []): LeaseOption
    {
        // Translated, and the STATUS reads as a word: this is the app talking to a person, so
        // printing `'lapsed'` gives them half a business rule and half a database column.
        if (! $option->isOpen()) {
            throw new DomainException(__('admin.refusals.lease_option_not_open', [
                'status' => Translate::orHumanized("admin.lease_options.statuses.{$option->status}", $option->status),
            ]));
        }

        $lease = $option->lease;

        if ($lease === null) {
            throw new InvalidArgumentException('This option is not attached to a lease.');
        }

        $today = CarbonImmutable::now()->startOfDay();

        // The date NOTICE WAS SERVED, which is what the window is judged against — not the day
        // somebody got round to recording it. A notice served inside the window and entered a week
        // late is valid, and refusing it here would push the operator to falsify the date.
        $noticeGiven = isset($data['notice_given_at']) && $data['notice_given_at']
            ? CarbonImmutable::parse($data['notice_given_at'])->startOfDay()
            : ($option->notice_given_at ? CarbonImmutable::instance($option->notice_given_at) : $today);

        // ── THE NOTICE WINDOW IS A CONTRACTUAL TERM, AND NOTHING WAS CHECKING IT ────────────────
        //
        // `windowIsOpen()` has been on the model since options shipped and NO caller ever asked it
        // — built, correct and unreachable, the shape this repo names for services that run and
        // bill nobody.
        //
        // Measured: a break option whose window opens on 30/12/2026 was exercised on 30/08/2026,
        // four months early, and the system recorded a termination and priced its 250,000 penalty.
        // The tenant's answer is that their notice was served outside the window the lease grants
        // and is therefore void — so the mall has a termination on its books that the contract does
        // not support. The window CLOSING was refused only by accident: `status === 'lapsed'` is
        // set by a scheduled sweep, so an option the sweep has not yet reached passed both ways.
        //
        // Judged on the date NOTICE WAS SERVED, exactly as `$noticeGiven` is derived above: a
        // notice served inside the window and keyed a week late is valid, and refusing it on
        // today's date would push the operator to falsify the date.
        // A DomainException, not an InvalidArgumentException: this is the operator doing something
        // the lease does not permit, not a developer error. `bootstrap/app.php` renders the first
        // as its own message and the second as a 500 page — and the sweep that requires every
        // refusal to be translated deliberately reads only the first.
        if (! $option->windowIsOpen($noticeGiven)) {
            throw new DomainException(__('admin.errors.option_notice_outside_window', [
                'served' => $noticeGiven->format('d/m/Y'),
                'from' => $option->earliest_notice_date?->format('d/m/Y') ?? '—',
                'to' => $option->latest_notice_date?->format('d/m/Y') ?? '—',
            ]));
        }

        return DB::transaction(function () use ($option, $lease, $noticeGiven, $today, $data): LeaseOption {
            $projectedRent = $option->projectedRent((float) $lease->base_rent_monthly);

            $option->forceFill([
                'status' => 'exercised',
                'resolved_at' => $today->toDateString(),
                'notice_given_at' => $noticeGiven->toDateString(),
            ])->save();

            $type = self::EVENT_FOR[$option->type] ?? null;

            if ($type !== null) {
                app(RecordLeaseEventService::class)->record(
                    $lease,
                    $type,
                    // Effective when the option BITES, which for a renewal is the day after the
                    // current term ends — not the day notice was served.
                    $this->effectiveFrom($option, $lease),
                    // A SENTENCE THE OPERATOR TYPED, or NOTHING — never a sentence we compose.
                    //
                    // This translated at WRITE time and stored the result, so an option exercised
                    // while the panel was in English left an English row that no later reader could
                    // ever see in Arabic. The rule this repo states for the activity log and the
                    // journal applies here identically: a row stores DATA, and prose is resolved on
                    // READ. Everything the sentence said — the type, the notice date, the rent
                    // before and after — is already in the payload below, so the reader composes it
                    // and one wording fix reaches every row ever written.
                    $data['reason'] ?? null,
                    array_filter([
                        LeaseEventNarrative::KEY => 'option_exercised',
                        'option_id' => $option->id,
                        'option_type' => $option->type,
                        'rent_basis' => $option->rent_basis,
                        'term_months' => $option->term_months,
                        'notice_given_at' => $noticeGiven->toDateString(),
                        'amount_from' => (float) $lease->base_rent_monthly,
                        'amount_to' => $projectedRent,
                        // Stated when the basis cannot produce a number, so the history says the
                        // rent is still to be agreed rather than silently omitting it.
                        'rent_to_be_agreed' => $projectedRent === null,
                    ], fn ($v) => $v !== null),
                    $data['document_reference'] ?? null,
                );
            }

            return $option->fresh();
        });
    }

    /** Waive or lapse — recorded on the option, not in the lease's history: nothing changed. */
    public function resolveWithout(LeaseOption $option, string $status): LeaseOption
    {
        if (! in_array($status, ['waived', 'lapsed'], true)) {
            throw new InvalidArgumentException("'{$status}' is not a way to resolve an option without exercising it.");
        }

        if (! $option->isOpen()) {
            throw new InvalidArgumentException("This option is already '{$option->status}'.");
        }

        $option->forceFill([
            'status' => $status,
            'resolved_at' => CarbonImmutable::now()->toDateString(),
        ])->save();

        return $option->fresh();
    }

    /**
     * The terms an exercised option hands to the renewal form (story OP-04).
     *
     * Null rent means the basis is `market` or `cpi` — the operator types a figure, because a
     * valuation and an index are not numbers this system may invent.
     *
     * @return array{option: LeaseOption, term_months: ?int, rent: ?float, commencement: CarbonImmutable}|null
     */
    public function pendingRenewalTerms(Lease $lease): ?array
    {
        $option = $lease->options()
            ->where('status', 'exercised')
            ->where('type', 'renewal')
            ->orderByDesc('resolved_at')
            ->first();

        if ($option === null) {
            return null;
        }

        return [
            'option' => $option,
            'term_months' => $option->term_months,
            'rent' => $option->projectedRent((float) $lease->base_rent_monthly),
            'commencement' => $this->effectiveFrom($option, $lease),
        ];
    }

    private function effectiveFrom(LeaseOption $option, Lease $lease): CarbonImmutable
    {
        return filled($lease->expiry_date)
            ? CarbonImmutable::instance($lease->expiry_date)->addDay()
            : CarbonImmutable::now()->startOfDay();
    }
}
