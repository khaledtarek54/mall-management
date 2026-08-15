<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One mall-news post as its recipient sees it.
 *
 * **Both languages ship and the client picks** — the same convention as
 * {@see MarketingPostResource}, and for the same reason: the app can
 * switch language without a round-trip, and a cached feed does not go monolingual the moment the
 * reader changes their mind. (Server-side resolution would need `Accept-Language` in a cache key,
 * which is cheap but should be a deliberate change, not a side effect.)
 *
 * **A hand-written allowlist, not model serialization.** What must never appear here is the
 * operator's side of the record: `created_by`, `recipients_count`, the read receipts of OTHER
 * tenants, the draft/schedule state, `publish_at`. A retailer learning how many stores got a
 * notice — or that ten did and they were the last to open it — is the operator's business, not
 * theirs. Absent by construction rather than by remembering to filter.
 *
 * `read`/`read_at` are THIS tenant's own receipt, read off the eager-loaded recipient row. They
 * are the one piece of recipient data that belongs to the reader.
 *
 * @mixin Announcement
 */
class AnnouncementResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        // The caller's own recipient row, constrained to them by the controller's eager load. A
        // missing row cannot happen on a post this resource is reachable for — `liveFor()` filters
        // on its existence — but reading it defensively keeps a future caller that forgets the
        // constraint from reporting somebody else's receipt.
        $mine = $this->relationLoaded('recipients') ? $this->recipients->first() : null;

        return [
            'id' => (int) $this->id,
            'category' => (string) $this->category,

            'title' => (string) $this->title,
            'title_ar' => $this->title_ar,
            'body' => (string) $this->body,
            'body_ar' => $this->body_ar,

            'hero_url' => $this->heroUrl(),

            'is_pinned' => (bool) $this->is_pinned,
            // When it was broadcast — the date the app shows. Not `created_at`: a notice composed
            // a fortnight early and scheduled would otherwise show up as two weeks old the moment
            // it arrived.
            'sent_at' => $this->sent_at?->toIso8601String(),
            // When it drops off the feed. Null = standing notice. Shipped so the app can show
            // "until Friday" rather than the reader wondering whether it still applies.
            'expires_at' => $this->expires_at?->toIso8601String(),

            'read' => $mine?->read_at !== null,
            'read_at' => $mine?->read_at?->toIso8601String(),

            'property' => $this->whenLoaded('asset', fn () => [
                'code' => (string) $this->asset->code,
                'name' => (string) $this->asset->name,
            ]),
        ];
    }
}
