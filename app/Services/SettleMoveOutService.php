<?php

namespace App\Services;

use App\Models\DepositTransaction;
use App\Models\Lease;
use App\Models\LeaseEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Dispose of a departing tenant's deposit in one act, and freeze the final account (story MF-03).
 *
 * **What was wrong.** Refund and forfeit were two unrelated manual `DepositTransaction` entries.
 * Nothing netted them, nothing itemised the deductions, nothing checked either against the balance
 * actually held, and nothing recorded what the operator and the tenant had agreed. A move-out was a
 * sequence of unconnected acts that happened to add up if everyone was careful.
 *
 * **The statement is persisted as the termination event's payload.** That is deliberate rather than
 * a new table: `LeaseEvent` is already append-only, dated, attributed and immutable, which is
 * exactly what a settled account needs. Re-deriving the statement a year later would show today's
 * numbers, not the ones that were signed; the event shows the ones that were signed.
 *
 * **It disposes of the deposit only.** Netting open AR off a deposit is a fourth channel into
 * `Invoice::recomputeTotals()` — see `MoveOutStatementService` for why that is its own piece of
 * work. Deductions here are what the landlord KEEPS (damages, cleaning, unamortised fit-out), which
 * `forfeit` already models correctly: Dr Deposits Held / Cr Misc Income.
 */
class SettleMoveOutService
{
    public function __construct(private MoveOutStatementService $statements) {}

    /**
     * @param  array{settlement_date?:string|\DateTimeInterface|null, deductions?:array<int, array<string, mixed>>, reason?:string|null, document_reference?:string|null, method?:string|null}  $data
     * @return array{statement: array<string, mixed>, forfeit: ?DepositTransaction, refund: ?DepositTransaction, event: LeaseEvent}
     */
    public function settle(Lease $lease, array $data = []): array
    {
        if ($lease->status !== 'terminated' && $lease->status !== 'expired') {
            throw new InvalidArgumentException(
                "Lease #{$lease->id} is '{$lease->status}'; a final account settles a tenancy that has ended."
            );
        }

        $settlementDate = isset($data['settlement_date']) && $data['settlement_date']
            ? CarbonImmutable::parse($data['settlement_date'])->startOfDay()
            : CarbonImmutable::now()->startOfDay();

        $deductions = collect($data['deductions'] ?? [])
            ->map(fn (array $row) => [
                'description' => trim((string) ($row['description'] ?? '')),
                'amount' => round((float) ($row['amount'] ?? 0), 2),
            ])
            ->filter(fn (array $row) => $row['amount'] > 0 && $row['description'] !== '')
            ->values();

        $statement = $this->statements->for($lease, $settlementDate);
        $held = (float) $statement['deposit_held'];
        $deducted = round((float) $deductions->sum('amount'), 2);

        if ($deducted > $held) {
            throw new InvalidArgumentException(
                'The deductions exceed the deposit held — a deposit cannot fund more than it holds. Bill the excess instead.'
            );
        }

        if ($held <= 0 && $deducted <= 0) {
            throw new InvalidArgumentException('There is no deposit held on this lease to settle.');
        }

        $refundable = round($held - $deducted, 2);

        return DB::transaction(function () use ($lease, $statement, $deductions, $deducted, $refundable, $settlementDate, $data, $held) {
            $forfeit = null;
            $refund = null;

            // What the landlord KEEPS, as one forfeit carrying the itemisation in its notes. One
            // row rather than one per deduction: the GL entry is a single Dr Deposits Held /
            // Cr Misc Income either way, and the itemisation belongs on the document the tenant
            // signed — which is the lease event below.
            if ($deducted > 0) {
                $forfeit = DepositTransaction::create([
                    'lease_id' => $lease->id,
                    'tenant_id' => $lease->tenant_id,
                    'asset_id' => $lease->unit?->asset_id,
                    'type' => 'forfeit',
                    'amount' => $deducted,
                    'transaction_date' => $settlementDate,
                    'status' => 'recorded',
                    'notes' => __('admin.move_out.forfeit_notes', [
                        'items' => $deductions->map(fn (array $d) => $d['description'].' '.number_format($d['amount'], 2))->join('; '),
                    ]),
                    'created_by_user_id' => auth()->id(),
                ]);
            }

            if ($refundable > 0) {
                $refund = DepositTransaction::create([
                    'lease_id' => $lease->id,
                    'tenant_id' => $lease->tenant_id,
                    'asset_id' => $lease->unit?->asset_id,
                    'type' => 'refund',
                    'amount' => $refundable,
                    'transaction_date' => $settlementDate,
                    'method' => $data['method'] ?? null,
                    'status' => 'recorded',
                    'notes' => __('admin.move_out.refund_notes', ['date' => $settlementDate->format('d/m/Y')]),
                    'created_by_user_id' => auth()->id(),
                ]);
            }

            // Freeze the account as it stood. The payload is the statement — immutable, dated and
            // attributed, because LeaseEvent refuses updates and deletes.
            $event = app(RecordLeaseEventService::class)->record(
                $lease,
                LeaseEvent::TYPE_TERMINATION,
                $settlementDate,
                $data['reason'] ?? __('admin.move_out.default_reason'),
                [
                    'settlement' => true,
                    'deposit_contractual' => (float) $statement['contractual_deposit'],
                    'deposit_held' => $held,
                    'deductions' => $deductions->all(),
                    'deducted_total' => $deducted,
                    'refunded' => max($refundable, 0),
                    'open_ar' => (float) $statement['open_ar'],
                    'tenant_credit' => (float) $statement['tenant_credit'],
                    'net_to_tenant' => (float) $statement['net_to_tenant'],
                    'residual_debt' => (float) $statement['residual_debt'],
                    // The numbers that were NOT knowable on the day. A statement that omits these
                    // reads as final when it is not.
                    'pending_trueups' => collect($statement['pending_trueups'])->pluck('detail')->all(),
                ],
                $data['document_reference'] ?? null,
            );

            return [
                'statement' => $statement,
                'forfeit' => $forfeit,
                'refund' => $refund,
                'event' => $event,
            ];
        });
    }
}
