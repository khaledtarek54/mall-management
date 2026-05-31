<?php

use App\Filament\Owner\Resources\Properties\Pages\ViewProperty;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    seedRoles();
    ensureAllPropertiesAsset();

    $this->asset = makeAsset(['code' => 'HW', 'name' => 'Heliopolis West']);
    $this->owner = makeUser('owner');
    $this->asset->owners()->attach($this->owner->id, [
        'ownership_percentage' => 100,
        'started_at' => now(),
    ]);

    // One occupied unit + lease + invoice so the statement has real data
    $unit = makeUnit($this->asset, ['status' => 'occupied']);
    $tenant = makeTenant(['name' => 'Café Crema']);
    $lease = makeLease($unit, $tenant);
    makeInvoice($lease, ['balance' => 1000, 'status' => 'issued', 'due_date' => now()->subDays(5)]);
});

it('renders the View Property page with the statement download action', function () {
    $response = $this->actingAs($this->owner)
        ->get("/owner/properties/{$this->asset->id}");

    $response->assertOk();
    expect($response->getContent())->toContain('Property Statement');
});

it('streams a real PDF when the owner triggers the statement action', function () {
    // Filament defaults to the `admin` panel during tests; ViewProperty lives
    // in the owner panel so its URL helpers (resources.properties.index, etc)
    // only resolve once we tell Filament which panel is current.
    Filament::setCurrentPanel(Filament::getPanel('owner'));

    $component = Livewire::actingAs($this->owner)
        ->test(ViewProperty::class, ['record' => $this->asset->id])
        ->callAction('statement');

    $download = $component->effects['download'] ?? null;

    expect($download)->not->toBeNull();
    expect($download['name'])->toStartWith('Property-Statement-hw-');

    // Livewire base64-encodes streamed downloads — decode and verify it's a
    // real PDF, not just a placeholder.
    $bytes = base64_decode($download['content']);
    expect(substr($bytes, 0, 5))->toBe('%PDF-');
    expect(strlen($bytes))->toBeGreaterThan(2000);
});

it('hides the property from an owner who does not own it', function () {
    $other = makeUser('owner');

    // Owner-panel scoping filters non-owned assets out of the query entirely,
    // so route-model binding 404s rather than 403s. Both are equivalent
    // "you can't see this" behaviours; 404 is what Filament gives us.
    $this->actingAs($other)
        ->get("/owner/properties/{$this->asset->id}")
        ->assertNotFound();
});
