<?php

use App\Models\Asset;
use App\Models\MarketingPost;
use App\Models\Tenant;
use App\Settings\ModulesSettings;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * The unauthenticated shopper surface — the only place in this system a stranger reads anything.
 *
 * Two kinds of test here, and both are needed:
 *   - **visibility**: exactly the right posts appear, and nothing else;
 *   - **leakage**: the fields a shopper receives are the allowlist and nothing more.
 *
 * The leak tests assert on the *absence* of keys, which is a weak assertion on its own — a typo in
 * the endpoint would pass it. Every one is therefore paired with a positive control that must find
 * the record, so "no tax_id" can never be satisfied by "no response".
 */
beforeEach(function (): void {
    // The feed is cached for 60s; a shared store between cases would leak one test's mall into
    // another's assertions.
    Cache::flush();
    Storage::fake('public');
});

/** A published, in-window, shopper-facing post with the artwork the module requires. */
function livePost(Asset $asset, array $attrs = []): MarketingPost
{
    $post = MarketingPost::factory()->published()->create(array_merge(['asset_id' => $asset->id], $attrs));
    $post->addMedia(UploadedFile::fake()->image('hero.jpg'))->toMediaCollection(MarketingPost::HERO_COLLECTION);

    return $post->refresh();
}

/**
 * A retailer actually trading in the mall, listed in the directory.
 *
 * `$unitCode` is a parameter rather than a constant because `units` is unique per (asset, code) —
 * two stores in one mall sharing a hard-coded shop number is a fixture collision, not a finding.
 */
function listedStore(Asset $asset, array $attrs = [], string $unitCode = 'L1-24'): Tenant
{
    $tenant = makeTenant(array_merge([
        'trade_name' => 'Defacto',
        'trade_name_ar' => 'ديفاكتو',
        'retail_category' => 'fashion',
        'is_listed' => true,
    ], $attrs));

    makeLease(makeUnit($asset, ['code' => $unitCode]), $tenant, ['status' => 'active']);

    return $tenant;
}

// ---------------------------------------------------------------- visibility

it('serves a live post to a caller with no credentials at all', function () {
    $asset = makeAsset();
    livePost($asset, ['title' => '20% off everything']);

    $this->getJson("/api/v1/public/malls/{$asset->code}/posts")
        ->assertOk()
        ->assertJsonPath('data.0.title', '20% off everything');
});

it('hides drafts, pending submissions, rejections and archives', function () {
    $asset = makeAsset();
    livePost($asset, ['title' => 'LIVE']);

    foreach ([MarketingPost::STATUS_DRAFT, MarketingPost::STATUS_PENDING,
        MarketingPost::STATUS_REJECTED, MarketingPost::STATUS_ARCHIVED] as $status) {
        MarketingPost::factory()->create(['asset_id' => $asset->id, 'status' => $status, 'title' => "HIDDEN-{$status}"]);
    }

    $body = $this->getJson("/api/v1/public/malls/{$asset->code}/posts")->assertOk()->json('data');

    // The positive control: the endpoint definitely works, so the absences below mean something.
    expect($body)->toHaveCount(1)
        ->and($body[0]['title'])->toBe('LIVE');
});

it('hides a post whose window has not opened, and one whose window has closed', function () {
    $asset = makeAsset();
    livePost($asset, ['title' => 'NOW']);
    livePost($asset, ['title' => 'LATER', 'starts_at' => now()->addWeek(), 'ends_at' => now()->addMonth()]);
    livePost($asset, ['title' => 'OVER', 'starts_at' => now()->subMonth(), 'ends_at' => now()->subDay()]);

    $titles = collect($this->getJson("/api/v1/public/malls/{$asset->code}/posts")->json('data'))->pluck('title');

    expect($titles)->toContain('NOW')->not->toContain('LATER')->not->toContain('OVER');
});

it('honours the display window over the validity window when both are set', function () {
    // The two-pair rule the table exists for: teased for a week, valid for a day.
    $asset = makeAsset();
    livePost($asset, [
        'title' => 'BLACK FRIDAY',
        'starts_at' => now()->addDays(6),   // not valid yet…
        'ends_at' => now()->addDays(7),
        'display_from' => now()->subDay(),  // …but already on screen
        'display_until' => now()->addDays(7),
    ]);

    $this->getJson("/api/v1/public/malls/{$asset->code}/posts")
        ->assertOk()
        ->assertJsonPath('data.0.title', 'BLACK FRIDAY');
});

it('never serves a tenants-only post to a shopper, but does serve it to a retailer', function () {
    $asset = makeAsset();
    $staffOnly = livePost($asset, ['title' => 'STAFF 20%', 'audience' => MarketingPost::AUDIENCE_TENANTS]);
    livePost($asset, ['title' => 'PUBLIC OFFER']);

    $public = collect($this->getJson("/api/v1/public/malls/{$asset->code}/posts")->json('data'))->pluck('title');
    expect($public)->toContain('PUBLIC OFFER')->not->toContain('STAFF 20%');

    // The control — the same row IS visible on the authenticated tenant feed, so its absence
    // above is the audience filter and not a broken fixture.
    $tenant = listedStore($asset);
    $feed = collect($this->actingAs(tenantLogin($tenant), 'tenant-api')
        ->getJson('/api/v1/me/feed')->assertOk()->json('data'))->pluck('title');

    expect($feed)->toContain('STAFF 20%')->toContain('PUBLIC OFFER')
        ->and($staffOnly->audience)->toBe(MarketingPost::AUDIENCE_TENANTS);
});

it('keeps one mall out of another mall feed', function () {
    $here = makeAsset();
    $elsewhere = makeAsset();
    livePost($here, ['title' => 'HERE']);
    livePost($elsewhere, ['title' => 'ELSEWHERE']);

    $titles = collect($this->getJson("/api/v1/public/malls/{$here->code}/posts")->json('data'))->pluck('title');

    expect($titles)->toContain('HERE')->not->toContain('ELSEWHERE');
});

it('404s an expired post that someone deep-linked', function () {
    $asset = makeAsset();
    $over = livePost($asset, ['starts_at' => now()->subMonth(), 'ends_at' => now()->subDay()]);
    $live = livePost($asset);

    $this->getJson("/api/v1/public/malls/{$asset->code}/posts/{$over->id}")->assertNotFound();
    // Control: the endpoint resolves a post that IS live, so the 404 above is the window.
    $this->getJson("/api/v1/public/malls/{$asset->code}/posts/{$live->id}")->assertOk();
});

it('404s a post id borrowed from another mall', function () {
    $here = makeAsset();
    $elsewhere = makeAsset();
    $foreign = livePost($elsewhere);

    $this->getJson("/api/v1/public/malls/{$here->code}/posts/{$foreign->id}")->assertNotFound();
});

it('refuses the ALL pseudo-asset as a mall', function () {
    // It is a REAL assets row (it has to be, for Filament to resolve it from a URL slug), so the
    // refusal cannot come from "no such asset" — it is an explicit check. firstOrCreate because a
    // migration already seeds it.
    Asset::firstOrCreate(
        ['code' => Asset::ALL_PROPERTIES_CODE],
        ['name' => 'All Properties', 'type' => 'mall', 'city' => 'Cairo',
            'country' => 'EG', 'currency' => 'EGP', 'is_active' => true],
    );

    $this->getJson('/api/v1/public/malls/'.Asset::ALL_PROPERTIES_CODE.'/posts')->assertNotFound();

    // Control: a real mall's code resolves through the same route.
    $real = makeAsset();
    livePost($real);
    $this->getJson("/api/v1/public/malls/{$real->code}/posts")->assertOk();
});

it('404s the whole surface when the module is switched off', function () {
    $asset = makeAsset();
    livePost($asset);

    // Control first — it works while the module is on.
    $this->getJson("/api/v1/public/malls/{$asset->code}/posts")->assertOk();

    $settings = app(ModulesSettings::class);
    $settings->marketing_posts = false;
    $settings->save();

    $this->getJson("/api/v1/public/malls/{$asset->code}/posts")->assertNotFound();
    $this->getJson('/api/v1/public/malls')->assertNotFound();
});

// ---------------------------------------------------------------- leakage

it('sends a shopper no part of the mall internal workflow', function () {
    $asset = makeAsset();
    $post = livePost($asset, [
        'review_notes' => 'Artwork was low-resolution the first time.',
        'view_count' => 999,
        'click_count' => 42,
        'display_from' => now()->subDay(),
        'display_until' => now()->addWeek(),
    ]);

    $card = $this->getJson("/api/v1/public/malls/{$asset->code}/posts/{$post->id}")
        ->assertOk()
        // The positive control — this IS the right record, so the absences below are real.
        ->assertJsonPath('data.id', $post->id)
        ->json('data');

    expect($card)->not->toHaveKeys([
        'status', 'review_notes', 'reviewed_at', 'reviewed_by', 'submitted_by_tenant_user_id',
        'view_count', 'click_count', 'display_from', 'display_until', 'created_by', 'asset_id',
    ]);
});

it('sends a shopper no part of a retailer paperwork', function () {
    $asset = makeAsset();
    $store = listedStore($asset, [
        'tax_id' => '100-200-300',
        'national_id' => '29001011234567',
        'commercial_register' => 'CR-99',
        'legal_name' => 'Crema Coffee Co. LLC',
        'phone' => '+20 100 123 4567',
        'email' => 'billing@crema.test',
    ]);
    livePost($asset, ['tenant_id' => $store->id]);

    $payload = $this->getJson("/api/v1/public/malls/{$asset->code}/stores")->assertOk()->json('data');

    // Control: the store is definitely in the directory.
    expect($payload)->toHaveCount(1)
        ->and($payload[0]['name'])->toBe('Defacto')
        ->and($payload[0])->not->toHaveKeys([
            'tax_id', 'national_id', 'commercial_register', 'legal_name',
            'phone', 'whatsapp', 'email', 'contact_person', 'contact_person_phone',
            'address', 'status', 'metadata', 'password',
        ]);
});

it('shows the trade name rather than the billing name', function () {
    $asset = makeAsset();
    listedStore($asset, ['name' => 'Crema Coffee Co. LLC', 'trade_name' => 'Café Crema']);
    livePost($asset);

    $this->getJson("/api/v1/public/malls/{$asset->code}/stores")
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Café Crema');
});

it('falls back to the billing name when no trade name was ever filled in', function () {
    // Why trade_name is nullable: the directory has to work on day one, before anyone backfills.
    $asset = makeAsset();
    listedStore($asset, ['name' => 'Zara Egypt', 'trade_name' => null, 'trade_name_ar' => null]);

    $this->getJson("/api/v1/public/malls/{$asset->code}/stores")
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Zara Egypt');
});

it('omits an unlisted store, and a store trading in a different mall', function () {
    $here = makeAsset();
    $elsewhere = makeAsset();
    // trade_name, not name — the directory shows the sign above the door.
    listedStore($here, ['trade_name' => 'Shown'], 'A-1');
    listedStore($here, ['trade_name' => 'Hidden', 'is_listed' => false], 'A-2');
    listedStore($elsewhere, ['trade_name' => 'Other mall'], 'B-1');

    $names = collect($this->getJson("/api/v1/public/malls/{$here->code}/stores")->json('data'))->pluck('name');

    expect($names)->toContain('Shown')->not->toContain('Hidden')->not->toContain('Other mall');
})->note('is_listed is a display switch; the allowlist is what keeps paperwork private.');

it('reports a store location only for the mall being browsed', function () {
    // A public endpoint must not let anyone map a chain across the operator's whole portfolio.
    $here = makeAsset();
    $elsewhere = makeAsset();
    $chain = listedStore($here);
    makeLease(makeUnit($elsewhere, ['code' => 'FAR-99']), $chain, ['status' => 'active']);

    $store = $this->getJson("/api/v1/public/malls/{$here->code}/stores")->assertOk()->json('data.0');

    expect($store['locations'])->toContain('L1-24')->not->toContain('FAR-99');
});

// ---------------------------------------------------------------- counters

it('counts a read on the detail screen without touching the search blob', function () {
    $asset = makeAsset();
    $post = livePost($asset);
    $blob = $post->search_text;

    $this->getJson("/api/v1/public/malls/{$asset->code}/posts/{$post->id}")->assertOk();

    $post->refresh();
    expect($post->view_count)->toBe(1)
        // The increment is a builder update precisely so the shopper's read does not re-fold the
        // blob on every view. If this ever changes, the mass-update caveat in HasSearchText applies.
        ->and($post->search_text)->toBe($blob);
});

it('counts a click only for a post that is actually on the feed', function () {
    $asset = makeAsset();
    $live = livePost($asset);
    $over = livePost($asset, ['starts_at' => now()->subMonth(), 'ends_at' => now()->subDay()]);

    $this->postJson("/api/v1/public/malls/{$asset->code}/posts/{$live->id}/click")->assertNoContent();
    $this->postJson("/api/v1/public/malls/{$asset->code}/posts/{$over->id}/click")->assertNotFound();

    expect($live->refresh()->click_count)->toBe(1)
        ->and($over->refresh()->click_count)->toBe(0);
});
