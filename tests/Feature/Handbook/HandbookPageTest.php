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

it('copies the panel palette verbatim, because the values are colours not triplets', function () {
    // Filament's palette variables hold COMPLETE colour values — `oklch(0.985 0 0)` — and are
    // consumed as `var(--gray-50)`, not `rgb(var(--gray-50))`.
    //
    // The first version wrapped each in `rgb(...)`, producing `rgb(oklch(0.985 0 0))`. A custom
    // property accepts any token sequence, so nothing errored: the variable WAS set, `var()`
    // therefore never fell back to the safe default, and every surface in the frame resolved to an
    // invalid colour. Silent, and total. Verified against a rendered panel page, not assumed.
    $template = handbookTemplate();

    $this->assertMatchesRegularExpression(
        '/setProperty\(\s*to\s*,\s*value\s*\)/',
        $template,
        'Palette values must be copied verbatim — wrapping them in rgb() yields invalid colours.'
    );

    $this->assertStringNotContainsString(
        'rgb(${value})',
        $template,
        'Filament palette values are already complete colours; rgb() around them is invalid CSS.'
    );
});

it('keeps the handbook legible in both themes', function () {
    // Contrast is chosen, not inherited. Every value here was measured against the ground it sits
    // on before being written down; amber-700 and green-700 came out at 4.09:1 and 4.10:1 on their
    // own tint — just under AA — so both dropped a step to the 800 tones asserted below.
    $embed = (string) file_get_contents(base_path('docs/visual/.vitepress/theme/embed.css'));

    // The measured-safe semantic hues.
    foreach (['#92400e', '#166534', '#b91c1c', '#fbbf24', '#4ade80', '#f87171'] as $hex) {
        $this->assertStringContainsString($hex, $embed, "The measured semantic colour {$hex} is missing.");
    }

    // The two that measured BELOW AA must not come back.
    foreach (['#b45309', '#15803d'] as $hex) {
        $this->assertStringNotContainsString(
            $hex,
            $embed,
            "{$hex} measures under 4.5:1 on its own tint — use the 800 tone instead."
        );
    }

    // Both themes must be defined, or one of them falls back to the handbook's own warm palette
    // and stops matching the panel entirely.
    $this->assertStringContainsString('.atriom-embed.dark', $embed);
    $this->assertStringContainsString('--atriom-gray-', $embed);
});

it('keeps the component out of both the script stack and the attribute', function () {
    // Two separate places this behaviour CANNOT live, each of which broke the page once:
    //
    //   · A pushed stack. The panel layout renders it before Livewire renders this component into
    //     it, so the push lands on a stack already output — silently dropped, component undefined.
    //   · The `x-data` ATTRIBUTE. It lived there, and a comment inside it contained the phrase
    //     "the colouring is very bad" — with real double quotes. HTML has no idea it is looking at
    //     JavaScript: the parser closed the attribute at the first `"` and rendered ~4kB of the
    //     component as visible text on the page.
    //
    // So it is registered via Alpine.data() in a plain inline <script>, and `x-data` is a bare
    // identifier that no amount of prose can break.
    $template = handbookTemplate();

    $this->assertStringNotContainsString("@push('scripts')", $template);
    $this->assertStringNotContainsString("@push('styles')", $template);
    $this->assertStringContainsString("Alpine.data('atriomHandbook'", $template);

    // Registered on alpine:init so the definition cannot race Alpine's own boot.
    $this->assertStringContainsString("addEventListener('alpine:init'", $template);

    // The attribute must be a bare identifier — no braces, no quotes, nothing to terminate early.
    preg_match('/x-data="([^"]*)"/', $template, $m);
    expect($m)->not->toBeEmpty();
    expect(trim($m[1]))->toBe('atriomHandbook');
});

it('never puts a double quote inside a double-quoted Alpine attribute', function () {
    // The generalised form of the bug above: any Alpine expression attribute that contains a `"`
    // terminates early and dumps the rest of itself onto the page as text. Cheap to check, and the
    // failure mode is spectacular and silent — nothing errors, the page just renders source code.
    $template = handbookTemplate();

    preg_match_all('/\s(x-[a-z-]+|@[a-z]+)="([^"]*)"/', $template, $attrs, PREG_SET_ORDER);

    expect($attrs)->not->toBeEmpty();

    foreach ($attrs as [, $name, $value]) {
        // A well-formed match already stops at the closing quote, so the tell is a value that runs
        // on past where the attribute should have ended: unbalanced braces mean it was truncated.
        $this->assertSame(
            substr_count($value, '{'),
            substr_count($value, '}'),
            "The {$name} attribute has unbalanced braces — it was almost certainly cut short by a "
            .'double quote inside it, which dumps the remainder onto the page as text.'
        );
    }
});
