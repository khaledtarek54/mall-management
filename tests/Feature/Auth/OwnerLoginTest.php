<?php

use App\Models\User;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    seedRoles();
    Filament::setCurrentPanel(Filament::getPanel('owner'));
});

afterEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

it('an owner user can authenticate into the owner panel', function () {
    $owner = User::create([
        'name' => 'Jawad Owner',
        'email' => 'owner@jawad.test',
        'password' => bcrypt('password'),
    ]);
    $owner->syncRoles(['owner']);

    Livewire::test(Login::class)
        ->fillForm([
            'email' => 'owner@jawad.test',
            'password' => 'password',
            'remember' => false,
        ])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(auth()->check())->toBeTrue();
    expect(auth()->id())->toBe($owner->id);
});

it('rejects a non-owner from the owner panel login', function () {
    $manager = User::create([
        'name' => 'Ops',
        'email' => 'ops@mall.test',
        'password' => bcrypt('password'),
    ]);
    $manager->syncRoles(['manager']);

    Livewire::test(Login::class)
        ->fillForm([
            'email' => 'ops@mall.test',
            'password' => 'password',
            'remember' => false,
        ])
        ->call('authenticate')
        ->assertHasFormErrors();

    expect(auth()->check())->toBeFalse();
});
