<?php

namespace App\Services;

use App\Models\Lease;
use App\Models\LeaseEvent;
use App\Models\LeaseOption;
use Carbon\CarbonImmutable;
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
     */
    private const EVENT_FOR = [
        'renewal' => LeaseEvent::TYPE_EXTENSION,
        'extension' => LeaseEvent::TYPE_EXTENSION,
        'expansion' => LeaseEvent::TYPE_EXPANSION,
        'contraction' => LeaseEvent::TYPE_CONTRACTION,
        'termination' => LeaseEvent::TYPE_TERMINATION,
    ];

    /**
     * @param  array{notice_given_at?:string|\DateTimeInterface|null, reason?:string|null, document_reference?:string|null}  $data
     */
    public function exercise(LeaseOption $option, array $data = []): LeaseOption
    {
        if (! $option->isOpen()) {
            throw new InvalidArgumentException(
                "This option is already '{$option->status}' — only an open option can be exercised."
            );
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
                    $data['reason'] ?? __('admin.lease_options.exercised_reason', [
                        'type' => __("admin.lease_options.types.{$option->type}"),
                        'date' => $noticeGiven->format('d/m/Y'),
                    ]),
                    array_filter([
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
            ->whereIn('type', ['renewal', 'extension'])
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
