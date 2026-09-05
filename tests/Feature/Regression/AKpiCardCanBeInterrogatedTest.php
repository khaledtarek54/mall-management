<?php

use App\Filament\Admin\Widgets\MallStats;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;

/**
 * A KPI on the landing dashboard can be clicked through to what it is made of (UX5-06).
 *
 * `MallStats` is every money role's landing widget and each figure was a dead end: occupancy, MRR,
 * CSAT, collections and AR were numbers the operator had to take on trust, with no way to see the
 * rows behind them. The drill-down is the whole of the fix — the arithmetic was already right.
 *
 * The half that needs a test is the GATE. This widget is shown to roles with very different reach,
 * so each link is conditional on the destination's own `canAccess()`: a card that lands on a 403
 * reads as the system being broken, which is worse than a card that does not link at all.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** label => url (or null) for every stat this role is shown. */
function statUrls(string $role, $asset): array
{
    test()->actingAs(makeUser($role, [$asset->id]));
    Filament::setTenant($asset);

    $widget = new MallStats;
    $method = new ReflectionMethod($widget, 'getStats');
    $method->setAccessible(true);

    $out = [];
    foreach ($method->invoke($widget) as $stat) {
        $out[(string) $stat->getLabel()] = $stat->getUrl();
    }

    return $out;
}

it('gives a super_admin somewhere to go from every card', function () {
    $urls = statUrls('super_admin', $this->asset);

    expect($urls)->not->toBeEmpty();

    foreach ($urls as $label => $url) {
        expect($url)->not->toBeNull("the card «{$label}» is still a dead end");
    }
});

it('offers no link a role cannot follow', function () {
    // `leasing`, NOT `marketing`. Marketing can open NONE of these destinations, so every URL is
    // null and the reachability loop below never executes — the assertion that actually matters
    // is dead code. Leasing holds units and not payments, so this role exercises both branches:
    // at least one link suppressed, at least one link followed.
    $urls = statUrls('leasing', $this->asset);

    // The premise first: this role IS shown cards. Without it the assertions below are satisfied
    // by an empty widget and the gate goes unproven — the vacuous-sweep shape this codebase has
    // been bitten by three times.
    expect($urls)->not->toBeEmpty();

    // The gate must actually FIRE...
    expect(collect($urls)->contains(fn ($url) => $url === null))->toBeTrue(
        'no link was suppressed for a partially-privileged role — the gate is not firing'
    );

    // ...and must not fire on everything, or the loop below is dead code and the one assertion
    // that resolves a destination never runs.
    expect(collect($urls)->contains(fn ($url) => $url !== null))->toBeTrue(
        'every link was suppressed, so nothing below is actually checked'
    );

    foreach ($urls as $label => $url) {
        if ($url === null) {
            continue;
        }

        // Whatever IS offered must be reachable — asserted by resolving the destination rather
        // than by trusting the list of roles, so a permission change cannot quietly break it.
        expect($this->get($url)->status())->toBeLessThan(400, "«{$label}» links somewhere this role cannot open");
    }
});

it('sends the AR card to ageing and the revenue card to the rent roll', function () {
    // WHICH destination is the substance: "how old is this debt" is answered by the ageing report
    // and by nothing else, and MRR is the rent roll summed. A link to the invoice list would be a
    // link that technically works and answers a different question.
    $urls = statUrls('super_admin', $this->asset);

    $ar = collect($urls)->first(fn ($u, $l) => str_contains(mb_strtolower($l), 'outstanding'));
    $mrr = collect($urls)->first(fn ($u, $l) => str_contains(mb_strtolower($l), 'revenue'));

    expect($ar)->toContain('ar-aging')
        ->and($mrr)->toContain('rent-roll');
});
