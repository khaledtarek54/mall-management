<?php

namespace App\Models;

use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PropertyOwned;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message in an owner-request conversation (module 15) — the reply thread that turns a one-shot
 * ticket into a real back-and-forth. Immutable once posted (a conversation log), so no update path.
 */
#[DeletionAllowed(reason: 'parent-managed: belongs to its thread')]
// a reply reaches its property through its request; no resource of its own (posted via the Reply action)
#[PropertyOwned(via: 'ownerRequest')]
class OwnerRequestReply extends Model
{
    protected $fillable = [
        'owner_request_id',
        'author_id',
        'body',
    ];

    /** @return BelongsTo<OwnerRequest, $this> */
    public function ownerRequest(): BelongsTo
    {
        return $this->belongsTo(OwnerRequest::class);
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
