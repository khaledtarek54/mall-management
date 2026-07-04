<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One checklist item on a preventive-maintenance work order (module 26). Copied from
 * the plan's checklist template when the order is raised; the engineer ticks it done.
 */
class MaintenanceWorkOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'maintenance_work_order_id',
        'label',
        'is_done',
        'done_at',
        'done_by_user_id',
    ];

    protected $casts = [
        'is_done' => 'boolean',
        'done_at' => 'datetime',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(MaintenanceWorkOrder::class, 'maintenance_work_order_id');
    }

    public function doneBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'done_by_user_id');
    }
}
