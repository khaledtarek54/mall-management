<?php

use App\Filament\Admin\Resources\Tenants\Pages\CreateTenant;
use App\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use App\Filament\Admin\Resources\Tenants\RelationManagers\DocumentsRelationManager;
use App\Models\Tenant;
use App\Support\ScriptCheck;
use App\Support\WebAddress;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

/**
 * Three cards from the tester's board, all about a field refusing or accepting the wrong thing.
 *
 * **The Website field refused `www.site.com`.** `->url()` maps to Laravel's `url` rule, which
 * requires a scheme, so the form accepted `https://zara.com` and refused the way a retailer actually
 * writes their address on a card. Normalised rather than refused: every browser and CRM supplies the
 * scheme itself.
 *
 * **The shopper-facing name accepted either language in either box.** Those two fields feed the
 * visitor app, so an Arabic-speaking shopper could be shown a Latin-only name — the one outcome the
 * pair exists to prevent.
 *
 * **A document's "Issued on" accepted a future date.** An issue date is when an authority actually
 * issued the paper; the compliance clock built on it would otherwise measure from a day that has not
 * happened.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $this->actingAs(makeUser('super_admin'));
    $this->asset = makeAsset();
});

it('accepts a web address written the way people write one, and stores it canonically', function () {
    asTenant($this->asset, function () {
        Livewire::test(CreateTenant::class)
            ->fillForm([
                'name' => 'Zara Egypt',
                // An individual, so the tax-address block a COMPANY requires is out of the way —
                // this case is about the website field, not about the address rules.
                'type' => 'individual',
                'status' => 'active',
                'email' => 'zara@example.test',
                'website_url' => 'www.zara.com',
            ])
            ->call('create')
            ->assertHasNoFormErrors(['website_url']);
    });

    // Normalised on the way in, so everything downstream — the visitor app, a PDF, a mail — gets a
    // link that works rather than a string a browser has to guess about.
    expect(Tenant::where('email', 'zara@example.test')->sole()->website_url)->toBe('https://www.zara.com');
});

it('leaves an address that already carries a scheme exactly as typed', function () {
    // The control on the normalisation: it must SUPPLY a missing scheme, never rewrite a present one
    // — an operator who deliberately typed http:// for an internal host keeps it.
    expect(WebAddress::normalise('https://zara.com'))->toBe('https://zara.com')
        ->and(WebAddress::normalise('http://intranet.local'))->toBe('http://intranet.local')
        ->and(WebAddress::normalise('zara.com'))->toBe('https://zara.com')
        ->and(WebAddress::normalise('  '))->toBeNull();
});

it('still refuses something that is not a web address at all', function () {
    // The control. Widening the INPUT must not widen the MEANING.
    asTenant($this->asset, function () {
        Livewire::test(CreateTenant::class)
            ->fillForm([
                'name' => 'Nonsense',
                'email' => 'nonsense@example.test',
                'website_url' => 'not a website',
            ])
            ->call('create')
            ->assertHasFormErrors(['website_url']);
    });
});

it('refuses a Latin-only name in the Arabic shopper field', function () {
    asTenant($this->asset, function () {
        Livewire::test(CreateTenant::class)
            ->fillForm([
                'name' => 'Zara Egypt',
                'email' => 'zara@example.test',
                'trade_name_ar' => 'ZARA',
            ])
            ->call('create')
            ->assertHasFormErrors(['trade_name_ar']);
    });
});

it('accepts a MIXED name in either box, which is what a real register looks like', function () {
    // The control that matters most. Demanding a single script would refuse most of an Egyptian
    // mall's tenant list — «زارا ZARA», «H&M مصر», «كارفور Carrefour» — and be a worse bug than the
    // one being fixed. The rule asks for PRESENCE, never purity.
    asTenant($this->asset, function () {
        Livewire::test(CreateTenant::class)
            ->fillForm([
                'name' => 'Zara Egypt',
                'email' => 'zara@example.test',
                'trade_name' => 'ZARA زارا',
                'trade_name_ar' => 'زارا ZARA',
            ])
            ->call('create')
            ->assertHasNoFormErrors(['trade_name', 'trade_name_ar']);
    });
});

it('says nothing about a name that carries no letters at all', function () {
    // A store called "700" is a name somebody chose. Neither check should fire on it, or the rule
    // stops catching a wrong box and starts inventing a policy about naming.
    expect(ScriptCheck::carriesNoLetters('700'))->toBeTrue()
        ->and(ScriptCheck::hasArabic('زارا'))->toBeTrue()
        ->and(ScriptCheck::hasLatin('ZARA'))->toBeTrue()
        ->and(ScriptCheck::hasArabic('ZARA'))->toBeFalse()
        ->and(ScriptCheck::hasLatin('زارا'))->toBeFalse();

    asTenant($this->asset, function () {
        Livewire::test(CreateTenant::class)
            ->fillForm([
                'name' => 'Seven Hundred',
                'email' => 'seven@example.test',
                'trade_name' => '700',
                'trade_name_ar' => '700',
            ])
            ->call('create')
            ->assertHasNoFormErrors(['trade_name', 'trade_name_ar']);
    });
});

it('refuses a document issued in the future, on both registers', function () {
    $tenant = makeTenant();

    Livewire::test(DocumentsRelationManager::class, [
        'ownerRecord' => $tenant,
        'pageClass' => EditTenant::class,
    ])
        ->callAction(TestAction::make('create')->table(), data: [
            'type' => 'commercial_register',
            'issued_on' => now()->addWeek()->toDateString(),
        ])
        ->assertHasActionErrors(['issued_on']);
});

it('still accepts a document issued today', function () {
    // The boundary AND the control: a paper issued this morning is the ordinary case, so the ceiling
    // must be inclusive of today rather than "strictly before".
    $tenant = makeTenant();

    Livewire::test(DocumentsRelationManager::class, [
        'ownerRecord' => $tenant,
        'pageClass' => EditTenant::class,
    ])
        ->callAction(TestAction::make('create')->table(), data: [
            'type' => 'commercial_register',
            'issued_on' => now()->toDateString(),
        ])
        ->assertHasNoActionErrors();
});
