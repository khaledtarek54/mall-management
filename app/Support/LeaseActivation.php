<?php

namespace App\Support;

use App\Models\Lease;
use App\Models\PostDatedCheque;

/**
 * Whether a lease may be ACTIVATED — the one predicate the Activate button, the service and the
 * reservation sweep all read.
 *
 * Client meeting 2026-09-02, points 1 and 2: *"activate the lease only when accounting confirms the
 * money is in — the deposit invoice paid or the cheques obtained; the reservation is valid for X
 * days."* Until 2026-09-11 activation was a DROPDOWN — anyone holding `leases.edit` picked `active`
 * on the form — and no reservation ever lapsed.
 *
 * **The standard.** Approval before a lease goes live is market-wide (Voyager's lease approval
 * workflow, MRI's lease authorisation, Entrata's lease approval), so activation is an ACT with its
 * own right (`leases.activate`), and Yardi's entering-vs-executing split puts it with accounting.
 * A MONEY gate is Yardi's residential "no move-in with a balance" control, set per property — not
 * Voyager Commercial's default — so it ships as a per-property setting whose default is `none`
 * (Yardi's), and the client's rule is what they set. A hold that lapses is Yardi's unit-hold expiry.
 *
 * **Two questions, one class.** *Is this lease awaiting activation* ({@see isAwaiting()}) and *what
 * is still missing* ({@see shortfall()}). The button shows the second as a sentence; the service
 * refuses on it under a lock; the sweep uses it to decide whether a lapsed reservation is still
 * unpaid. Read here or nowhere.
 */
final class LeaseActivation
{
    /** Entry executes the lease — Voyager Commercial's default, and the shipped one. */
    public const NONE = 'none';

    /** The security deposit must be HELD (`Lease::depositHeld()` ≥ the agreed figure). */
    public const DEPOSIT = 'deposit_received';

    /** …or post-dated cheques lodged on the lease worth at least the deposit. */
    public const DEPOSIT_OR_CHEQUES = 'deposit_or_cheques';

    public const REQUIREMENTS = [self::NONE, self::DEPOSIT, self::DEPOSIT_OR_CHEQUES];

    /**
     * The status an Activate act moves out of — entered, signed, not yet executed.
     *
     * `pending_approval` ONLY. A `draft` is terms still being written (`TenantVisibility` hides it
     * from the tenant on exactly that reading), so an act that executed it the moment a receipt
     * landed would put a half-written deal live, and a sweep that cancelled it into an immutable
     * terminal state would destroy the record a leasing agent was negotiating on. Leasing promotes
     * draft → awaiting activation when the deal is signed; that is what the status has always been
     * for. Yardi's unit-hold expiry frees the UNIT and does not destroy the lease either.
     */
    public const AWAITING = ['pending_approval'];

    public static function requirementFor(?int $assetId): string
    {
        $value = (string) PropertySettings::get('billing.lease_activation_requires', $assetId);

        return in_array($value, self::REQUIREMENTS, true) ? $value : self::NONE;
    }

    public static function reservationDaysFor(?int $assetId): int
    {
        return max(0, (int) PropertySettings::get('billing.reservation_valid_days', $assetId));
    }

    /**
     * Whether ENTRY may execute a lease on this property — i.e. the form may still offer `active`
     * on a new lease and the wizard creates one active. False the moment money is required: then
     * the Activate act is the only door, which is the whole point of the setting.
     */
    public static function entryExecutes(?int $assetId): bool
    {
        return self::requirementFor($assetId) === self::NONE;
    }

    public static function isAwaiting(Lease $lease): bool
    {
        return in_array($lease->status, self::AWAITING, true);
    }

    /**
     * What is still missing before this lease may be activated — null when nothing is.
     *
     * `$forUpdate` reads the deposit pot under a lock (the service's question); the button reads
     * the display twin. The cheque sum is a plain read either way: the window is a single SUM
     * inside a transaction that already holds the lease row, and the only writers of a cheque's
     * status (clear, bounce, cancel) each lock the cheque and its invoice, not the lease — so a
     * concurrent bounce can, in that instant, leave a cheque counted that is no longer security.
     * Recorded rather than locked: taking every cheque row FOR UPDATE here would put the deposit
     * register behind a lease lock nothing else takes in that order.
     *
     * @return array{requirement: string, required: float, held: float, cheques: float}|null
     */
    public static function shortfall(Lease $lease, bool $forUpdate = false): ?array
    {
        $requirement = self::requirementFor($lease->unit?->asset_id);

        if ($requirement === self::NONE) {
            return null;
        }

        $required = round((float) $lease->security_deposit, 2);
        $held = round($forUpdate ? $lease->depositHeldForUpdate() : $lease->depositHeld(), 2);

        // Only cheques STILL IN HAND count as security: a cleared cheque has become a payment (and
        // is in `depositHeld()` if it settled the deposit invoice), a bounced or cancelled one is
        // nothing.
        $cheques = $requirement === self::DEPOSIT_OR_CHEQUES
            ? round((float) PostDatedCheque::query()
                ->where('lease_id', $lease->id)
                ->whereIn('status', [PostDatedCheque::STATUS_HELD, PostDatedCheque::STATUS_DEPOSITED])
                ->sum('amount'), 2)
            : 0.0;

        $satisfied = $held >= $required
            || ($requirement === self::DEPOSIT_OR_CHEQUES && $cheques >= $required);

        return $satisfied ? null : [
            'requirement' => $requirement,
            'required' => $required,
            'held' => $held,
            'cheques' => $cheques,
        ];
    }

    /**
     * The refusal's key and tokens — what is required, what has arrived, and the way out. Returned
     * as a pair rather than a sentence so the service can raise it as `__($key, $tokens)` inside
     * the throw, which is the shape the translated-refusals gate reads.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    public static function refusal(array $shortfall): array
    {
        $key = $shortfall['requirement'] === self::DEPOSIT_OR_CHEQUES
            ? 'admin.refusals.lease_activation_needs_deposit_or_cheques'
            : 'admin.refusals.lease_activation_needs_deposit';

        return [$key, [
            'required' => number_format($shortfall['required'], 2),
            'held' => number_format($shortfall['held'], 2),
            'cheques' => number_format($shortfall['cheques'], 2),
        ]];
    }

    /** The same refusal as a sentence, for the button's tooltip and modal. */
    public static function explain(array $shortfall): string
    {
        [$key, $tokens] = self::refusal($shortfall);

        return __($key, $tokens);
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::REQUIREMENTS)->mapWithKeys(fn (string $r) => [$r => __('admin.lease_activation.'.$r)])->all();
    }
}
