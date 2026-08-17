<?php

use App\Filament\Admin\Resources\MarketingPosts\MarketingPostResource;
use App\Filament\Admin\Resources\MarketingPosts\Pages\ListMarketingPosts;
use App\Models\MarketingPost;
use App\Services\MarketingPost\ApproveMarketingPostService;
use App\Services\MarketingPost\PublishMarketingPostService;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The admin surface: who may do what, and does the screen actually render.
 *
 * **On proving the authz gates.** Neither `callAction()` nor `mountAction` proves an `action()`
 * gate on the Filament version shipped here — `callAction()` asserts visibility first, and
 * `mountAction` refuses a hidden action, so both go green whether or not `->authorize()` exists.
 * These tests therefore assert the PREDICATE directly (`canApprove()`), which is the thing both
 * `visible()` and `authorize()` call. That is what makes the double gate meaningful rather than
 * decorative. Every refusal below is paired with an authorised control, because a refusal passes
 * just as happily when the dispatch is a no-op.
 */
beforeEach(function (): void {
    $this->seed(RolesPermissionsSeeder::class);
    Storage::fake('public');
});

function adminPost(array $attrs = []): MarketingPost
{
    $asset = $attrs['asset'] ?? makeAsset();
    unset($attrs['asset']);

    $post = MarketingPost::factory()->create(array_merge(['asset_id' => $asset->id], $attrs));
    $post->addMedia(UploadedFile::fake()->image('hero.jpg'))->toMediaCollection(MarketingPost::HERO_COLLECTION);

    return $post->refresh();
}

// ---------------------------------------------------------------- rendering

it('renders the list with rows', function () {
    // With ROWS — an empty table hides every $state-closure bug in the columns, and this table
    // has several (the derived "Showing" badge, the store-name formatter, the status colours).
    $asset = makeAsset();
    $tenant = makeTenant(['trade_name' => 'Defacto']);
    makeLease(makeUnit($asset), $tenant, ['status' => 'active']);

    adminPost(['asset' => $asset, 'tenant_id' => $tenant->id, 'status' => MarketingPost::STATUS_PUBLISHED]);
    adminPost(['asset' => $asset, 'status' => MarketingPost::STATUS_PENDING]);
    adminPost(['asset' => $asset, 'status' => MarketingPost::STATUS_ARCHIVED]);

    $this->actingAs(makeUser('marketing'));
    // The admin panel is tenant-scoped in the URL (/admin/{tenant}/…), so a Livewire test of a
    // resource page must set the panel tenant or every row's Edit link fails to generate.
    Filament::setTenant($asset);

    Livewire::test(ListMarketingPosts::class)
        ->assertOk()
        ->assertCanSeeTableRecords(MarketingPost::all());
});

it('renders every tab, including the ones backed by the live predicate', function () {
    $asset = makeAsset();
    adminPost(['asset' => $asset, 'status' => MarketingPost::STATUS_PUBLISHED]);
    adminPost(['asset' => $asset, 'status' => MarketingPost::STATUS_PENDING]);

    $this->actingAs(makeUser('marketing'));
    Filament::setTenant($asset);

    $component = Livewire::test(ListMarketingPosts::class)->assertOk();

    foreach (['all', 'pending', 'live', 'draft', 'archived'] as $tab) {
        $component->set('activeTab', $tab)->assertOk();
    }
});

it('badges the review queue with the count of waiting submissions', function () {
    $asset = makeAsset();
    adminPost(['asset' => $asset, 'status' => MarketingPost::STATUS_PENDING]);
    adminPost(['asset' => $asset, 'status' => MarketingPost::STATUS_PENDING]);
    adminPost(['asset' => $asset, 'status' => MarketingPost::STATUS_PUBLISHED]);

    $this->actingAs(makeUser('marketing'));

    expect(MarketingPostResource::getNavigationBadge())->toBe('2');
});

// ---------------------------------------------------------------- authorisation

it('lets marketing and manager approve, and refuses everyone else', function () {
    // The predicate both visible() and authorize() call — asserted directly, because neither
    // callAction() nor mountAction can distinguish a gate from its absence here.
    foreach (['marketing', 'manager', 'super_admin'] as $role) {
        $this->actingAs(makeUser($role));
        expect(MarketingPostResource::canApprove())
            ->toBeTrue("{$role} should be able to approve a retailer's post");
    }

    foreach (['viewer', 'leasing', 'accounting', 'hr'] as $role) {
        $this->actingAs(makeUser($role));
        expect(MarketingPostResource::canApprove())
            ->toBeFalse("{$role} must not be able to publish to the mall app");
    }
});

it('separates approving from editing — a viewer sees, an editor edits, only an approver publishes', function () {
    $this->actingAs(makeUser('viewer'));
    expect(MarketingPostResource::canViewAny())->toBeTrue()
        ->and(MarketingPostResource::canCreate())->toBeFalse()
        ->and(MarketingPostResource::canApprove())->toBeFalse();
});

it('refuses delete to everyone except super_admin', function () {
    $post = adminPost();

    $this->actingAs(makeUser('marketing'));
    expect(MarketingPostResource::canDelete($post))->toBeFalse();

    // Control: it IS deletable by the platform owner, so the refusal above is the role and not a
    // DeletionPolicy NEVER classification.
    $this->actingAs(makeUser('super_admin'));
    expect(MarketingPostResource::canDelete($post))->toBeTrue();
});

it('keeps bulk delete off', function () {
    $this->actingAs(makeUser('super_admin'));

    expect(MarketingPostResource::canDeleteAny())->toBeFalse();
});

// ---------------------------------------------------------------- property isolation

it('shows an operator only the malls they can see', function () {
    $mine = makeAsset();
    $theirs = makeAsset();
    adminPost(['asset' => $mine, 'title' => 'MINE']);
    adminPost(['asset' => $theirs, 'title' => 'THEIRS']);

    // A user assigned to one property only.
    $this->actingAs(makeUser('marketing', [$mine->id]));

    $visible = MarketingPostResource::getEloquentQuery()->pluck('title');

    expect($visible)->toContain('MINE')->not->toContain('THEIRS');
});

it('refuses a write into a property outside the user visible set', function () {
    $mine = makeAsset();
    $theirs = makeAsset();

    $this->actingAs(makeUser('marketing', [$mine->id]));

    // The control first: their own property passes.
    MarketingPostResource::assertAssetInScope($mine->id);

    expect(fn () => MarketingPostResource::assertAssetInScope($theirs->id))
        ->toThrow(HttpException::class);
});

// ---------------------------------------------------------------- the workflow, end to end

it('takes a retailer submission from queue to live feed', function () {
    $asset = makeAsset();
    $tenant = makeTenant(['trade_name' => 'Defacto', 'is_listed' => true]);
    makeLease(makeUnit($asset), $tenant, ['status' => 'active']);

    // Retailer-authored: created_by null is what marks it theirs.
    $post = adminPost([
        'asset' => $asset,
        'tenant_id' => $tenant->id,
        'status' => MarketingPost::STATUS_PENDING,
        'created_by' => null,
        'title' => 'Defacto 20% off',
    ]);

    // Not on the shopper feed while it waits.
    $this->getJson("/api/v1/public/malls/{$asset->code}/posts")
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $reviewer = makeUser('marketing');
    app(ApproveMarketingPostService::class)->handle($post, $reviewer);

    // And now it is — through the real public endpoint, not a model assertion.
    $this->getJson("/api/v1/public/malls/{$asset->code}/posts")
        ->assertOk()
        ->assertJsonPath('data.0.title', 'Defacto 20% off')
        ->assertJsonPath('data.0.store.name', 'Defacto');
});

it('takes an operator-composed mall-wide post live without a queue step', function () {
    $asset = makeAsset();
    $post = adminPost([
        'asset' => $asset,
        'tenant_id' => null,
        'created_by' => makeUser('marketing')->id,
        'title' => 'Late-night shopping every Thursday',
        'type' => MarketingPost::TYPE_NEWS,
    ]);

    app(PublishMarketingPostService::class)->handle($post, makeUser('marketing'));

    $this->getJson("/api/v1/public/malls/{$asset->code}/posts")
        ->assertOk()
        ->assertJsonPath('data.0.title', 'Late-night shopping every Thursday')
        // Mall-wide: no store on the card, and that is a rendered null rather than a missing key.
        ->assertJsonPath('data.0.store', null);
});
