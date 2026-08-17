<?php

use App\Enums\PartyType;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\UnitOwnership;
use App\Models\Vendor;
use App\Support\Filament\EntitySelect;
use App\Support\Search\OptionDisplay;
use App\Support\Search\RecordOption;

/**
 * **A dropdown searches everything the record is known by, and shows enough to tell two apart.**
 *
 * THE BUG. Every entity picker in the panel was `->relationship('tenant', 'name')->searchable()`.
 * That searches ONE raw column, unfolded, and renders that same column as the whole option. So:
 *
 *   - Typing a tenant's phone number found nothing, though the phone has been in the record's
 *     `search_text` blob (and findable from the top search bar) since the blob existed.
 *   - Typing «شركه» did not find «شركة» — one hamza-family character apart, the same word to every
 *     Egyptian who types it, and the entire reason `SearchText` exists.
 *   - A mall with «Zara», «Zara Home» and «Zara Kids» offered three identical-looking rows.
 *
 * Every one of those renders as an empty or ambiguous dropdown, never an error — so it is never
 * reported, it is worked around by leaving the form.
 *
 * These tests drive `EntitySelect` and `OptionDisplay` directly rather than mounting a form,
 * because the property being pinned is what the picker MATCHES and SHOWS. A form test would prove
 * the page renders (which `AdminPageSmokeTest` already does) and would go green whether or not the
 * fold, the scope or the subtitle were there.
 */
beforeEach(function () {
    $this->asset = makeAsset(['code' => 'AW', 'name' => 'Atriom Walk']);
    $this->other = makeAsset(['code' => 'CP', 'name' => 'Cairo Plaza']);
    $this->actingAs(makeUser('super_admin'));
});

/** The options a picker would show for a query, as plain text. */
function pickerText(string $model, string $query, ?Closure $narrow = null): array
{
    return array_map(
        fn (array $entry): string => $entry[0]->toText(),
        OptionDisplay::search($model, $query, $narrow),
    );
}

it('finds a tenant by phone, tax id and code — not only by name', function () {
    $zara = makeTenant([
        'name' => 'Zara Egypt',
        'phone' => '+20 100 123 4567',
        'tax_id' => '512345678',
    ]);

    // Typed the way it is read off a screen: one unbroken run of digits, no punctuation. The
    // stored value has spaces and a `+`, so an unfolded `LIKE` on the raw column matches nothing.
    expect(pickerText(Tenant::class, '01001234567'))->toHaveCount(1)
        ->and(pickerText(Tenant::class, '512345678'))->toHaveCount(1)
        ->and(pickerText(Tenant::class, $zara->code))->toHaveCount(1)
        // …and with the dashes the operator did not type.
        ->and(pickerText(Tenant::class, str_replace('-', '', $zara->code)))->toHaveCount(1)
        // The control: the name still works. A scope that had broken everything would satisfy the
        // assertions above by returning nothing, and read as a pass.
        ->and(pickerText(Tenant::class, 'zara'))->toHaveCount(1);
});

it('folds the Arabic spelling both sides of the comparison', function () {
    makeTenant(['name' => 'شركة الفتح للتجارة']);

    // Teh marbuta vs heh — one character, the same word, and the difference between finding the
    // tenant and swearing they are not in the system.
    expect(pickerText(Tenant::class, 'شركه الفتح'))->toHaveCount(1)
        ->and(pickerText(Tenant::class, 'شركة'))->toHaveCount(1);
});

it('shows enough on an option to tell two same-named tenants apart', function () {
    $unitA = makeUnit($this->asset, ['code' => 'A-114']);
    $unitB = makeUnit($this->asset, ['code' => 'B-207']);

    $home = makeTenant(['name' => 'Zara', 'phone' => '0100 111 2222']);
    $kids = makeTenant(['name' => 'Zara', 'phone' => '0100 333 4444']);
    makeLease($unitA, $home, ['status' => 'active']);
    makeLease($unitB, $kids, ['status' => 'active']);

    $options = pickerText(Tenant::class, 'zara');

    expect($options)->toHaveCount(2);

    $joined = implode(' || ', $options);

    // The unit is what an operator actually knows about the one they mean.
    expect($joined)->toContain('A-114')
        ->toContain('B-207')
        ->toContain('0100 111 2222')
        // …and the code, which is the thing they can read down a phone line.
        ->toContain($home->code)
        ->toContain($kids->code);
});

it('escapes every operator-typed value in the option markup', function () {
    // `allowHtml()` hands the label to Filament's `{!! !!}` and to the browser as innerHTML. A
    // tenant name is operator-typed, so an unescaped label is stored XSS reachable from every form
    // that lists the record — and `RecordOption::toHtml()` is the ONLY place that builds it.
    $tenant = makeTenant(['name' => '<script>alert(1)</script>']);

    $html = OptionDisplay::for($tenant->fresh())->toHtml();

    expect($html)->not->toContain('<script>')
        ->toContain('&lt;script&gt;')
        // Still an option, not a blank row.
        ->toContain('atriom-option-title');
});

it('offers a unit OWNER who holds no lease at all', function () {
    // The bug the invoice form shipped with: it scoped the tenant picker on `leases.unit` alone,
    // so a module-37 buyer — who has an ownership and no lease — could be invoiced by the billing
    // services and never picked on the form that raises the invoice.
    $unit = makeUnit($this->asset, ['code' => 'C-500']);
    $owner = makeTenant(['name' => 'Owner Holdings', 'party_type' => PartyType::UnitOwner->value]);

    UnitOwnership::create([
        'asset_id' => $this->asset->id,
        'unit_id' => $unit->id,
        'tenant_id' => $owner->id,
        'status' => 'handed_over',
        'ownership_share_pct' => 100,
        'started_at' => now()->subYear(),
    ]);

    $restricted = makeUser('manager', [$this->asset->id]);
    $this->actingAs($restricted);

    asTenant($this->asset, function () use ($owner) {
        expect(pickerText(Tenant::class, 'owner holdings'))->toHaveCount(1)
            ->and(implode('', pickerText(Tenant::class, 'owner holdings')))->toContain($owner->name);
    });
});

it('does not offer a tenant whose only affiliation is another property', function () {
    $mine = makeTenant(['name' => 'Local Retailer']);
    makeLease(makeUnit($this->asset, ['code' => 'A-1']), $mine, ['status' => 'active']);

    $theirs = makeTenant(['name' => 'Distant Retailer']);
    makeLease(makeUnit($this->other, ['code' => 'Z-9']), $theirs, ['status' => 'active']);

    // A tenant with no LEASE but an OWNERSHIP in the other mall. The old
    // `orWhereDoesntHave('leases')` branch offered exactly this one to every property in the
    // portfolio — a cross-property leak invisible until a party happened to be both.
    $absentee = makeTenant(['name' => 'Distant Owner', 'party_type' => PartyType::UnitOwner->value]);
    UnitOwnership::create([
        'asset_id' => $this->other->id,
        'unit_id' => makeUnit($this->other, ['code' => 'Z-8'])->id,
        'tenant_id' => $absentee->id,
        'status' => 'handed_over',
        'ownership_share_pct' => 100,
        'started_at' => now()->subYear(),
    ]);

    $restricted = makeUser('manager', [$this->asset->id]);
    $this->actingAs($restricted);

    asTenant($this->asset, function () {
        // Every refusal is paired with a control that must succeed — a scope that returned nothing
        // at all would satisfy the refusals alone and read as a pass.
        expect(pickerText(Tenant::class, 'local retailer'))->toHaveCount(1)
            ->and(pickerText(Tenant::class, 'distant retailer'))->toBe([])
            ->and(pickerText(Tenant::class, 'distant owner'))->toBe([]);
    });
});

it('keeps a brand-new tenant pickable in every property', function () {
    // A tenant affiliated with nothing belongs to nobody, and must stay offerable or their FIRST
    // lease could never be written.
    makeTenant(['name' => 'Just Registered']);

    $restricted = makeUser('manager', [$this->asset->id]);
    $this->actingAs($restricted);

    asTenant($this->asset, fn () => expect(pickerText(Tenant::class, 'just registered'))->toHaveCount(1));
});

it('narrows further when the screen asks, without losing the property scope', function () {
    makeTenant(['name' => 'Active Co', 'status' => 'active']);
    makeTenant(['name' => 'Active Gone', 'status' => 'inactive']);

    $narrow = fn ($query) => $query->where('status', 'active');

    expect(pickerText(Tenant::class, 'active'))->toHaveCount(2)
        ->and(pickerText(Tenant::class, 'active', $narrow))->toHaveCount(1);
});

it('returns nothing for a query that folds away, rather than the first page of the table', function () {
    makeTenant(['name' => 'Anyone']);

    // Pure punctuation folds to no words at all. Reading that as "match everything" is how a stray
    // keystroke dumps the table into a dropdown.
    expect(pickerText(Tenant::class, '---'))->toBe([])
        ->and(pickerText(Tenant::class, ''))->toBe([]);
});

it('wires an EntitySelect for server-side folded search rather than browser filtering', function () {
    $select = EntitySelect::make('tenant_id')->entity(Tenant::class);

    // `hasDynamicSearchResults()` false is the silent failure: Filament stops calling the server
    // and filters the options already in the browser — which, with `allowHtml()`, means matching
    // the operator's query against option MARKUP.
    expect($select->hasDynamicSearchResults())->toBeTrue()
        ->and($select->isSearchable())->toBeTrue()
        ->and($select->isHtmlAllowed())->toBeTrue()
        ->and($select->getSearchColumns())->toBe(['search_text'])
        // A table that grows is never preloaded whole into the page.
        ->and($select->isPreloaded())->toBeFalse()
        // The prompt names what may be typed — the whole reason the phone number is discoverable.
        ->and($select->getSearchPrompt())->toContain('phone');
});

it('renders a filter chip as text and its options as markup', function () {
    // Same option, two renderings, one definition: Filament renders an active filter's indicator as
    // plain text, so feeding it the markup shows the operator a wall of `<span class=…>`.
    $tenant = makeTenant(['name' => 'Chip Co', 'phone' => '0100 000 1111']);
    $option = OptionDisplay::for($tenant->fresh());

    expect($option->toText())->toContain('Chip Co')
        ->not->toContain('<span')
        ->and($option->toHtml())->toContain('<span');
});

it('gives a tenant and a vendor a code of their own, and keeps an imported one', function () {
    $first = makeTenant(['name' => 'First In']);
    $second = makeTenant(['name' => 'Second In']);

    expect($first->code)->toMatch('/^TN-\d{7}$/')
        ->and($second->code)->toMatch('/^TN-\d{7}$/')
        ->and($second->code)->not->toBe($first->code);

    // An operator migrating off another system arrives with codes their accountant already uses.
    $imported = makeTenant(['name' => 'Migrated', 'code' => 'LEGACY-42']);
    expect($imported->code)->toBe('LEGACY-42');

    $vendor = Vendor::create(['name' => 'Al-Nour Cleaning', 'type' => 'service_provider', 'status' => 'active']);
    expect($vendor->code)->toMatch('/^VN-\d{7}$/');

    // And the code is in the fold, so it is findable typed with or without its separator.
    expect(pickerText(Tenant::class, $first->code))->toHaveCount(1)
        ->and(pickerText(Tenant::class, str_replace('-', '', $first->code)))->toHaveCount(1);
});

it('offers units by their code, floor and state', function () {
    $unit = makeUnit($this->asset, ['code' => 'A-114', 'status' => 'vacant']);

    $text = implode('', pickerText(Unit::class, 'a114'));

    expect($text)->toContain('A-114')
        ->toContain($this->asset->name);

    // A unit picker inverts the usual reading of state: vacant is the answer you want.
    expect(OptionDisplay::for($unit->fresh())->tone)->toBe('success');
});

it('appends a screen-specific warning without moving it into the registry', function () {
    $unit = makeUnit($this->asset, ['code' => 'D-1']);
    $base = OptionDisplay::for($unit->fresh());

    $decorated = $base->append('⚠ encumbered');

    expect($decorated->subtitle)->toContain('⚠ encumbered')
        // The registry's own option is untouched — the decoration belongs to the screen.
        ->and($base->subtitle)->not->toContain('⚠ encumbered')
        ->and($decorated->title)->toBe($base->title);
});
