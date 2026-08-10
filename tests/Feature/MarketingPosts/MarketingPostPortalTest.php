<?php

use App\Filament\Portal\Resources\MarketingPosts\MarketingPostResource;
use App\Filament\Portal\Resources\MarketingPosts\Pages\ListMarketingPosts;
use App\Models\MarketingPost;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * /portal — a retailer composing their own offers.
 *
 * The thing under test is the ceiling, not the floor: whatever a retailer does here, the post must
 * end at `pending`. Every capability test is therefore paired with the refusal it implies.
 */
beforeEach(function (): void {
    Storage::fake('public');
    // Without this the row actions resolve against the ADMIN panel and try to build
    // /admin/{tenant}/… URLs for a portal page. Restored afterwards so the panel does not leak
    // into the next file's expectations.
    Filament::setCurrentPanel(Filament::getPanel('portal'));
});

afterEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

/** A retailer trading in the given mall, signed in to the portal. */
function portalActor(App\Models\Asset $asset, bool $isAdmin = true): App\Models\TenantUser
{
    $tenant = makeTenant(['trade_name' => 'Defacto']);
    makeLease(makeUnit($asset), $tenant, ['status' => 'active']);

    $user = makeTenantUser($tenant, $isAdmin);
    test()->actingAs($user, 'portal');

    return $user;
}

function portalPost(App\Models\Asset $asset, int $tenantId, array $attrs = []): MarketingPost
{
    $post = MarketingPost::factory()->create(array_merge([
        'asset_id' => $asset->id,
        'tenant_id' => $tenantId,
        'created_by' => null,
    ], $attrs));

    $post->addMedia(UploadedFile::fake()->image('hero.jpg'))->toMediaCollection(MarketingPost::HERO_COLLECTION);

    return $post->refresh();
}

it('renders the retailer list with rows', function () {
    $asset = makeAsset();
    $user = portalActor($asset);

    portalPost($asset, $user->tenant_id, ['status' => MarketingPost::STATUS_DRAFT]);
    portalPost($asset, $user->tenant_id, ['status' => MarketingPost::STATUS_PENDING]);
    portalPost($asset, $user->tenant_id, [
        'status' => MarketingPost::STATUS_REJECTED,
        'review_notes' => 'Artwork is low-resolution.',
    ]);

    Livewire::test(ListMarketingPosts::class)
        ->assertOk()
        ->assertCanSeeTableRecords(MarketingPost::all())
        // The rejection reason is ON the row — a retailer who has to click to find out why
        // resubmits the same artwork.
        ->assertSee('Artwork is low-resolution.');
});

it('shows a retailer only their own posts', function () {
    $asset = makeAsset();
    $me = portalActor($asset);

    portalPost($asset, $me->tenant_id, ['title' => 'MINE']);

    // A neighbour's post, in the same mall.
    $neighbour = makeTenant();
    makeLease(makeUnit($asset), $neighbour, ['status' => 'active']);
    portalPost($asset, $neighbour->id, ['title' => 'THEIRS']);

    $visible = MarketingPostResource::getEloquentQuery()->pluck('title');

    expect($visible)->toContain('MINE')->not->toContain('THEIRS');
});

it('lets a tenant-admin compose and refuses a read-only login', function () {
    $asset = makeAsset();

    portalActor($asset, isAdmin: true);
    expect(MarketingPostResource::canCreate())->toBeTrue();

    portalActor($asset, isAdmin: false);
    expect(MarketingPostResource::canCreate())->toBeFalse();
});

it('lets a retailer edit a draft or a returned post, and nothing else', function () {
    $asset = makeAsset();
    $user = portalActor($asset);

    $draft = portalPost($asset, $user->tenant_id, ['status' => MarketingPost::STATUS_DRAFT]);
    $returned = portalPost($asset, $user->tenant_id, ['status' => MarketingPost::STATUS_REJECTED]);
    $queued = portalPost($asset, $user->tenant_id, ['status' => MarketingPost::STATUS_PENDING]);
    $live = portalPost($asset, $user->tenant_id, ['status' => MarketingPost::STATUS_PUBLISHED]);

    expect(MarketingPostResource::canEdit($draft))->toBeTrue()
        ->and(MarketingPostResource::canEdit($returned))->toBeTrue()
        // Editing a queued post would mean the mall reviews one thing and publishes another;
        // editing a live one would mean approval never bound at all.
        ->and(MarketingPostResource::canEdit($queued))->toBeFalse()
        ->and(MarketingPostResource::canEdit($live))->toBeFalse();
});

it('lets a retailer bin a draft but never a live offer', function () {
    $asset = makeAsset();
    $user = portalActor($asset);

    $draft = portalPost($asset, $user->tenant_id, ['status' => MarketingPost::STATUS_DRAFT]);
    $live = portalPost($asset, $user->tenant_id, ['status' => MarketingPost::STATUS_PUBLISHED]);

    expect(MarketingPostResource::canDelete($draft))->toBeTrue()
        ->and(MarketingPostResource::canDelete($live))->toBeFalse();
});

it('hides the whole surface when the operator switches the module off', function () {
    $asset = makeAsset();
    portalActor($asset);

    // Control first.
    expect(MarketingPostResource::canViewAny())->toBeTrue();

    $settings = app(App\Settings\ModulesSettings::class);
    $settings->marketing_posts = false;
    $settings->save();

    expect(MarketingPostResource::canViewAny())->toBeFalse()
        ->and(MarketingPostResource::shouldRegisterNavigation())->toBeFalse();
});
