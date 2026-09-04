<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| A marketing post survives its retailer's lease ending (SW-179)
|--------------------------------------------------------------------------
| The store picker narrowed with `->modifyOptionsQuery()`, and that narrows what can be FOUND as
| well as what is SHOWN: `EntitySelect` resolves a stored value's LABEL through the same modifier,
| and Filament turns "cannot label" into `Rule::in([])`. So the day the attributed retailer's lease
| ended, every save of that post was refused as *invalid* on a field nobody had touched — the
| picker rendering empty beside the error, because the label would not resolve either.
|
| `->suggest()` is the seam this codebase already built for the distinction: it narrows the browse
| list only. What you SEE and what you can FIND are different questions, and collapsing them breaks
| the record that already exists.
*/

use App\Filament\Admin\Resources\MarketingPosts\Pages\CreateMarketingPost;
use App\Filament\Admin\Resources\MarketingPosts\Pages\EditMarketingPost;
use App\Models\MarketingPost;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->asset = makeAsset(['code' => 'MKT']);
    Filament::setTenant($this->asset);

    $this->trading = makeTenant(['name' => 'Cilantro Coffee']);
    $this->lease = makeLease(makeUnit($this->asset), $this->trading, ['status' => 'active']);

    // A retailer who has never traded in this mall — the other half of the picker's question.
    $this->stranger = makeTenant(['name' => 'Zooba Kitchen']);

    $this->post = MarketingPost::factory()->create([
        'asset_id' => $this->asset->id,
        'tenant_id' => $this->trading->id,
        'title' => 'Buy one get one',
        'status' => MarketingPost::STATUS_DRAFT,
    ])->refresh();
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

function marketingStorePicker(): Select
{
    return Livewire::test(CreateMarketingPost::class)
        ->instance()
        ->getSchema('form')
        ->getFlatFields()['tenant_id'];
}

it('opens on the retailers trading in this mall', function (): void {
    // The half that must NOT be lost: a suggestion is still a suggestion.
    $options = array_keys(marketingStorePicker()->getOptions());

    expect($options)->toContain($this->trading->getKey())
        ->and($options)->not->toContain($this->stranger->getKey());
});

it('reaches a retailer the browse list does not show, by searching', function (): void {
    // `->suggest()` narrows what is SHOWN. Search runs on the modifier alone, so the rest of the
    // register is one search away — the difference between a suggestion and a filter.
    $results = marketingStorePicker()->getSearchResults('Zooba');

    expect(array_keys($results))->toContain($this->stranger->getKey());
});

it('still LABELS a retailer who has stopped trading here', function (): void {
    // The label lookup IS the validation gate: Filament resolves a Select's stored value by asking
    // the picker to label it, and refuses with `Rule::in([])` when it cannot.
    $this->lease->update(['status' => 'expired']);   // what `leases:expire` writes, nightly

    $picker = marketingStorePicker();
    $picker->state($this->trading->getKey());

    expect($picker->getOptionLabel())->not->toBeNull();
});

it('saves an edit of a post whose retailer has left the mall', function (): void {
    // The control FIRST, so the assertion after it is about the lease ending and not about the
    // Edit page having been broken all along.
    Livewire::test(EditMarketingPost::class, ['record' => $this->post->getKey()])
        ->fillForm(['title' => 'Buy one get one — control'])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($this->post->fresh()->title)->toBe('Buy one get one — control');

    $this->lease->update(['status' => 'expired']);

    Livewire::test(EditMarketingPost::class, ['record' => $this->post->getKey()])
        ->fillForm(['title' => 'Buy one get one — extended'])
        ->call('save')
        ->assertHasNoFormErrors();

    // The attribution survives the save rather than being silently cleared to get past the
    // refusal, which was the only workaround the operator had.
    expect($this->post->fresh()->title)->toBe('Buy one get one — extended')
        ->and($this->post->fresh()->tenant_id)->toBe($this->trading->getKey());
});
