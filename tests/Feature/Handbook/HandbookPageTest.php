<?php

use App\Filament\Admin\Pages\Handbook;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * The handbook page inside the panel.
 *
 * It frames `/handbook` rather than rendering it inline, because the handbook runs a Vue app and
 * the panel runs Livewire + Alpine — two SPA runtimes in one document would break the interactive
 * components first. So what is worth testing here is the CONTRACT between the two: which URL is
 * framed, that it carries the embed flag, and that it follows the reader's language.
 *
 * The last two tests guard the two bugs this page shipped with. Both are browser behaviours no PHP
 * test can execute, so they are asserted against the template — which is enough, because in each
 * case the bug IS a specific thing being present in the markup.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(function () {
    Filament::setTenant(null, isQuiet: true);
    app()->setLocale('en');
});

/** The page template, with Blade comments stripped. */
function handbookTemplate(): string
{
    $raw = (string) file_get_contents(resource_path('views/filament/pages/handbook.blade.php'));

    // The file DOCUMENTS both bugs below, so a substring check would otherwise match the prose
    // explaining them rather than the markup causing them.
    return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $raw);
}

it('renders inside the panel for any signed-in user', function () {
    // No permission gates it and none should: it documents how the software works, not any
    // property's numbers. A `{module}.view` check would gate a reader out of the manual for the
    // app they are already signed in to.
    $this->actingAs(makeUser('viewer'));
    Filament::setTenant(makeAsset());

    Livewire::test(Handbook::class)->assertOk();

    expect(Handbook::canAccess())->toBeTrue();
});

it('frames the embed URL, not the full site', function () {
    $this->actingAs(makeUser('viewer'));
    Filament::setTenant(makeAsset());

    // embed=1 is what makes the handbook drop its own top navigation — the panel already supplies
    // the chrome. Without it the reader gets two stacked headers.
    expect((new Handbook)->getFrameUrl())->toBe('/handbook/?embed=1');
});

it('follows the reader into Arabic', function () {
    // An operator working in Arabic should land on the Arabic handbook, not on English with a
    // language switcher to go and find.
    $this->actingAs(makeUser('viewer'));
    Filament::setTenant(makeAsset());

    app()->setLocale('ar');

    expect((new Handbook)->getFrameUrl())->toBe('/handbook/ar/?embed=1');
});

it('offers an escape hatch to the full site', function () {
    // The frame is a fixed slice of the viewport — right for looking something up, wrong for
    // reading a chapter. The action must open the FULL site, so a second window gets its
    // navigation back.
    $this->actingAs(makeUser('viewer'));
    Filament::setTenant(makeAsset());

    Livewire::test(Handbook::class)
        ->assertActionVisible('openInTab')
        ->assertActionVisible('guide');
});

it('never lazy-loads the frame, and never hides it while it loads', function () {
    // The page shipped stuck on its spinner. The iframe carried loading=lazy AND x-show=ready;
    // x-show sets display:none, and a lazily-loaded iframe that is display:none is by definition
    // never near the viewport — so the browser never starts the load, the load event never fires,
    // ready never flips, and the loader stays up forever.
    //
    // assertStringNotContainsString rather than expect()->not->toContain(): toContain is VARIADIC,
    // so a failure message passed as a second argument becomes a second NEEDLE, and not->toContain
    // then passes whenever EITHER is absent. Written that way this test went green with loading=lazy
    // put straight back in — a guard that guarded nothing.
    $template = handbookTemplate();

    $this->assertStringNotContainsString(
        'loading="lazy"',
        $template,
        'A lazily-loaded iframe never loads while it is hidden, so its load event never fires.'
    );

    // Matched to the closing tag rather than to the first ">": the tag contains a Blade echo with
    // an object arrow, and a non-greedy match to ">" stops inside that arrow — capturing a fragment
    // that cannot contain what we are looking for. That version passed with x-show put back on.
    preg_match('/<iframe\b(.*?)><\/iframe>/s', $template, $iframe);

    expect($iframe)->not->toBeEmpty();

    // Vacuity guard: prove the captured tag really is the whole element.
    $this->assertStringContainsString('sandbox=', $iframe[1], 'The iframe tag was captured only partially.');

    $this->assertStringNotContainsString(
        'x-show',
        $iframe[1],
        'The frame must stay visible while it loads — fade the OVERLAY instead.'
    );
});

it('pins the handbook toolbar instead of letting it scroll away', function () {
    // "When I select a page the search is dragged down with me." Two causes, both fixed:
    //
    //   1. VitePress makes `.VPNav` position:fixed only at >=960px; below that it sits in the flow
    //      and scrolls with the content. An iframe inside the panel is routinely narrower than that
    //      once the sidebar and gutters are taken out.
    //   2. The frame is `position: fixed` relative to ITS OWN viewport. If it is even slightly
    //      taller than the space available, the PANEL page scrolls and the whole frame — pinned
    //      toolbar included — travels with it. So the height is MEASURED at runtime rather than
    //      guessed at with a constant that is wrong the moment a heading wraps.
    $embed = (string) file_get_contents(base_path('docs/visual/.vitepress/theme/embed.css'));

    $this->assertMatchesRegularExpression(
        '/max-width:\s*959px.*?\.atriom-embed\s+\.VPNav\s*\{[^}]*position:\s*fixed/s',
        $embed,
        'Below 960px VitePress leaves its nav in the flow, so embed mode must pin it.'
    );

    // Pinning it means we owe the content offset ourselves at that width — but ONLY there, or it
    // double-counts against the padding VitePress already applies above 960px.
    $this->assertMatchesRegularExpression(
        '/max-width:\s*959px.*?\.atriom-embed\s+\.VPContent\s*\{[^}]*padding-block-start/s',
        $embed,
        'A pinned nav no longer occupies flow space; the content needs the offset instead.'
    );

    // The hamburger is the ONLY way to open the sidebar below 960px. Hiding it left a narrow frame
    // with no navigation at all — worse than the chrome it replaced.
    $this->assertStringNotContainsString(
        '.atriom-embed .VPNavBarHamburger',
        $embed,
        'The hamburger is the only sidebar affordance below 960px.'
    );

    $this->assertStringContainsString(
        'getBoundingClientRect',
        handbookTemplate(),
        'The frame height must be measured, not assumed, or the panel page scrolls.'
    );
});

it('defines its behaviour inline rather than through a script stack', function () {
    // A push to the scripts stack is silently dropped here: the panel layout renders that stack
    // before Livewire renders this component into it, so the push lands on a stack already output.
    // The Alpine component must therefore be an inline x-data object with no external function to
    // resolve — otherwise it is "atriomHandbook is not defined" at runtime.
    $template = handbookTemplate();

    $this->assertStringNotContainsString("@push('scripts')", $template);
    $this->assertStringNotContainsString("@push('styles')", $template);
    $this->assertStringContainsString('x-data="{', $template);
});
