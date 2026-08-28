<?php

namespace App\Models;

use App\Models\Concerns\RefusesRestatementOfCommittedMoney;
use App\Services\RecordAdvanceRepaymentService;
use App\Support\ActivityLogging;
use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PostingDateGuardedBy;
use App\Support\Attributes\PropertyOwned;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * One repayment against an employee advance/loan (module 24, Phase 2). Posts to the
 * GL as Dr Cash|Bank / Cr Employee Advances, reducing the receivable. A CHILD ledger
 * source of the advance — its GL follows the advance's lifecycle (EmployeeAdvance's
 * booted() cascade). `asset_id` is denormalised from the advance for the GL dimension.
 */
#[DeletionAllowed(reason: 'parent-managed: deleted to reverse a repayment')]
#[PropertyOwned]
#[PostingDateGuardedBy(guard: RecordAdvanceRepaymentService::class)]
class EmployeeAdvanceRepayment extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;
    use RefusesRestatementOfCommittedMoney;

    protected $fillable = [
        'employee_advance_id',
        'asset_id',
        'amount',
        'repaid_on',
        'method',
        'notes',
        'created_by_user_id',
    ];

    protected $casts = [
        'repaid_on' => 'date',
        'amount' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'employee_advance_repayment');
    }

    public function advance(): BelongsTo
    {
        return $this->belongsTo(EmployeeAdvance::class, 'employee_advance_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    protected static function booted(): void
    {
        static::saving(function (self $repayment) {
            $raw = $repayment->getAttributes()['amount'] ?? null;
            if ($raw === null || $raw === '') {
                $repayment->amount = 0;
            }
        });
    }

    /**
     * @see RefusesRestatementOfCommittedMoney — the `committed` sentence in
     *      App\Support\ChangeImpact::POLICY for this model, as code.
     */
    public function isCommittedMoney(): bool
    {
        // Recording a repayment is the money arriving.
        return true;
    }
}
