<?php

use App\Filament\Admin\Resources\MarketingPosts\MarketingPostResource;
use App\Filament\Admin\Resources\MarketingPosts\Pages\CreateMarketingPost;
use App\Filament\Admin\Resources\MarketingPosts\Pages\EditMarketingPost;
use App\Models\MarketingPost;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * The marketing-post create/edit form — nothing rendered it before this file.
 *
 * The module shipped with 60 passing tests and **no coverage of the form at all**: the admin tests
 * exercise the list, the actions and the authz predicates, so a misconfigured edit screen was
 * invisible to the suite. It shipped as a five-section, thirty-field scroll while every other major
 * resource here (lease, invoice, tenant, credit note, payment, request) had been converted to tabs
 * — and the operator noticed before the tests did, which is the wrong way round.
 *
 * These render the real page and assert the fields are reachable and a save round-trips, so the next
 * layout change cannot silently drop a field.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

function editablePost(): MarketingPost
{
    $asset = makeAsset();
    Filament::setTenant($asset);

    return MarketingPost::factory()->create([
        'asset_id' => $asset->id,
        'title' => 'Ramadan late-night shopping',
        'type' => MarketingPost::TYPE_NEWS,
        'status' => MarketingPost::STATUS_DRAFT,
    ])->refresh();
}

it('renders the edit form', function () {
    $post = editablePost();

    Livewire::test(EditMarketingPost::class, ['record' => $post->getKey()])
        ->assertOk();
});

it('renders the create form', function () {
    Filament::setTenant(makeAsset());

    Livewire::test(CreateMarketingPost::class)->assertOk();
});

it('keeps every field reachable across the tabs', function () {
    // The failure a tabbed conversion actually causes: a field left outside a tab renders nowhere
    // while the form still loads. One assertion per tab, naming a field only that tab holds.
    $post = editablePost();

    Livewire::test(EditMarketingPost::class, ['record' => $post->getKey()])
        ->assertFormFieldExists('asset_id')        // what
        ->assertFormFieldExists('audience')        // what
        ->assertFormFieldExists('title')           // copy
        ->assertFormFieldExists('terms_ar')        // copy
        ->assertFormFieldExists('hero')            // artwork
        ->assertFormFieldExists('gallery')         // artwork
        ->assertFormFieldExists('starts_at')       // when
        ->assertFormFieldExists('display_until')   // when
        ->assertFormFieldExists('is_featured')     // placement
        ->assertFormFieldExists('cta_url');        // placement
});

it('loads the record into the form and saves an edit', function () {
    $post = editablePost();

    Livewire::test(EditMarketingPost::class, ['record' => $post->getKey()])
        ->assertFormSet(['title' => 'Ramadan late-night shopping'])
        ->fillForm(['title' => 'Ramadan late-night shopping — extended'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($post->fresh()->title)->toBe('Ramadan late-night shopping — extended');
});

it('still refuses to move a post into a property the operator cannot see', function () {
    // The edit page's guard (`mutateFormDataBeforeSave` → `assertAssetInScope`). Filament stamps
    // asset_id on create only, so without it an edit could relocate a post to another mall.
    //
    // Asserted as the PREDICATE, not through `fillForm`. The form field is `disabled()` whenever a
    // property is selected, so a fill cannot move it and a save-based test passes whether or not the
    // guard exists — the same false-pass shape the module's own docblock warns about for actions.
    // The guard is a backstop against a crafted payload, and this is the only way to reach it.
    $post = editablePost();
    $elsewhere = makeAsset();

    // Assigned to the post's OWN property only. Without the assignment a marketing user is
    // unrestricted and every property is in scope, so the refusal could never fire.
    $this->actingAs(makeUser('marketing', [$post->asset_id]));

    // A 403 specifically — `GuardsAssetInScope` calls `abort(403)`.
    expect(fn () => MarketingPostResource::assertAssetInScope($elsewhere->id))
        ->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);

    // The control — the property they ARE assigned to is accepted, so the refusal above is scoping
    // rather than a blanket refusal.
    expect(MarketingPostResource::assertAssetInScope($post->asset_id))->toBeNull();
});
