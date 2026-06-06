<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class CreditNote extends Model
{
    use LogsActivity, SoftDeletes;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'status', 'invoice_id', 'tenant_id', 'total', 'applied_amount', 'balance', 'reason'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('credit_note');
    }

    protected $fillable = [
        'number',
        'tenant_id',
        'invoice_id',
        'lease_id',
        'status',
        'issue_date',
        'reason',
        'reason_notes',
        'subtotal',
        'vat_amount',
        'total',
        'applied_amount',
        'balance',
        'currency',
        'issued_by_user_id',
        'applied_at',
        'voided_at',
        'notes',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'applied_at' => 'datetime',
        'voided_at' => 'datetime',
        'subtotal' => 'decimal:2',
        'vat_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'applied_amount' => 'decimal:2',
        'balance' => 'decimal:2',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function lease(): BelongsTo
    {
        return $this->belongsTo(Lease::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CreditNoteItem::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function hasBalance(): bool
    {
        return (float) $this->balance > 0 && in_array($this->status, ['issued', 'applied']);
    }

    public static function generateNumber(string $assetCode = 'AW', ?\DateTimeInterface $issueDate = null): string
    {
        $issueDate = $issueDate ? Carbon::instance($issueDate) : now();
        $prefix = sprintf('CN-%s-%s-', $assetCode, $issueDate->format('Ym'));

        $lastNumber = static::withTrashed()
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('number')
            ->value('number');

        $next = $lastNumber
            ? ((int) substr($lastNumber, strlen($prefix))) + 1
            : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    protected static function booted(): void
    {
        static::creating(function (self $note) {
            if (empty($note->number)) {
                $assetCode = $note->lease?->unit?->asset?->code ?: 'AW';
                $note->number = static::generateNumber($assetCode, $note->issue_date);
            }
            if (empty($note->currency)) {
                $note->currency = 'EGP';
            }
            if ($note->balance === null) {
                $note->balance = (float) ($note->total ?? 0) - (float) ($note->applied_amount ?? 0);
            }
        });
    }
}
