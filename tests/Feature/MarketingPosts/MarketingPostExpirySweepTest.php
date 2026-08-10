<?php

use App\Models\MarketingPost;
use App\Support\MarketingFeedCache;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * `marketing:expire-posts` — the hourly sweep that keeps the operator's register honest.
 *
 * The read-side predicate already hides an expired post from shoppers, so this is not what makes
 * the feed correct. It is what makes "published" in the admin list mean "running" — and the
 * distinction matters, because a marketing team looking at forty published posts of which nine
 * ended last month cannot tell what they are actually promoting.
 */
beforeEach(function (): void {
    Cache::flush();
    Storage::fake('public');
});

function sweepablePost(array $attrs = []): MarketingPost
{
    $post = MarketingPost::factory()->create(array_merge(['asset_id' => makeAsset()->id], $attrs));
    $post->addMedia(UploadedFile::fake()->image('hero.jpg'))->toMediaCollection(MarketingPost::HERO_COLLECTION);

    return $post->refresh();
}

it('archives a published post whose window has closed', function () {
    $expired = sweepablePost([
        'status' => MarketingPost::STATUS_PUBLISHED,
        'starts_at' => now()->subMonth(),
        'ends_at' => now()->subDay(),
    ]);

    $this->artisan('marketing:expire-posts')->assertSuccessful();

    expect($expired->refresh()->status)->toBe(MarketingPost::STATUS_ARCHIVED);
});

it('leaves a running post, an open-ended post, and one not yet started alone', function () {
    // The control set. Without it, a sweep that archived EVERYTHING would pass the test above.
    $running = sweepablePost(['status' => MarketingPost::STATUS_PUBLISHED, 'ends_at' => now()->addWeek()]);
    $forever = sweepablePost([
        'status' => MarketingPost::STATUS_PUBLISHED,
        'starts_at' => null, 'ends_at' => null,
    ]);
    $future = sweepablePost([
        'status' => MarketingPost::STATUS_PUBLISHED,
        'starts_at' => now()->addWeek(), 'ends_at' => now()->addMonth(),
    ]);

    $this->artisan('marketing:expire-posts')->assertSuccessful();

    expect($running->refresh()->status)->toBe(MarketingPost::STATUS_PUBLISHED)
        ->and($forever->refresh()->status)->toBe(MarketingPost::STATUS_PUBLISHED)
        ->and($future->refresh()->status)->toBe(MarketingPost::STATUS_PUBLISHED);
});

it('reads the display window ahead of the validity window', function () {
    // Valid until yesterday but scheduled to stay on screen for another week — a "last chance"
    // card. The sweep must follow the same COALESCE rule the feed does, or it files away a post
    // the shopper can still see.
    $stillShowing = sweepablePost([
        'status' => MarketingPost::STATUS_PUBLISHED,
        'ends_at' => now()->subDay(),
        'display_until' => now()->addWeek(),
    ]);

    $this->artisan('marketing:expire-posts')->assertSuccessful();

    expect($stillShowing->refresh()->status)->toBe(MarketingPost::STATUS_PUBLISHED);
});

it('is idempotent — a second run changes nothing', function () {
    $expired = sweepablePost([
        'status' => MarketingPost::STATUS_PUBLISHED,
        'ends_at' => now()->subDay(),
    ]);

    $this->artisan('marketing:expire-posts')->assertSuccessful();
    $firstTouch = $expired->refresh()->updated_at;

    $this->artisan('marketing:expire-posts')->assertSuccessful();

    expect($expired->refresh()->updated_at->eq($firstTouch))->toBeTrue()
        ->and($expired->status)->toBe(MarketingPost::STATUS_ARCHIVED);
});

it('never touches a submission still waiting on review', function () {
    // The archive service refuses a pending post outright. If the sweep ever selected one, that
    // refusal would abort the whole run and strand every post after it.
    $pending = sweepablePost([
        'status' => MarketingPost::STATUS_PENDING,
        'ends_at' => now()->subDay(),
    ]);
    $expired = sweepablePost([
        'status' => MarketingPost::STATUS_PUBLISHED,
        'ends_at' => now()->subDay(),
    ]);

    $this->artisan('marketing:expire-posts')->assertSuccessful();

    expect($pending->refresh()->status)->toBe(MarketingPost::STATUS_PENDING)
        // Control: the run did do its job, so the untouched pending row is the filter and not a
        // sweep that silently did nothing.
        ->and($expired->refresh()->status)->toBe(MarketingPost::STATUS_ARCHIVED);
});

it('writes nothing in dry-run', function () {
    $expired = sweepablePost([
        'status' => MarketingPost::STATUS_PUBLISHED,
        'ends_at' => now()->subDay(),
    ]);

    $this->artisan('marketing:expire-posts', ['--dry-run' => true])->assertSuccessful();

    expect($expired->refresh()->status)->toBe(MarketingPost::STATUS_PUBLISHED);
});

// ---------------------------------------------------------------- feed cache

it('invalidates the cached feed the moment a post changes', function () {
    // The failure this guards: an operator approves an offer, opens the app to check, sees
    // nothing, and approves it again. TTL-only caching does exactly that for up to a minute.
    $asset = makeAsset();
    $before = MarketingFeedCache::version($asset->id);

    sweepablePost(['asset_id' => $asset->id]);

    expect(MarketingFeedCache::version($asset->id))->toBeGreaterThan($before);
});

it('does not invalidate another property cached feed', function () {
    $mine = makeAsset();
    $theirs = makeAsset();
    $theirsBefore = MarketingFeedCache::version($theirs->id);

    sweepablePost(['asset_id' => $mine->id]);

    expect(MarketingFeedCache::version($theirs->id))->toBe($theirsBefore);
});

it('does not invalidate the cache when a shopper merely reads a post', function () {
    // The view counter is a builder increment precisely so a read cannot invalidate the cache it
    // just populated — otherwise the feed's hit rate collapses to zero under real traffic.
    $asset = makeAsset();
    $post = sweepablePost(['asset_id' => $asset->id, 'status' => MarketingPost::STATUS_PUBLISHED]);
    $version = MarketingFeedCache::version($asset->id);

    $this->getJson("/api/v1/public/malls/{$asset->code}/posts/{$post->id}")->assertOk();

    expect(MarketingFeedCache::version($asset->id))->toBe($version)
        // Control: the read definitely happened.
        ->and($post->refresh()->view_count)->toBe(1);
});
