<?php

namespace App\Models;

use App\Models\Concerns\RefusesDeletionOfCommittedRecords;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One dated, reasoned, attributed change to a lease — the lease's history as a list of assertions.
 *
 * See the migration for why this exists. The short version: the charge schedule records *what* the
 * rent became and *when*; nothing recorded *why*, or on whose authority, or against which signed
 * amendment.
 *
 * **This model is append-only.** `booted()` refuses updates and deletes outright. That is not
 * defensive coding — an editable audit record is not an audit record, and the one thing that would
 * quietly destroy this table's value is a well-meaning "fix the typo in the reason" action.
 */
class LeaseEvent extends Model
{
    use HasFactory, RefusesDeletionOfCommittedRecords;

    /**
     * The vocabulary of commercial change (story LE-01).
     *
     * Deliberately about the DEAL, not about the data: `rent_modification` rather than
     * `base_rent_monthly_changed`. A timeline written in column names is a second activity log, and
     * the activity log already exists.
     */
    public const TYPE_RENT_MODIFICATION = 'rent_modification';

    public const TYPE_ABATEMENT = 'abatement';

    public const TYPE_EXPANSION = 'expansion';

    public const TYPE_CONTRACTION = 'contraction';

    public const TYPE_RELOCATION = 'relocation';

    public const TYPE_EXTENSION = 'extension';

    public const TYPE_HOLDOVER = 'holdover';

    public const TYPE_TERMINATION = 'termination';

    public const TYPES = [
        self::TYPE_RENT_MODIFICATION,
        self::TYPE_ABATEMENT,
        self::TYPE_EXPANSION,
        self::TYPE_CONTRACTION,
        self::TYPE_RELOCATION,
        self::TYPE_EXTENSION,
        self::TYPE_HOLDOVER,
        self::TYPE_TERMINATION,
    ];

    protected $fillable = [
        'lease_id', 'type', 'effective_date', 'reason', 'document_reference', 'user_id', 'payload',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'payload' => 'array',
    ];

    protected static function booted(): void
    {
        // Append-only, at the model. Deletion is refused by RefusesDeletionOfCommittedRecords (the
        // project-wide backstop, which the DeletionPolicy gate requires); this closes the other
        // half, which no money record needs but an audit record does — an event you can EDIT is
        // not an audit record. Recording a correcting event is the supported fix, the same shape
        // as void/credit-note: it leaves a document an auditor can follow instead of an overwrite.
        static::updating(function (self $event): void {
            throw new \DomainException(__('admin.errors.lease_event_immutable'));
        });
    }

    /** @return BelongsTo<Lease, $this> */
    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function label(): string
    {
        return __('admin.lease_events.types.' . $this->type);
    }

    /**
     * The actor, as the timeline should name them.
     *
     * A sweep genuinely has no user, and "System" is the honest word for that — better than
     * attributing an automated escalation to whoever happened to be logged in when it ran.
     */
    public function actorName(): string
    {
        return $this->user?->name ?? __('admin.lease_events.system_actor');
    }

    public function effectiveOn(): CarbonImmutable
    {
        return CarbonImmutable::instance($this->effective_date);
    }

    /**
     * The money movement this event describes, when it describes one.
     *
     * @return array{from: float, to: float}|null
     */
    public function amountChange(): ?array
    {
        $from = $this->payload['amount_from'] ?? null;
        $to = $this->payload['amount_to'] ?? null;

        if ($from === null && $to === null) {
            return null;
        }

        return ['from' => (float) $from, 'to' => (float) $to];
    }
}
