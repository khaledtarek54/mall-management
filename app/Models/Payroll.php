<?php

namespace App\Models;

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
class Payroll extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'number',
        'asset_id',
        'period_month',
        'description',
        'gross_salaries',
        'salary_tax',
        'social_insurance',
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
        'salary_tax' => 'decimal:2',
        'social_insurance' => 'decimal:2',
        'net_paid' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['number', 'status', 'asset_id', 'gross_salaries', 'salary_tax', 'social_insurance', 'net_paid', 'paid_from'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('payroll');
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
    public function recomputeFromLines(): void
    {
        if (! $this->lines()->exists()) {
            $this->gross_salaries = 0;
            $this->salary_tax = 0;
            $this->social_insurance = 0;
            $this->net_paid = 0;
            $this->saveQuietly();

            return;
        }

        $this->gross_salaries = round((float) $this->lines()->sum('gross'), 2);
        $this->salary_tax = round((float) $this->lines()->sum('salary_tax'), 2);
        $this->social_insurance = round((float) $this->lines()->sum('social_insurance'), 2);
        $this->net_paid = round($this->gross_salaries - $this->salary_tax - $this->social_insurance, 2);
        $this->saveQuietly();
    }

    /** Recognised on the GL once approved (past draft, not cancelled). */
    public function isPostable(): bool
    {
        return $this->status === 'approved';
    }

    public static function generateNumber(string $assetCode = 'GEN', ?\DateTimeInterface $month = null): string
    {
        $month = $month ? Carbon::instance($month) : now();
        $prefix = sprintf('PR-%s-%s-', $assetCode, $month->format('Ym'));

        $lastNumber = static::withTrashed()
            ->where('number', 'like', $prefix.'%')
            ->orderByDesc('number')
            ->value('number');

        $next = $lastNumber ? ((int) substr($lastNumber, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    protected static function booted(): void
    {
        static::saving(function (self $payroll) {
            // Coerce blank NOT-NULL money inputs to 0 — read the RAW attribute (a
            // decimal:2 cast throws MathException if '' is read through the getter).
            foreach (['gross_salaries', 'salary_tax', 'social_insurance'] as $column) {
                $raw = $payroll->getAttributes()[$column] ?? null;
                if ($raw === null || $raw === '') {
                    $payroll->{$column} = 0;
                }
            }

            // net_paid is derived on every write path.
            $payroll->net_paid = round(
                (float) $payroll->gross_salaries - (float) $payroll->salary_tax - (float) $payroll->social_insurance,
                2,
            );
        });

        static::creating(function (self $payroll) {
            if (empty($payroll->number)) {
                $payroll->number = static::generateNumber($payroll->asset?->code ?: 'GEN', $payroll->period_month);
            }
        });
    }
}
