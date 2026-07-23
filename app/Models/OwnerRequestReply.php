<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message in an owner-request conversation (module 15) — the reply thread that turns a one-shot
 * ticket into a real back-and-forth. Immutable once posted (a conversation log), so no update path.
 */
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
