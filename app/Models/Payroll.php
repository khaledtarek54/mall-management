<?php

namespace App\Models;

use App\Models\Concerns\AllocatesDocumentNumber;
use App\Models\Concerns\HasSearchText;
use App\Models\Concerns\RecordsBankAccount;
use App\Models\Concerns\RefusesDeletionOfCommittedRecords;
use App\Services\PayrollService;
use App\Support\ActivityLogging;
use App\Support\Attributes\NeverDeletable;
use App\Support\Attributes\PostingDateGuardedBy;
use App\Support\Attributes\PropertyOwned;
use App\Support\DocumentNumbering;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * مسير رواتب — a monthly payroll run. net_paid is DERIVED = gross − salary tax −
 * social insurance, enforced on every write path (so no path persists an
 * inconsistent net or a zero the journalizer would mis-handle).
 */
#[NeverDeletable(correction: 'cancel the run — payslips and their GL entries follow it')]
// A NULLABLE asset_id, and a null is portfolio-level overhead every property must still see
// — an operator-wide bill is not hidden because someone picked a mall. Declared, not implied:
// scoping this strictly would hide those rows from every screen and nothing would fail loudly.
#[PropertyOwned(portfolioRowsWhenNull: true)]
#[PostingDateGuardedBy(guard: PayrollService::class)]
class Payroll extends Model
{
    use AllocatesDocumentNumber, RefusesDeletionOfCommittedRecords;
    use HasFactory, HasSearchText, LogsActivity, SoftDeletes;
    use RecordsBankAccount;

    /**
     * Salaries leave the payroll account where the operator holds one — an Egyptian bank issuing a
     * salary transfer file wants its own — and the operating account otherwise.
     */
    public static function bankAccountPurpose(): string
    {
        return BankAccount::PURPOSE_PAYROLL;
    }

    /** This document calls its rail `paid_from`, not `method`. */
    public static function bankAccountRailColumn(): string
    {
        return 'paid_from';
    }

    protected $fillable = [
        'bank_account_id',
        'number',
        'asset_id',
        'period_month',
        'description',
        'gross_salaries',
        'allowances',
        'salary_tax',
        'social_insurance',
        'advance_deductions',
        'other_deductions',
        'employer_social_insurance',
        'net_paid',
        'paid_from',
        'status',
        'approved_by_user_id',
        'created_by_user_id',
        'approved_at',
    ];

    protected $casts = [
        'period_month' => 'date',
        'approved_at' => 'datetime',
        'gross_salaries' => 'decimal:2',
        'allowances' => 'decimal:2',
        'salary_tax' => 'decimal:2',
        'social_insurance' => 'decimal:2',
        'advance_deductions' => 'decimal:2',
        'other_deductions' => 'decimal:2',
        'employer_social_insurance' => 'decimal:2',
        'net_paid' => 'decimal:2',
    ];

    /**
     * Payroll run number and its description.
     *
     * @return array<int, string|int|float|null>
     */
    public function searchTextSources(): array
    {
        return [
            $this->number,
            $this->description,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'payroll');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    /** Per-employee breakdown (module 24, Phase 3) — the payslip lines. */
    public function lines(): HasMany
    {
        return $this->hasMany(PayrollLine::class);
    }

    /**
     * When a run has per-employee lines, its header aggregates DERIVE from Σ lines
     * (so the header — and the GL entry that posts from it — always ties to the sum
     * of the payslips). Called only from the PayrollLine save/delete hooks, so the
     * no-lines branch means the LAST line was just removed → reset the header to zero
     * (Σ of no lines = 0), never leave a stale line-derived total on the books. A pure
     * lump-sum run (no lines, no hooks) never reaches here, so its manual amounts stand.
     * saveQuietly + explicit net so it doesn't loop through the line hooks.
     */
    /**
     * **The one definition of what a run's header is, given its payslips.**
     *
     * Written out TWICE until 2026-08-20 — here and in the `saving` hook — as seven identical sums
     * plus the net. They agreed, and that is the whole hazard: the two copies existed for different
     * reasons (a line was saved; someone edited the header while lines exist) and an EIGHTH
     * component would have to be added to both, with the one that was missed producing a payroll
     * header that disagrees with the payslips beneath it. That is the divergence the invoice
     * validation sweep closed on §8 R1, and the same rule applies: several channels change the
     * number, so exactly one method computes it.
     *
     * Assigns only — persistence belongs to the caller, because the `saving` hook is already inside
     * a save and must not re-enter one.
     *
     * `employer_social_insurance` is summed but NOT deducted: it is the employer's own cost, not a
     * withholding from the employee. The advance installment and ad-hoc deductions ARE deducted —
     * they repay the loan and withhold from pay.
     */
    public function fillTotalsFromLines(): void
    {
        $this->gross_salaries = round((float) $this->lines()->sum('gross'), 2);
        $this->allowances = round((float) $this->lines()->sum('allowances'), 2);
        $this->salary_tax = round((float) $this->lines()->sum('salary_tax'), 2);
        $this->social_insurance = round((float) $this->lines()->sum('social_insurance'), 2);
        $this->advance_deductions = round((float) $this->lines()->sum('advance_deduction'), 2);
        $this->other_deductions = round((float) $this->lines()->sum('other_deductions'), 2);
        $this->employer_social_insurance = round((float) $this->lines()->sum('employer_social_insurance'), 2);
        $this->net_paid = round(
            $this->gross_salaries - $this->salary_tax - $this->social_insurance
                - $this->advance_deductions - $this->other_deductions,
            2,
        );
    }

    public function recomputeFromLines(): void
    {
        if (! $this->lines()->exists()) {
            $this->gross_salaries = 0;
            $this->allowances = 0;
            $this->salary_tax = 0;
            $this->social_insurance = 0;
            $this->advance_deductions = 0;
            $this->other_deductions = 0;
            $this->employer_social_insurance = 0;
            $this->net_paid = 0;
            $this->saveQuietly();

            return;
        }

        $this->fillTotalsFromLines();
        $this->saveQuietly();
    }

    /** Recognised on the GL once approved (past draft, not cancelled). */
    public function isPostable(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * The number prefix for this document's sequence — ONE definition, used by generateNumber()
     * and by the allocation lock key (see AllocatesDocumentNumber). Two copies would drift, and a
     * lock keyed on a prefix that no longer matches the sequence it guards protects nothing.
     */
    public static function numberPrefix(string $assetCode = 'GEN', ?\DateTimeInterface $month = null): string
    {
        $month = $month ? Carbon::instance($month) : now();

        return sprintf('%s-%s-%s-', DocumentNumbering::prefixFor('payroll'), $assetCode, $month->format('Ym'));
    }

    public static function generateNumber(string $assetCode = 'GEN', ?\DateTimeInterface $month = null): string
    {
        $prefix = static::numberPrefix($assetCode, $month);

        $lastNumber = static::withTrashed()
            ->where('number', 'like', $prefix.'%')
            // LENGTH first: `orderByDesc('number')` alone is a STRING sort, so once a series passes
            // its zero-padding the shorter number sorts higher and MAX returns the wrong row.
            ->orderByRaw('LENGTH(number) DESC, number DESC')
            ->value('number');

        $next = $lastNumber ? ((int) substr($lastNumber, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    protected static function booted(): void
    {
        static::saving(function (self $payroll) {
            // Coerce blank NOT-NULL money inputs to 0 — read the RAW attribute (a
            // decimal:2 cast throws MathException if '' is read through the getter).
            foreach (['gross_salaries', 'allowances', 'salary_tax', 'social_insurance', 'advance_deductions', 'other_deductions', 'employer_social_insurance'] as $column) {
                $raw = $payroll->getAttributes()[$column] ?? null;
                if ($raw === null || $raw === '') {
                    $payroll->{$column} = 0;
                }
            }

            // ── An APPROVED run's money is settled ────────────────────────────────────────────
            // Approval posts the run to the GL. The module doc states the freeze as a fact —
            // "once approved the header (and its GL entry) is settled and the lines are frozen" —
            // and names its enforcement as `abort_unless(runIsEditable)`, which exists in exactly
            // one place: `PayrollLinesRelationManager`. That made the freeze a property of one
            // screen. `GeneratePayrollService` guards itself, so both KNOWN writers were safe and
            // every other one restated a posted payroll.
            //
            // Read against the ORIGINAL status so approving (draft → approved) is not blocked by
            // its own outcome, and cancelling stays possible — it is the correction path.
            // (Module 24 close-out, 2026-08-11; the mirror of the disposed-asset and settled-عهدة
            // freezes in modules 23 and 25.)
            // ── NOBODY IS PAID TWICE FOR THE SAME MONTH (2026-08-20) ──────────────────────────
            //
            // `payroll_lines` is unique on (run, employee), so an employee cannot appear twice in
            // ONE run — and nothing stopped a SECOND run for the same property and month. Found by
            // driving it: two August runs, nine employees each, both approvable. Approving both paid
            // every one of them twice and posted 134,564 for a month whose payroll was 66,782, with
            // no screen and no tie-out objecting, because each run is internally perfect.
            //
            // Guarded at the TRANSITION INTO approved, and on the EMPLOYEE, not on the run. A
            // supplementary run is legitimate — a bonus, an off-cycle correction, a starter paid
            // late — and refusing a second run outright would block all of them. What may never
            // happen is the same person drawing two approved payslips for the same period.
            //
            // On the model rather than in the approve action, for the reason the posting-date guards
            // are: the action is one caller, and a console or a service restating a run must meet
            // the same refusal.
            if ($payroll->exists
                && $payroll->status === 'approved'
                && $payroll->getOriginal('status') !== 'approved'
                && $payroll->period_month !== null) {
                $employeeIds = $payroll->lines()->pluck('employee_id')->filter();

                if ($employeeIds->isNotEmpty()) {
                    $clashing = PayrollLine::query()
                        ->whereIn('employee_id', $employeeIds)
                        ->whereHas('payroll', fn ($q) => $q
                            ->where('status', 'approved')
                            ->where('asset_id', $payroll->asset_id)
                            ->whereDate('period_month', $payroll->period_month)
                            ->whereKeyNot($payroll->getKey()))
                        ->with('employee:id,name')
                        ->get();

                    if ($clashing->isNotEmpty()) {
                        throw new \DomainException(__('admin.payroll.errors.already_paid_this_month', [
                            'names' => $clashing->pluck('employee.name')->filter()->unique()->take(3)->implode('، '),
                            'count' => $clashing->pluck('employee_id')->unique()->count(),
                        ]));
                    }
                }
            }

            if ($payroll->exists && $payroll->getOriginal('status') === 'approved') {
                $frozen = ['gross_salaries', 'allowances', 'salary_tax', 'social_insurance',
                    'advance_deductions', 'other_deductions', 'employer_social_insurance',
                    'net_paid', 'paid_from', 'period_month', 'asset_id'];

                foreach ($frozen as $field) {
                    if ($payroll->isDirty($field)) {
                        throw new \DomainException(__('admin.payroll.errors.approved_immutable'));
                    }
                }
            }

            // ── The header ties to the payslips, from BOTH directions ────────────────────────
            // `recomputeFromLines()` sums the lines into the header, and its docblock says it is
            // "called only from the PayrollLine save/delete hooks" — so the lines pulled the header
            // and nothing pushed back. A header written directly persisted whatever arrived, and
            // `PayrollJournalizer` posts the salaries debit from the HEADER while the payslips (and
            // the PDFs an employee is handed) said something else. The same divergence the
            // validation sweep closed on invoices (§8 R1), and closed the same way.
            //
            // A LUMP-SUM run keeps its manual amounts: with no payslips there is nothing to derive
            // from, which `recomputeFromLines` already carves out for the same reason an invoice
            // with no line items keeps its header.
            if ($payroll->exists
                && $payroll->isDirty(['gross_salaries', 'allowances', 'salary_tax', 'social_insurance',
                    'advance_deductions', 'other_deductions', 'employer_social_insurance'])
                && $payroll->lines()->exists()) {
                $payroll->fillTotalsFromLines();
            }

            // net_paid is derived on every write path (employer SI is NOT deducted; the advance
            // installment + ad-hoc deductions ARE — they repay the loan / withhold from pay).
            $payroll->net_paid = round(
                (float) $payroll->gross_salaries - (float) $payroll->salary_tax - (float) $payroll->social_insurance - (float) $payroll->advance_deductions - (float) $payroll->other_deductions,
                2,
            );
        });

        static::creating(function (self $payroll) {
            if (empty($payroll->number)) {
                // Resolved once: the prefix that keys the lock and the sequence the
                // generator reads must be the same string, or the lock guards nothing.
                $assetCode = $payroll->asset?->code ?: 'GEN';

                $payroll->number = $payroll->allocateDocumentNumber(
                    static::numberPrefix($assetCode, $payroll->period_month),
                    fn (): string => static::generateNumber($assetCode, $payroll->period_month),
                );
            }
        });
    }
}
