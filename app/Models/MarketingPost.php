<?php

namespace App\Models;

use App\Models\Concerns\HasSearchText;
use App\Support\MarketingFeedCache;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
// `Carbon\Carbon`, not `Illuminate\Support\Carbon`: the `datetime` cast is typed as the base class,
// and `Illuminate\Support\Carbon` extends it — so declaring the base accepts what Laravel actually
// hands back at runtime, while declaring the subclass makes `validUntil()` narrower than its own
// return value. Widening the two parameter types below is safe: they still accept everything they
// accepted before.
use Carbon\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * One shopper-facing card in a mall's feed: an offer, an event, or a piece of mall news.
 *
 * Authored either by the operator's marketing team (published directly) or by the retailer from
 * /portal or the mobile API, in which case it waits in `pending` until an operator approves it.
 * See {@see \App\Services\MarketingPost\PublishMarketingPostService} and docs/modules/36-*.md.
 *
 * ## The one predicate: {@see scopeLiveFor}
 *
 * "Is this post on screen right now" is asked in five places — the public visitor API, the tenant
 * feed API, the portal, the admin "Live" filter, and the carousel. It is defined ONCE, here, and
 * every one of those consumers calls it. Re-deriving it at a call site is how a post ends up
 * visible to shoppers a week after it expired on one surface and not another, and the two answers
 * disagreeing is not something a test at either end would notice.
 *
 * The predicate deliberately keys on the DISPLAY window, falling back to the validity window when
 * display is unset — see the migration docblock for why those are two different things.
 */
class MarketingPost extends Model implements HasMedia
{
    use HasFactory, HasSearchText, InteractsWithMedia, LogsActivity, SoftDeletes;

    /** The card artwork. Public disk — a shopper fetching the feed is unauthenticated. */
    public const HERO_COLLECTION = 'hero';

    /** Extra photos for the detail screen (an event gallery, a lookbook). Also public. */
    public const GALLERY_COLLECTION = 'gallery';

    public const TYPE_OFFER = 'offer';

    public const TYPE_EVENT = 'event';

    public const TYPE_NEWS = 'news';

    public const TYPES = [self::TYPE_OFFER, self::TYPE_EVENT, self::TYPE_NEWS];

    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_PENDING,
        self::STATUS_PUBLISHED,
        self::STATUS_REJECTED,
        self::STATUS_ARCHIVED,
    ];

    /**
     * Who the post is served to. `tenants` is not decoration — a retailer-staff discount or a
     * "trading hours change" notice is genuinely internal, and the public API filters on this.
     */
    public const AUDIENCE_VISITORS = 'visitors';

    public const AUDIENCE_TENANTS = 'tenants';

    public const AUDIENCE_BOTH = 'both';

    public const AUDIENCES = [self::AUDIENCE_VISITORS, self::AUDIENCE_TENANTS, self::AUDIENCE_BOTH];

    /** Statuses a retailer may still edit. Anything else is under operator control. */
    public const TENANT_EDITABLE_STATUSES = [self::STATUS_DRAFT, self::STATUS_REJECTED];

    protected $fillable = [
        'asset_id',
        'tenant_id',
        'type',
        'status',
        'audience',
        'title',
        'title_ar',
        'summary',
        'summary_ar',
        'body',
        'body_ar',
        'terms',
        'terms_ar',
        'discount_label',
        'discount_label_ar',
        'starts_at',
        'ends_at',
        'display_from',
        'display_until',
        'is_featured',
        'priority',
        'cta_label',
        'cta_label_ar',
        'cta_url',
        'created_by',
        'submitted_by_tenant_user_id',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'published_at',
    ];

    /**
     * Defaults for the NOT-NULL columns a partially-filled form would otherwise leave null —
     * an unchecked Toggle sends false, but a form that never rendered the field sends nothing.
     */
    protected $attributes = [
        'type' => self::TYPE_OFFER,
        'status' => self::STATUS_DRAFT,
        'audience' => self::AUDIENCE_VISITORS,
        'is_featured' => false,
        'priority' => 0,
        'view_count' => 0,
        'click_count' => 0,
    ];

    /**
     * Declared as the `$casts` PROPERTY, not the `casts()` method.
     *
     * Both work identically at runtime, but static analysis only reads the property: larastan types
     * a model attribute from the migration column and then applies `$casts` on top, so a datetime
     * declared only in `casts()` stays a `string` to PHPStan — which is why every
     * `$post->starts_at->toIso8601String()` in this module read as "method call on string". 77 of the
     * project's 82 models already declare it this way; this one is now the 78th.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'display_from' => 'datetime',
        'display_until' => 'datetime',
        'reviewed_at' => 'datetime',
        'published_at' => 'datetime',
        'is_featured' => 'boolean',
        'priority' => 'integer',
        'view_count' => 'integer',
        'click_count' => 'integer',
    ];

    /**
     * Never let a blank form field write null into a NOT-NULL column (the `meter_readings.cost` /
     * `leases.has_percentage_rent` class of bug). Coerced in the model so every writer — form,
     * service, API action, seeder — is covered by one rule rather than four.
     */
    protected static function booted(): void
    {
        static::saving(function (self $post): void {
            $post->is_featured = (bool) $post->is_featured;
            $post->priority = (int) $post->priority;
            $post->view_count = (int) $post->view_count;
            $post->click_count = (int) $post->click_count;
            $post->type = $post->type ?: self::TYPE_OFFER;
            $post->status = $post->status ?: self::STATUS_DRAFT;
            $post->audience = $post->audience ?: self::AUDIENCE_VISITORS;
        });

        // Invalidate this property's cached shopper feed on ANY model-level change.
        //
        // Hooked on the model rather than in the publish/archive services on purpose: a post's
        // appearance on the feed changes in more ways than the workflow transitions (editing the
        // copy, re-dating the window, featuring it, soft-deleting it, restoring it), and a hook
        // per path is the arrangement where one gets forgotten. The TTL still backstops whatever
        // this misses — see App\Support\MarketingFeedCache for why it is both.
        //
        // Note this does NOT fire for the shopper-view counters, which are builder increments
        // precisely so a read does not invalidate the cache it just populated.
        // No null check on `asset_id`: the column is NOT NULL (`foreignId(...)->constrained()`),
        // and these three hooks only fire after a row has successfully hit the database, so there
        // is no state in which it is unset here.
        $bust = function (self $post): void {
            MarketingFeedCache::bump((int) $post->asset_id);
        };

        static::saved($bust);
        static::deleted($bust);
        static::restored($bust);
    }

    // ============ Search ============

    /**
     * What an operator types to find a post: its headline in either language, the badge, and the
     * store's own name is NOT here — the blob is a pure function of this row's own attributes
     * (see HasSearchText), so "every Defacto offer" is a relation search against
     * `tenant.search_text`, which the resource wires up.
     *
     * @return array<int, string|int|float|null>
     */
    public function searchTextSources(): array
    {
        return [
            $this->title,
            $this->title_ar,
            $this->summary,
            $this->summary_ar,
            $this->discount_label,
            $this->discount_label_ar,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['status', 'title', 'is_featured', 'priority', 'published_at', 'reviewed_by', 'review_notes'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('marketing_post');
    }

    // ============ Media ============

    /**
     * Hero + gallery are PUBLIC, and unlike every other collection in this system that is the
     * correct answer rather than an oversight. A shopper fetching /api/v1/public/... has no
     * session and no token by design; artwork the mall is actively broadcasting to the street is
     * not confidential. Registered explicitly (medialibrary's default is fail-open) and listed in
     * MediaPrivacyConformanceTest's PUBLIC_COLLECTIONS with that reason, so the decision is
     * reviewed rather than inherited.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::HERO_COLLECTION)->useDisk('public')->singleFile();
        $this->addMediaCollection(self::GALLERY_COLLECTION)->useDisk('public');
    }

    public function heroUrl(): ?string
    {
        $media = $this->getFirstMedia(self::HERO_COLLECTION);

        return $media?->getFullUrl();
    }

    /** @return array<int, string> */
    public function galleryUrls(): array
    {
        return $this->getMedia(self::GALLERY_COLLECTION)
            ->map(fn ($media) => $media->getFullUrl())
            ->values()
            ->all();
    }

    // ============ Relationships ============

    /** @return BelongsTo<Asset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** @return BelongsTo<TenantUser, $this> */
    public function submittedByTenantUser(): BelongsTo
    {
        return $this->belongsTo(TenantUser::class, 'submitted_by_tenant_user_id');
    }

    /** Marketing spend booked against this campaign — the content↔money join (module 13). */
    /** @return HasMany<MarketingSpend, $this> */
    public function spends(): HasMany
    {
        return $this->hasMany(MarketingSpend::class);
    }

    // ============ The predicate ============

    /**
     * THE definition of "on screen right now". Every consumer calls this; none re-derives it.
     *
     * Keys on the DISPLAY window, falling back to the validity window where display is unset —
     * `COALESCE(display_from, starts_at)`. A post with neither is treated as always-on once
     * published, which is what an operator means by a mall-news item with no dates.
     *
     * @param  Builder<self>  $query
     * @param  string  $audience  One of self::AUDIENCES — the surface asking. `visitors` also
     *                            matches `both`; passing `null` skips the audience filter entirely
     *                            (the admin "Live" filter, which shows everything that is running).
     */
    public function scopeLiveFor(Builder $query, ?string $audience = self::AUDIENCE_VISITORS, ?Carbon $at = null): Builder
    {
        $now = $at ?? now();

        // Written as "no boundary OR boundary satisfied" rather than relying on AND/OR precedence
        // inside a raw string — the same rule twice, once per end of the window.
        $query->where('status', self::STATUS_PUBLISHED)
            ->whereRaw('(COALESCE(display_from, starts_at) IS NULL OR COALESCE(display_from, starts_at) <= ?)', [$now])
            ->whereRaw('(COALESCE(display_until, ends_at) IS NULL OR COALESCE(display_until, ends_at) >= ?)', [$now]);

        // A store-attributed post is only live while its store is still SHOWABLE — trading in this
        // mall, active, and listed in the directory.
        //
        // Two failures this closes, and neither is exotic. A retailer's lease ends, they move out,
        // and their approved offer keeps advertising a shop that is not there until its end date
        // catches up. And an unlisted retailer — one the operator deliberately hid from the
        // directory — was still having their name and logo broadcast on every card, with the
        // tap-through to their store page 404ing, because the store endpoint checked `is_listed`
        // and the feed did not.
        //
        // It lives HERE rather than in the public controllers so there is still exactly one
        // predicate. Putting it on the shopper surface alone would make the operator's "Showing
        // now" disagree with what shoppers actually see — the drift this method exists to prevent.
        // A mall-wide post (no tenant) is unaffected.
        $query->where(function (Builder $q): void {
            $q->whereNull('tenant_id')
                ->orWhereHas('tenant', fn ($tenant) => $tenant
                    ->where('is_listed', true)
                    ->where('status', 'active')
                    // Correlated to the post's own property: trading SOMEWHERE is not enough,
                    // they have to be trading in the mall the shopper is standing in. Through the
                    // lease_unit pivot (`activeLeases.units`), so an additional unit on a
                    // multi-unit lease counts.
                    ->whereHas('activeLeases.units', fn ($units) => $units
                        ->whereColumn('units.asset_id', 'marketing_posts.asset_id')));
        });

        if ($audience !== null) {
            $query->whereIn('audience', array_unique([$audience, self::AUDIENCE_BOTH]));
        }

        return $query;
    }

    /**
     * Feed order: featured first, then the marketing team's agreed priority, then newest.
     * Shared by the carousel and the list so the two never disagree about what is "top".
     *
     * @param  Builder<self>  $query
     */
    public function scopeFeedOrder(Builder $query): Builder
    {
        return $query->orderByDesc('is_featured')
            ->orderByDesc('priority')
            ->orderByDesc('published_at')
            ->orderByDesc('id');
    }

    // ============ State ============

    public function isEditableByTenant(): bool
    {
        return in_array($this->status, self::TENANT_EDITABLE_STATUSES, true);
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    public function isAwaitingReview(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    /**
     * Composed by a retailer rather than by the operator's marketing team — i.e. there is someone
     * on the other side of the review to notify.
     *
     * Keyed on `created_by` being absent rather than on `submitted_by_tenant_user_id` being
     * present, because the two tenant surfaces populate different columns: the portal knows which
     * TenantUser is acting, while the mobile API authenticates the `Tenant` itself (the
     * `tenant-api` guard) and has no user to record. A predicate reading the portal-only column
     * would silently classify every API submission as operator-authored and skip its notification.
     */
    public function isTenantAuthored(): bool
    {
        return $this->created_by === null && $this->tenant_id !== null;
    }

    /**
     * Past its window while still marked published — what the expiry sweep archives. Asked of a
     * loaded row (the sweep asks it in SQL); both sides use the same COALESCE rule.
     */
    public function hasExpired(?Carbon $at = null): bool
    {
        $end = $this->display_until ?? $this->ends_at;

        return $end !== null && $end->lt($at ?? now());
    }

    /** The end date a shopper is shown — the promise, not the display window. */
    public function validUntil(): ?Carbon
    {
        return $this->ends_at;
    }
}
