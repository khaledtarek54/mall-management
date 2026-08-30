<?php

/*
|--------------------------------------------------------------------------
| The public landing page — the one surface nobody re-checks
|--------------------------------------------------------------------------
| `/` is unauthenticated, it is the first thing anybody sees, and it is outside every sweep in this
| suite: it is not a Filament resource, so the screen matrix cannot see it; it is not a panel page,
| so the navigation gate cannot; and it has no service behind it, so reachability cannot. It had no
| test of any kind, and drifted accordingly — it advertised THREE surfaces after the vendor portal
| shipped, in a three-column grid holding two cards.
|
| Four properties, and each has already been wrong somewhere in this codebase:
|
| A — **It renders, in both languages, in the right direction.** The panel and both portals are
|     Arabic-native. A landing page that is English-only says the product is not.
|
| B — **Its numbers are the registries' numbers.** Everything quoted comes from
|     `App\Support\LandingFacts`. This is the rule CLAUDE.md states for docs — never hand-type a
|     count — applied to the page that faces outward, where a stale figure is read as a claim.
|     Mutation-proved: replace any `{{ $stat['value'] }}` with a literal and this goes red.
|
| C — **It offers every panel that exists, as a tile.** Derived from `Filament::getPanels()`, not
|     from a list beside it: this page shipped naming THREE surfaces in a three-column grid holding
|     two cards, and then kept naming three after the vendor portal made it four. A gate that reads
|     the same list the page reads cannot see what that list omits, which is why the panels are
|     asked of Filament. Its first version asserted only that each URL appeared SOMEWHERE, and
|     mutation said so — deleting the vendor tile left it green, because the footer links there too.
|
| D — **It names no credential.** A public page must not carry a demo login, a seeded password or
|     anything out of the environment. The demo accounts are documented in CLAUDE.md and the docs
|     tree, which are for people who already hold the repository.
*/

use App\Http\Middleware\SetLocale;
use App\Support\LandingFacts;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\File;

it('A: renders in both languages, each in its own direction', function () {
    foreach (SetLocale::SUPPORTED as $locale) {
        $html = $this->withSession(['locale' => $locale])->get('/')
            ->assertOk()
            ->getContent();

        $direction = $locale === 'ar' ? 'rtl' : 'ltr';

        expect($html)->toContain('lang="'.$locale.'"', 'dir="'.$direction.'"');

        // A key that resolves to nothing comes back as the key itself, which renders as literal
        // `landing.hero.lede` on the page. Laravel never errors on it, so only a sweep sees it.
        expect($html)->not->toMatch('/landing\.[a-z0-9_]+\.[a-z0-9_.]+/');
    }
})->group('conformance');

it('A2: the Arabic page is actually in Arabic, not English served under an Arabic tag', function () {
    // `Lang::has()` falls back to English, so a catalogue can be present and empty of Arabic. The
    // only honest check is on the rendered characters.
    $html = $this->withSession(['locale' => 'ar'])->get('/')->assertOk()->getContent();

    $body = strip_tags($html);

    expect(preg_match_all('/\p{Arabic}/u', $body))->toBeGreaterThan(400);
})->group('conformance');

it('B: every figure on the page is read from a registry, not typed into it', function () {
    $html = $this->get('/')->assertOk()->getContent();

    foreach (LandingFacts::all() as $key => $value) {
        expect($value)->toBeGreaterThan(0, "LandingFacts::{$key} resolved to nothing");
    }

    // The six the page actually prints, each in its own stat cell — asserted on the FULL element
    // so a different number that happens to contain these digits cannot satisfy it.
    $facts = LandingFacts::all();

    foreach (['documented_modules', 'gl_sources', 'screens', 'reports', 'roles', 'surfaces'] as $key) {
        expect($html)->toContain('<div class="stat-value">'.$facts[$key].'</div>');
    }
})->group('conformance');

it('B2: the page quotes no count the registries do not answer for', function () {
    // The stat cells are the only place a bare figure is presented as a fact about the system.
    // Anything else that appears there is a hand-typed number, which is the whole failure mode.
    $html = $this->get('/')->assertOk()->getContent();

    preg_match_all('/<div class="stat-value">(\d+)<\/div>/', $html, $matches);

    expect($matches[1])->not->toBeEmpty('the sweep found no stat cells, so it proved nothing');

    $known = array_map('strval', array_values(LandingFacts::all()));

    // NOTE: Pest's `toContain` takes further NEEDLES, not a failure message — a message passed
    // there is asserted as a second needle and the test fails for a reason that is not the defect.
    $unexplained = array_values(array_diff($matches[1], $known));

    expect($unexplained)->toBe([], 'these figures are presented as facts about the system and no registry answers them: '.implode(', ', $unexplained));
})->group('conformance');

it('C: every panel that exists is offered as a tile, and every tile is reachable', function () {
    $html = $this->get('/')->assertOk()->getContent();

    $panels = Filament::getPanels();

    expect($panels)->not->toBeEmpty('no panels were registered, so this test proved nothing');

    // One TILE per panel — asserted on the surface-card markup, not merely on the URL appearing
    // somewhere on the page. The footer links to all three as well, so a URL sweep stays green
    // with the tile deleted (measured).
    foreach ($panels as $id => $panel) {
        $path = '/'.$panel->getPath();

        expect($html)->toContain('<a class="surface-card reveal" href="'.url($path).'"');

        // Signed out, a panel redirects to its own login. What it must never answer is 404.
        $this->get($path)->assertStatus(302);
    }

    // The tiles are the panels plus the mobile API, which has no page to open and so is a card
    // rather than a link. Anything else is a tile for a surface that does not exist.
    expect(substr_count($html, 'class="surface-card'))->toBe(count($panels) + 1);
})->group('conformance');

it('D: the public page names no credential', function () {
    $html = $this->get('/')->assertOk()->getContent();

    // The demo accounts, the seeded password, and the shapes an environment value arrives in.
    $forbidden = [
        '@mall.test',
        '@atriomwalk.test',
        '@atriom.test',
        'password',
        config('app.key'),
        env('DEMO_USER_PASSWORD') ?: '__no_demo_password_configured__',
        env('DB_PASSWORD') ?: '__no_db_password_configured__',
    ];

    foreach (array_filter($forbidden) as $needle) {
        expect(strtolower($html))->not->toContain(strtolower($needle));
    }
})->group('conformance');

it('D2: the view carries no credential in its source either', function () {
    // A string can be present in the template and hidden by CSS, or sit in a comment. The rendered
    // check above would pass on a comment; this one would not.
    $source = File::get(resource_path('views/welcome.blade.php'));

    foreach (['@mall.test', '@atriomwalk.test', 'DEMO_USER_PASSWORD', 'DB_PASSWORD'] as $needle) {
        expect($source)->not->toContain($needle);
    }
})->group('conformance');
