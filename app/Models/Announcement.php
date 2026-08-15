<?php

namespace App\Models;

use App\Jobs\BroadcastAnnouncement;
use App\Models\Concerns\HasSearchText;
use App\Notifications\AnnouncementNotification;
use App\Services\SendAnnouncementAction;
use App\Support\Attributes\DeletionAllowed;
use App\Support\Attributes\PropertyOwned;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * A notice from the mall office to the tenants of one property — and, since it became a post, a
 * thing they can still read a month later.
 *
 * The record carries both languages, a category, optional artwork, an optional display window and
 * a pin. It is delivered as a bell row + FCM push ({@see AnnouncementNotification})
 * whose deep link opens the post itself, and it is served to the mobile app as a feed
 * (`GET /api/v1/me/announcements`) and to the web portal as a read-only resource.
 *
 * ## The one predicate: {@see scopeLiveFor}
 *
 * "Does this tenant see this notice right now" is asked by the list endpoint, the detail endpoint,
 * the unread badge, and the portal table. It is defined ONCE, here. Two surfaces disagreeing about
 * whether a notice is live is not a failure either end's tests would catch, and the symptom — a
 * retailer arguing they were never told something the operator's screen says they were — reads as
 * a data problem rather than a code one.
 *
 * The predicate keys on the RECIPIENT ROW, not on property membership. See
 * {@see AnnouncementRecipient} for why that distinction is load-bearing rather than an
 * optimisation.
 *
 * ## Mutability
 *
 * A `draft` or `scheduled` announcement is ordinary editable content. A **sent** one is evidence —
 * tenants have been pushed its text, and `announcement_recipients` records who — so it is
 * immutable from that moment: {@see isEditable} answers false, the admin resource refuses the edit
 * page for it, and the operator corrects it by sending another notice. That is the split the
 * `marketing_posts` migration argued could not coexist in one row; it can, because `status` says
 * which side of it the row is on.
 *
 * Property-owned (direct `asset_id`) — a notice targets exactly one mall.
 */
#[DeletionAllowed(reason: 'configuration: a notice board post; a SENT one is refused by the resource, which is the state that carries evidence')]
// broadcast targeted at one property
#[PropertyOwned]
class Announcement extends Model implements HasMedia
{
    use HasSearchText, InteractsWithMedia, SoftDeletes;

    /**
     * The notice artwork. **PRIVATE disk, unlike `marketing_posts`' hero** — and the difference is
     * the audience, not an oversight. A shopper reads a marketing card unauthenticated, so its
     * image has to be reachable without a session; a tenant notice is read by the tenants of one
     * mall, and its artwork can be an evacuation map or a floor plan. Served through
     * `GET /api/v1/me/announcements/{id}/hero`, which checks the recipient row first.
     */
    public const HERO_COLLECTION = 'hero';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_SENT = 'sent';

    /** @var list<string> */
    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_SCHEDULED, self::STATUS_SENT];

    public const CATEGORY_GENERAL = 'general';

    public const CATEGORY_OPERATIONS = 'operations';

    public const CATEGORY_EVENT = 'event';

    public const CATEGORY_EMERGENCY = 'emergency';

    public const CATEGORY_HOURS = 'hours';

    /** @var list<string> */
    public const CATEGORIES = [
        self::CATEGORY_GENERAL,
        self::CATEGORY_OPERATIONS,
        self::CATEGORY_EVENT,
        self::CATEGORY_EMERGENCY,
        self::CATEGORY_HOURS,
    ];

    protected $fillable = [
        'asset_id',
        'title',
        'title_ar',
        'body',
        'body_ar',
        'category',
        'status',
        'publish_at',
        'expires_at',
        'is_pinned',
        'created_by',
        'sent_at',
        'recipients_count',
    ];

    /**
     * The column defaults, mirrored in PHP.
     *
     * The database has them too, but a database default only exists AFTER the insert — so a
     * freshly created model reads `category` as null until somebody refreshes it, and the
     * notification raised inside the same request carries that null into its payload. (It did:
     * the bell stored `announcement_category: null` and the app had no chip to render.) Same
     * class of bug as a blank form field reaching a NOT NULL column, fixed in the same place.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'category' => self::CATEGORY_GENERAL,
        'status' => self::STATUS_DRAFT,
        'is_pinned' => false,
        'recipients_count' => 0,
    ];

    protected $casts = [
        'publish_at' => 'datetime',
        'expires_at' => 'datetime',
        'sent_at' => 'datetime',
        'is_pinned' => 'boolean',
        'recipients_count' => 'integer',
    ];

    /**
     * Both languages, so an operator finds the notice by what it said in either.
     *
     * A pure function of this row's own attributes — never reaches through a relation, or renaming
     * the property would strand every blob that quoted it.
     *
     * @return array<int, string|int|float|null>
     */
    public function searchTextSources(): array
    {
        return [
            $this->title,
            $this->title_ar,
            $this->body,
            $this->body_ar,
        ];
    }

    public function registerMediaCollections(): void
    {
        // `local`, explicitly. medialibrary's default is env('MEDIA_DISK', 'public') — fail-open —
        // and a tenant notice is not public. See the HERO_COLLECTION docblock.
        $this->addMediaCollection(self::HERO_COLLECTION)->useDisk('local')->singleFile();
    }

    // ============ Relationships ============

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Everyone the broadcast reached, and whether they have read it.
     *
     * @return HasMany<AnnouncementRecipient, $this>
     */
    public function recipients(): HasMany
    {
        return $this->hasMany(AnnouncementRecipient::class);
    }

    /**
     * The subset who opened it — the numerator of the read rate the admin table shows.
     *
     * @return HasMany<AnnouncementRecipient, $this>
     */
    public function reads(): HasMany
    {
        return $this->recipients()->whereNotNull('read_at');
    }

    // ============ State ============

    public function isSent(): bool
    {
        return $this->status === self::STATUS_SENT;
    }

    public function isScheduled(): bool
    {
        return $this->status === self::STATUS_SCHEDULED;
    }

    /**
     * A notice is editable until it has been broadcast. After that it is evidence: tenants hold a
     * push notification quoting its text and `announcement_recipients` records who. Correct it by
     * sending another notice, which is the only correction a tenant can actually see.
     */
    public function isEditable(): bool
    {
        return ! $this->isSent();
    }

    /** Ready to broadcast: composed, not yet sent, and (if scheduled) its time has come. */
    public function isDueToSend(): bool
    {
        if ($this->isSent()) {
            return false;
        }

        return $this->publish_at === null || $this->publish_at->isPast();
    }

    // ============ Localized content ============

    /**
     * The title in the reader's language, falling back to the one the operator actually wrote.
     *
     * Both columns ship on every API payload and the client picks — but the BELL and the PUSH are
     * rendered server-side, once, per recipient, so they need this. It is what lets
     * `BellChannel`'s per-locale re-render mean something for an announcement: the channel has
     * always stored every supported language, and with one text column there was only ever one
     * answer to store.
     */
    public function titleFor(?string $locale = null): string
    {
        return $this->pick($this->title, $this->title_ar, $locale);
    }

    public function bodyFor(?string $locale = null): string
    {
        return $this->pick($this->body, $this->body_ar, $locale);
    }

    /**
     * Falls back rather than blanking: an operator who wrote only English meant that text to be
     * read, not to be hidden from Arabic readers.
     */
    private function pick(?string $en, ?string $ar, ?string $locale): string
    {
        $locale ??= app()->getLocale();

        $preferred = $locale === 'ar' ? $ar : $en;
        $fallback = $locale === 'ar' ? $en : $ar;

        return trim((string) $preferred) !== '' ? (string) $preferred : (string) ($fallback ?? '');
    }

    public function heroUrl(): ?string
    {
        $media = $this->getFirstMedia(self::HERO_COLLECTION);

        return $media === null
            ? null
            : route('api.v1.me.announcements.hero', ['id' => $this->id, 'media' => $media->id]);
    }

    // ============ Scopes ============

    /**
     * **The one visibility predicate.** A notice is on a tenant's feed when it has been broadcast,
     * they were one of its recipients, and its display window has not closed.
     *
     * `expires_at` is optional and null means "no end", which is what an operator means by a
     * standing notice. There is deliberately no start clause — `sent_at` IS the start, and a
     * second one would let a notice be pushed to a phone while absent from the feed the push
     * deep-links into.
     *
     * @param  Builder<Announcement>  $query
     */
    public function scopeLiveFor(Builder $query, Tenant|int $tenant): void
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->getKey() : $tenant;

        $query
            ->where('status', self::STATUS_SENT)
            ->whereHas('recipients', fn (Builder $q) => $q->where('tenant_id', $tenantId))
            ->where(fn (Builder $q) => $q
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>=', now()));
    }

    /**
     * Feed order: pinned first, then newest. One method, so the list endpoint and the carousel
     * can never disagree about what is at the top.
     *
     * @param  Builder<Announcement>  $query
     */
    public function scopeFeedOrder(Builder $query): void
    {
        $query->orderByDesc('is_pinned')
            ->orderByDesc('sent_at')
            ->orderByDesc('id');
    }

    /**
     * Scheduled notices whose time has come — the sweep's set.
     *
     * @param  Builder<Announcement>  $query
     */
    public function scopeDueToSend(Builder $query): void
    {
        $query->where('status', self::STATUS_SCHEDULED)
            ->whereNotNull('publish_at')
            ->where('publish_at', '<=', now());
    }

    /** @see BroadcastAnnouncement @see SendAnnouncementAction */
    public function broadcast(): void
    {
        BroadcastAnnouncement::dispatch($this);
    }
}
