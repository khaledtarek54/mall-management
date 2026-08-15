<?php

namespace App\Models;

use App\Support\Attributes\DeletionAllowed;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[DeletionAllowed(reason: 'parent-managed: belongs to its request')]
class TenantRequestComment extends Model
{
    protected $fillable = [
        'tenant_request_id',
        'author_type',
        'author_id',
        'body',
        'is_internal',
    ];

    protected $casts = [
        'is_internal' => 'boolean',
    ];

    public function request(): BelongsTo
    {
        return $this->belongsTo(TenantRequest::class, 'tenant_request_id');
    }

    public function author(): MorphTo
    {
        return $this->morphTo();
    }
}
