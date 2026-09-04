<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| The register's "Live" column answers the question the filter answers (SW-180)
|--------------------------------------------------------------------------
| `MarketingPost::liveFor()` is the ONE predicate — its own docblock says so and names the five
| consumers. The admin register's "Live" COLUMN was not one of them: it composed `isPublished() &&
| ! hasExpired()` plus a display-window check and dropped the STORE clause entirely, so a post kept
| reading Live after its retailer's lease ended, after the retailer was unlisted and after they
| were set inactive — beside a "Live" tab and a "Live" filter, on the same screen, that both go
| through the scope and said otherwise.
|
| The colour was a second, quieter half of the same defect: it tested only `isPublished() &&
| ! hasExpired()`, so a published post waiting for its display window rendered a GREEN badge
| reading "No".
*/

use App\Filament\Admin\Resources\MarketingPosts\Pages\ListMarketingPosts;
use App\Models\MarketingPost;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->asset = makeAsset(['code' => 'LIV']);
    Filament::setTenant($this->asset);

    $this->trading = makeTenant(['name' => 'Cilantro Coffee']);
    $this->lease = makeLease(makeUnit($this->asset), $this->trading, ['status' => 'active']);

    $this->stranger = makeTenant(['name' => 'Zooba Kitchen']);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** What the operator actually reads in the Live column: the word and the colour. */
function marketingLiveBadge(MarketingPost $post): array
{
    $column = Livewire::test(ListMarketingPosts::class)
        ->instance()
        ->getTable()
        ->getColumn('live')
        ->record($post->fresh());

    $state = $column->getState();

    return [
        'text' => (string) $column->formatState($state),
        'color' => $column->getColor($state),
    ];
}

it('says No once the attributed retailer stops trading in this mall', function (): void {
    $post = MarketingPost::factory()->published()->create([
        'asset_id' => $this->asset->id,
        'tenant_id' => $this->trading->id,
    ]);

    // The control: while the retailer is trading, all three readings agree that it is live.
    expect(MarketingPost::query()->liveFor(null)->pluck('id')->all())->toContain($post->id)
        ->and($post->isLiveNow())->toBeTrue()
        ->and(marketingLiveBadge($post))->toBe([
            'text' => __('admin.marketing_posts.live_yes'),
            'color' => 'success',
        ]);

    // The lease runs out — what `leases:expire` writes, nightly, on a day when nothing happened.
    $this->lease->update(['status' => 'expired']);

    expect(MarketingPost::query()->liveFor(null)->pluck('id')->all())->not->toContain($post->id)
        ->and($post->fresh()->isLiveNow())->toBeFalse()
        ->and(marketingLiveBadge($post))->toBe([
            'text' => __('admin.marketing_posts.live_no'),
            'color' => 'gray',
        ]);
});

it('does not paint a post green while the badge beside it reads No', function (): void {
    // A published, mall-wide post whose display window has not opened yet. The badge TEXT read the
    // display window and the COLOUR did not, so the two disagreed on the same cell.
    $post = MarketingPost::factory()->published()->create([
        'asset_id' => $this->asset->id,
        'tenant_id' => null,
        'display_from' => now()->addWeek(),
    ]);

    expect(MarketingPost::query()->liveFor(null)->pluck('id')->all())->not->toContain($post->id)
        ->and(marketingLiveBadge($post))->toBe([
            'text' => __('admin.marketing_posts.live_no'),
            'color' => 'gray',
        ]);
});

it('answers exactly what the scope answers, row for row', function (): void {
    // Six rows covering every reason a post is or is not on screen: live with a store, live
    // mall-wide, not yet displayed, past its window, never published, and — the clause the column
    // used to drop — published against a retailer who has never traded in this mall.
    MarketingPost::factory()->published()->create(['asset_id' => $this->asset->id, 'tenant_id' => $this->trading->id]);
    MarketingPost::factory()->published()->create(['asset_id' => $this->asset->id, 'tenant_id' => null]);
    MarketingPost::factory()->published()->create(['asset_id' => $this->asset->id, 'tenant_id' => null, 'display_from' => now()->addWeek()]);
    MarketingPost::factory()->expired()->create(['asset_id' => $this->asset->id, 'tenant_id' => null]);
    MarketingPost::factory()->create(['asset_id' => $this->asset->id, 'tenant_id' => $this->trading->id]);
    MarketingPost::factory()->published()->create(['asset_id' => $this->asset->id, 'tenant_id' => $this->stranger->id]);

    $live = MarketingPost::query()->liveFor(null)->pluck('id')->all();

    // The premise: the fixture has to contain BOTH answers, or "they agree" is vacuous.
    expect($live)->not->toBeEmpty()
        ->and(count($live))->toBeLessThan(MarketingPost::query()->count());

    foreach (MarketingPost::all() as $post) {
        expect($post->isLiveNow())->toBe(
            in_array($post->id, $live, true),
            "post {$post->id} ({$post->status}) disagrees with liveFor()",
        );
    }
});
