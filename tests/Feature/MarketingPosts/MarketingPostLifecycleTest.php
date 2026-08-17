<?php

use App\Models\Asset;
use App\Models\MarketingPost;
use App\Models\Tenant;
use App\Services\MarketingPost\ApproveMarketingPostService;
use App\Services\MarketingPost\ArchiveMarketingPostService;
use App\Services\MarketingPost\PublishMarketingPostService;
use App\Services\MarketingPost\RejectMarketingPostService;
use App\Services\MarketingPost\SubmitMarketingPostService;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The state machine and the one predicate. Everything a shopper ever sees is decided here.
 *
 * These are SERVICE tests — no permission is consulted by any of them. The seeder runs only
 * because `makeUser()` assigns a role that has to exist; RBAC on the same transitions is proved
 * separately, in MarketingPostAuthzTest.
 */
beforeEach(function (): void {
    $this->seed(RolesPermissionsSeeder::class);
});

/** Give a post the artwork publication requires, without touching a real filesystem. */
function attachHero(MarketingPost $post): MarketingPost
{
    Storage::fake('public');
    $post->addMedia(UploadedFile::fake()->image('hero.jpg', 1200, 675))
        ->toMediaCollection(MarketingPost::HERO_COLLECTION);

    return $post->refresh();
}

/** A tenant actually trading in the given property — what SubmitMarketingPostService demands. */
function makeTradingTenant(Asset $asset): Tenant
{
    $tenant = makeTenant();
    makeLease(makeUnit($asset), $tenant, ['status' => 'active']);

    return $tenant;
}

it('publishes a draft and stamps who reviewed it', function () {
    $asset = makeAsset();
    $post = attachHero(MarketingPost::factory()->create(['asset_id' => $asset->id]));
    $user = makeUser('marketing');

    $published = app(PublishMarketingPostService::class)->handle($post, $user);

    expect($published->status)->toBe(MarketingPost::STATUS_PUBLISHED)
        ->and($published->published_at)->not->toBeNull()
        ->and($published->reviewed_by)->toBe($user->id);
});

it('is idempotent — re-publishing does not move published_at', function () {
    $asset = makeAsset();
    $post = attachHero(MarketingPost::factory()->create(['asset_id' => $asset->id]));
    $service = app(PublishMarketingPostService::class);

    $first = $service->handle($post, makeUser('marketing'));
    $stamp = $first->published_at;

    $second = $service->handle($first->refresh(), makeUser('marketing'));

    expect($second->published_at->eq($stamp))->toBeTrue();
});

it('refuses to publish a post whose window has already closed', function () {
    $post = attachHero(MarketingPost::factory()->create([
        'asset_id' => makeAsset()->id,
        'starts_at' => now()->subMonth(),
        'ends_at' => now()->subDay(),
    ]));

    expect(fn () => app(PublishMarketingPostService::class)->handle($post, makeUser('marketing')))
        ->toThrow(DomainException::class);
});

it('refuses to publish shopper-facing content with no artwork', function () {
    $post = MarketingPost::factory()->create(['asset_id' => makeAsset()->id]);

    expect(fn () => app(PublishMarketingPostService::class)->handle($post, makeUser('marketing')))
        ->toThrow(DomainException::class);
})->note('A feed row with no hero renders as a grey box in every mall app there is.');

it('lets a tenants-only notice publish without artwork', function () {
    // The control for the test above — a refusal test passes just as happily when the thing is
    // refused for the wrong reason, or always.
    $post = MarketingPost::factory()->create([
        'asset_id' => makeAsset()->id,
        'audience' => MarketingPost::AUDIENCE_TENANTS,
    ]);

    $published = app(PublishMarketingPostService::class)->handle($post, makeUser('marketing'));

    expect($published->status)->toBe(MarketingPost::STATUS_PUBLISHED);
});

it('refuses to publish a backwards validity window', function () {
    $post = attachHero(MarketingPost::factory()->create([
        'asset_id' => makeAsset()->id,
        'starts_at' => now()->addWeek(),
        'ends_at' => now()->addDays(2),
    ]));

    expect(fn () => app(PublishMarketingPostService::class)->handle($post, makeUser('marketing')))
        ->toThrow(DomainException::class);
});

// ---------------------------------------------------------------- tenant submission

it('moves a tenant draft into the review queue, never straight to published', function () {
    $asset = makeAsset();
    $tenant = makeTradingTenant($asset);
    $tenantUser = makeTenantUser($tenant);
    $post = MarketingPost::factory()->create(['asset_id' => $asset->id, 'tenant_id' => $tenant->id]);

    $submitted = app(SubmitMarketingPostService::class)->handle($post, $tenantUser);

    expect($submitted->status)->toBe(MarketingPost::STATUS_PENDING)
        ->and($submitted->submitted_by_tenant_user_id)->toBe($tenantUser->id);
});

it('refuses a submission into a property the tenant does not trade in', function () {
    $home = makeAsset();
    $elsewhere = makeAsset();
    $tenant = makeTradingTenant($home);
    // The tampering this guard exists for: a client-supplied asset_id pointing at another mall.
    $post = MarketingPost::factory()->create(['asset_id' => $elsewhere->id, 'tenant_id' => $tenant->id]);

    expect(fn () => app(SubmitMarketingPostService::class)->handle($post, makeTenantUser($tenant)))
        ->toThrow(DomainException::class);
});

it('finds the tenant through the lease_unit pivot, not just the master unit', function () {
    // The trap the announcement fan-out documents: a retailer whose presence in THIS mall is an
    // additional unit on a multi-unit lease would be wrongly refused by a leases.unit_id check.
    $home = makeAsset();
    $second = makeAsset();
    $tenant = makeTenant();
    $lease = makeLease(makeUnit($home), $tenant, ['status' => 'active']);
    $lease->units()->attach(makeUnit($second)->id, ['is_master' => false]);

    $post = MarketingPost::factory()->create(['asset_id' => $second->id, 'tenant_id' => $tenant->id]);

    $submitted = app(SubmitMarketingPostService::class)->handle($post, makeTenantUser($tenant));

    expect($submitted->status)->toBe(MarketingPost::STATUS_PENDING);
});

it('refuses to submit a post the tenant no longer controls', function () {
    $asset = makeAsset();
    $tenant = makeTradingTenant($asset);
    $post = MarketingPost::factory()->published()->create([
        'asset_id' => $asset->id,
        'tenant_id' => $tenant->id,
    ]);

    expect(fn () => app(SubmitMarketingPostService::class)->handle($post, makeTenantUser($tenant)))
        ->toThrow(DomainException::class);
});

// ---------------------------------------------------------------- review

it('approves a pending post through the same publish checks', function () {
    $asset = makeAsset();
    $tenant = makeTradingTenant($asset);
    $post = attachHero(MarketingPost::factory()->pending()->create([
        'asset_id' => $asset->id,
        'tenant_id' => $tenant->id,
    ]));

    $approved = app(ApproveMarketingPostService::class)->handle($post, makeUser('marketing'));

    expect($approved->status)->toBe(MarketingPost::STATUS_PUBLISHED);
});

it('refuses to approve anything that is not pending', function () {
    $post = attachHero(MarketingPost::factory()->create(['asset_id' => makeAsset()->id]));

    expect(fn () => app(ApproveMarketingPostService::class)->handle($post, makeUser('marketing')))
        ->toThrow(DomainException::class);
});

it('demands a reason when rejecting, and hands the post back to the tenant', function () {
    $asset = makeAsset();
    $tenant = makeTradingTenant($asset);
    $post = MarketingPost::factory()->pending()->create([
        'asset_id' => $asset->id,
        'tenant_id' => $tenant->id,
    ]);
    $reviewer = makeUser('marketing');

    expect(fn () => app(RejectMarketingPostService::class)->handle($post, $reviewer, '   '))
        ->toThrow(DomainException::class);

    $rejected = app(RejectMarketingPostService::class)->handle($post, $reviewer, 'Artwork is low-resolution.');

    expect($rejected->status)->toBe(MarketingPost::STATUS_REJECTED)
        ->and($rejected->review_notes)->toBe('Artwork is low-resolution.')
        ->and($rejected->isEditableByTenant())->toBeTrue();
});

it('clears a stale rejection reason when the post is later published', function () {
    // Otherwise the portal shows a live offer alongside the reason it was once refused.
    $asset = makeAsset();
    $tenant = makeTradingTenant($asset);
    $post = attachHero(MarketingPost::factory()->pending()->create([
        'asset_id' => $asset->id,
        'tenant_id' => $tenant->id,
        'review_notes' => 'Artwork is low-resolution.',
    ]));

    $published = app(ApproveMarketingPostService::class)->handle($post, makeUser('marketing'));

    expect($published->review_notes)->toBeNull();
});

// ---------------------------------------------------------------- archive

it('refuses to archive a submission still waiting on a verdict', function () {
    $post = MarketingPost::factory()->pending()->create(['asset_id' => makeAsset()->id]);

    expect(fn () => app(ArchiveMarketingPostService::class)->handle($post, makeUser('marketing')))
        ->toThrow(DomainException::class);
});

it('archives a live post and keeps its engagement counters', function () {
    $post = MarketingPost::factory()->published()->create([
        'asset_id' => makeAsset()->id,
        'view_count' => 412,
        'click_count' => 37,
    ]);

    $archived = app(ArchiveMarketingPostService::class)->handle($post, makeUser('marketing'));

    expect($archived->status)->toBe(MarketingPost::STATUS_ARCHIVED)
        ->and($archived->view_count)->toBe(412)
        ->and($archived->click_count)->toBe(37);
});
