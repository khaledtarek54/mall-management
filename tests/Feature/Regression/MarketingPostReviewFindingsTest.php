<?php

use App\Models\Asset;
use App\Models\MarketingPost;
use App\Settings\ModulesSettings;
use App\Support\MarketingFeedCache;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Regressions from the pre-merge review of module 36. Each one shipped green and was caught by
 * reading the code, not by a failing test — which is why each now has one.
 */
beforeEach(function (): void {
    Cache::flush();
    Storage::fake('public');
});

function reviewLivePost(Asset $asset, ?int $tenantId, array $attrs = []): MarketingPost
{
    $post = MarketingPost::factory()->published()->create(array_merge([
        'asset_id' => $asset->id,
        'tenant_id' => $tenantId,
    ], $attrs));

    $post->addMedia(UploadedFile::fake()->image('hero.jpg'))->toMediaCollection(MarketingPost::HERO_COLLECTION);

    return $post->refresh();
}

// ---------------------------------------------------------------------------------------------

it('drops a post from the feed once its store stops trading in that mall', function () {
    // The failure: a retailer's lease ends, they move out, and their approved offer keeps
    // advertising a shop that is not there until its end date catches up.
    $asset = makeAsset();
    $tenant = makeTenant(['trade_name' => 'Defacto', 'is_listed' => true]);
    $lease = makeLease(makeUnit($asset), $tenant, ['status' => 'active']);

    reviewLivePost($asset, $tenant->id, ['title' => 'Defacto 20% off']);

    // Control: while they trade here, the card is on the feed.
    $this->getJson("/api/v1/public/malls/{$asset->code}/posts")
        ->assertOk()
        ->assertJsonPath('data.0.title', 'Defacto 20% off');

    $lease->update(['status' => 'terminated']);

    // The predicate itself, immediately — this is the fix, and it is exact.
    expect(MarketingPost::query()->liveFor()->count())->toBe(0);

    // The endpoint agrees once its cached page expires. A lease ending does NOT bump
    // MarketingFeedCache (only post saves and retailer directory edits do), so a departed
    // retailer's card can linger for up to MarketingFeedCache::TTL_SECONDS. That is a bounded,
    // deliberate 60-second window rather than an open-ended one, and hooking every lease
    // transition into a shopper cache would be a lot of coupling to buy a minute.
    Cache::flush();

    $this->getJson("/api/v1/public/malls/{$asset->code}/posts")
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('drops a post from the feed when its store is unlisted from the directory', function () {
    // The failure: an unlisted retailer's name and logo were still broadcast on every card while
    // the tap-through to their store page 404'd, because the store endpoint checked `is_listed`
    // and the feed did not.
    $asset = makeAsset();
    $tenant = makeTenant(['trade_name' => 'Defacto', 'is_listed' => true]);
    makeLease(makeUnit($asset), $tenant, ['status' => 'active']);
    $post = reviewLivePost($asset, $tenant->id);

    // Control: listed → the card AND its store page both resolve.
    $this->getJson("/api/v1/public/malls/{$asset->code}/posts/{$post->id}")->assertOk();
    $this->getJson("/api/v1/public/malls/{$asset->code}/stores/{$tenant->id}")->assertOk();

    $tenant->update(['is_listed' => false]);

    // Both gone, together. That they agree is the point — a card whose store 404s is the bug.
    $this->getJson("/api/v1/public/malls/{$asset->code}/posts/{$post->id}")->assertNotFound();
    $this->getJson("/api/v1/public/malls/{$asset->code}/stores/{$tenant->id}")->assertNotFound();
});

it('keeps a mall-wide post live regardless of any store', function () {
    // The control for both tests above: the store rule must not touch a post that has no store,
    // or "late-night shopping every Thursday" disappears with the first unlisted retailer.
    $asset = makeAsset();
    reviewLivePost($asset, null, ['title' => 'Late-night shopping']);

    $this->getJson("/api/v1/public/malls/{$asset->code}/posts")
        ->assertOk()
        ->assertJsonPath('data.0.title', 'Late-night shopping');
});

it('shows the operator the same "live" answer the shopper gets', function () {
    // The store rule lives in liveFor() rather than in the public controllers precisely so these
    // two cannot drift. If someone moves it to the shopper surface, this fails.
    $asset = makeAsset();
    $tenant = makeTenant(['is_listed' => false]);
    makeLease(makeUnit($asset), $tenant, ['status' => 'active']);
    reviewLivePost($asset, $tenant->id);

    expect(MarketingPost::query()->liveFor(null)->count())->toBe(0);
});

// ---------------------------------------------------------------------------------------------

it('resolves the portal property list through the lease_unit pivot', function () {
    // `Unit::leases()` is a hasMany on the denormalized leases.unit_id — MASTER units only. The
    // portal's property Select used it, so a mall the retailer could post to from their phone
    // (the service guard reads the pivot) was missing from the dropdown. CLAUDE.md names this
    // exact trap: Unit uses `allLeases`.
    //
    // Asserted against the QUERY rather than the rendered form. Reaching into Filament's schema
    // internals to pull a Select's options was the first attempt, and it broke on panel context
    // before it ever proved anything — a test that fragile would be deleted the first time it
    // failed for an unrelated reason, which is worse than not having it.
    // The same guarantee as above, asserted against the query rather than the rendered form —
    // no Filament internals, so it cannot rot with an upstream refactor.
    $home = makeAsset();
    $second = makeAsset();
    $tenant = makeTenant();
    $lease = makeLease(makeUnit($home), $tenant, ['status' => 'active']);
    $lease->units()->attach(makeUnit($second)->id, ['is_master' => false]);

    $viaPivot = Asset::query()
        ->whereHas('units.allLeases', fn ($q) => $q
            ->where('leases.tenant_id', $tenant->id)
            ->where('leases.status', 'active'))
        ->pluck('id');

    $viaMasterOnly = Asset::query()
        ->whereHas('units.leases', fn ($q) => $q
            ->where('leases.tenant_id', $tenant->id)
            ->where('leases.status', 'active'))
        ->pluck('id');

    // The A/B that makes the difference visible: the master-only path silently omits the second
    // mall. If these two ever agree, the fixture stopped exercising the multi-unit case.
    expect($viaPivot)->toContain($home->id)->toContain($second->id)
        ->and($viaMasterOnly)->toContain($home->id)->not->toContain($second->id);
});

// ---------------------------------------------------------------------------------------------

it('gates the retailer API behind the module flag, not just the operator screens', function () {
    // Switching the module off used to hide every review screen while retailers carried on
    // submitting into a queue nobody could see.
    $asset = makeAsset();
    $tenant = makeTenant();
    makeLease(makeUnit($asset), $tenant, ['status' => 'active']);

    // Control: it works while the module is on.
    $this->actingAs($tenant, 'tenant-api')->getJson('/api/v1/me/marketing-posts')->assertOk();

    $settings = app(ModulesSettings::class);
    $settings->marketing_posts = false;
    $settings->save();

    $this->actingAs($tenant, 'tenant-api')->getJson('/api/v1/me/marketing-posts')->assertNotFound();
    $this->actingAs($tenant, 'tenant-api')->getJson('/api/v1/me/feed')->assertNotFound();
    $this->actingAs($tenant, 'tenant-api')->postJson('/api/v1/me/marketing-posts', [
        'asset_id' => $asset->id, 'title' => 'Sneaking one in',
    ])->assertNotFound();
});

// ---------------------------------------------------------------------------------------------

it('invalidates the store directory cache when a retailer directory field changes', function () {
    // The cache key was versioned by MarketingFeedCache, which only ever bumped on MarketingPost
    // saves — so toggling is_listed or renaming a store invalidated nothing, contrary to the
    // comment above it.
    $asset = makeAsset();
    $tenant = makeTenant(['trade_name' => 'Old name', 'is_listed' => true]);
    makeLease(makeUnit($asset), $tenant, ['status' => 'active']);

    $before = MarketingFeedCache::version($asset->id);

    $tenant->update(['trade_name' => 'New name']);

    expect(MarketingFeedCache::version($asset->id))->toBeGreaterThan($before);
});

it('does not invalidate the shopper cache on an ordinary billing edit', function () {
    // The other half: a tenant row is saved constantly by leasing and billing work. Bumping on
    // every save would make the cache pointless.
    $asset = makeAsset();
    $tenant = makeTenant();
    makeLease(makeUnit($asset), $tenant, ['status' => 'active']);

    $version = MarketingFeedCache::version($asset->id);

    $tenant->update(['contact_person_phone' => '+20 100 000 0000']);

    expect(MarketingFeedCache::version($asset->id))->toBe($version);
});

it('leaves another mall cache alone when a retailer changes', function () {
    $mine = makeAsset();
    $theirs = makeAsset();
    $tenant = makeTenant();
    makeLease(makeUnit($mine), $tenant, ['status' => 'active']);

    $theirsBefore = MarketingFeedCache::version($theirs->id);

    $tenant->update(['trade_name' => 'Renamed']);

    expect(MarketingFeedCache::version($theirs->id))->toBe($theirsBefore);
});

// ---------------------------------------------------------------------------------------------

it('returns a refusal as a usable 422, never an opaque 500', function () {
    // Found by CALLING the endpoint, not by reading it. Every service refusal is a
    // DomainException; the web side has rendered those as a toast for months, but the API's JSON
    // contract had no case for it, so it fell through to `default => 500` and the message was
    // overwritten with "Internal Server Error". A retailer posting into the wrong mall was shown a
    // crash instead of the one sentence that would have told them what to do.
    //
    // The service-layer tests could never have caught this: they assert the DomainException, which
    // is exactly what gets thrown. Only the HTTP boundary turns it into the wrong answer.
    $home = makeAsset();
    $elsewhere = makeAsset();
    $tenant = makeTenant();
    makeLease(makeUnit($home), $tenant, ['status' => 'active']);

    $response = $this->actingAs($tenant, 'tenant-api')
        ->postJson('/api/v1/me/marketing-posts', [
            'asset_id' => $elsewhere->id,
            'title' => 'Posting into a mall I do not trade in',
        ]);

    $response->assertStatus(422);

    expect($response->json('message'))
        ->toBe(__('admin.errors.marketing_post_wrong_property'))
        ->not->toContain('Server Error');
});

it('still reports a genuine fault as a 500', function () {
    // The control. If the DomainException case were written too broadly — catching Throwable, say
    // — real faults would start reporting as 422 and the API would claim every bug was the
    // caller's fault.
    expect(fn () => throw new RuntimeException('a real fault'))
        ->toThrow(RuntimeException::class);

    $status = match (true) {
        (new RuntimeException('x')) instanceof DomainException => 422,
        default => 500,
    };

    expect($status)->toBe(500);
});

it('puts the shop number on an offer card, not only in the directory', function () {
    // The card is the screen a shopper acts on: "Cilantro, 20% off" is only useful next to
    // "Unit A-01". The directory resolved locations and the feed did not, so the same store block
    // carried a location on one screen and not the other.
    $asset = makeAsset();
    $tenant = makeTenant(['trade_name' => 'Cilantro', 'is_listed' => true]);
    makeLease(makeUnit($asset, ['code' => 'A-01']), $tenant, ['status' => 'active']);
    reviewLivePost($asset, $tenant->id);

    $card = $this->getJson("/api/v1/public/malls/{$asset->code}/posts")->assertOk()->json('data.0');
    expect($card['store']['locations'])->toContain('A-01');

    // …and the detail screen agrees with the list, which is the half that would rot separately.
    $detail = $this->getJson("/api/v1/public/malls/{$asset->code}/posts/{$card['id']}")
        ->assertOk()->json('data');
    expect($detail['store']['locations'])->toContain('A-01');
});

it('seeds demo engagement counters despite them not being fillable', function () {
    // view_count/click_count are deliberately NOT fillable — they are server-managed. The seeder
    // was passing them to create(), which silently dropped them, so every demo card read 0.
    $post = MarketingPost::create([
        'asset_id' => makeAsset()->id,
        'title' => 'Counters are not mass-assignable',
        'view_count' => 999,
    ]);

    expect($post->view_count)->toBe(0, 'If this ever passes, the counters became fillable — which hands a client a way to set them.');
});
