<?php

use App\Filament\Actions\KeyboardShortcutsAction;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Lang;

/**
 * A shortcut nobody is told about does not exist; a shortcut we advertise and do not have is worse.
 *
 * The bindings were here all along. Filament v4 binds `mod+s` to Save on every Edit page and to
 * Create on every Create page, and `mod+shift+s` to "Create & create another"; this panel adds
 * `mod+k` for global search. What was missing is anywhere to learn them — `⌘K` gets used only
 * because `globalSearchFieldKeyBindingSuffix()` prints its binding inside the field, which is the
 * panel's own evidence that an advertised shortcut is used and a silent one is not.
 *
 * So the list is now in the user menu of both panels, and this test keeps it honest in the
 * direction that matters: **every binding we advertise must be one the panel actually emits**. A
 * reference that teaches a key which does nothing is worse than no reference, and it would never be
 * noticed — the operator would assume they had mistyped.
 *
 * Filament renders a binding as Mousetrap's `x-mousetrap.global.mod-s` attribute, NOT as the
 * literal `mod+s` — searching the page for the latter is how a first pass concluded, wrongly, that
 * nothing was bound at all.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->asset = makeAsset();
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setTenant($this->asset, isQuiet: true);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

/** How Filament writes a Mousetrap binding into the DOM. */
function mousetrapAttribute(string $binding): string
{
    return 'x-mousetrap.global.'.str_replace('+', '-', $binding);
}

it('advertises only bindings the panel really emits', function () {
    $lease = makeLease(makeUnit($this->asset));

    $page = $this->get("/admin/{$this->asset->code}/leases/{$lease->id}/edit")->getContent()
        .$this->get("/admin/{$this->asset->code}/leases/create")->getContent()
        .$this->get("/admin/{$this->asset->code}/leases")->getContent();

    // The premise first: if these pages stopped rendering, every `str_contains` below would be
    // false and the test would report "no shortcuts emitted" rather than passing vacuously.
    expect($page)->toContain('x-mousetrap');

    $unbound = [];

    foreach (array_keys(KeyboardShortcutsAction::SHORTCUTS) as $binding) {
        // One shape for all of them: Mousetrap writes `+` as `-` and chains bindings with dots,
        // so the global-search pair renders as `x-mousetrap.global.command-k.ctrl-k` and a form
        // save as `x-mousetrap.global.mod-s`. Matching the dotted fragment covers both without a
        // per-binding special case — which is what the first version had, and it was wrong.
        if (! str_contains($page, str_replace('+', '-', $binding))) {
            $unbound[] = $binding;
        }
    }

    expect($unbound)->toBe([], "The shortcuts modal advertises bindings the panel does not emit:\n  "
        .implode("\n  ", $unbound)."\nEither bind them or remove them from KeyboardShortcutsAction::SHORTCUTS.");
});

it('describes every shortcut in both languages', function () {
    foreach (KeyboardShortcutsAction::SHORTCUTS as $binding => $key) {
        foreach (['en', 'ar'] as $locale) {
            // `fallback: false` — Lang::has() falls back to English by default, so the obvious
            // spelling of this check only ever catches a key missing from BOTH.
            expect(Lang::has("admin.shortcuts.{$key}", $locale, fallback: false))
                ->toBeTrue("admin.shortcuts.{$key} is missing from lang/{$locale} (binding {$binding}).");
        }
    }
});

it('renders the reference without freezing a language at boot', function () {
    // A panel builder argument is evaluated ONCE, at registration, before any request has a locale
    // — so every string on this action has to be a closure. Ask for the label under each locale and
    // it must answer differently, which an eagerly-translated label could not.
    $action = KeyboardShortcutsAction::make();

    app()->setLocale('en');
    $english = $action->getLabel();

    app()->setLocale('ar');
    $arabic = $action->getLabel();

    app()->setLocale('en');

    expect($english)->toBe('Keyboard shortcuts');
    expect($arabic)->not->toBe($english);
});

it('writes each binding the way a keyboard shows it', function () {
    $rows = KeyboardShortcutsAction::rows();

    expect($rows)->toHaveCount(count(KeyboardShortcutsAction::SHORTCUTS));

    // `mod` is Mousetrap's platform-neutral modifier — rendering only ⌘ or only Ctrl would be wrong
    // for half the operators, so both are shown.
    foreach ($rows as $row) {
        expect($row['keys'])->toContain('⌘ / Ctrl');
        expect($row['keys'])->not->toContain('mod');
        expect($row['label'])->not->toStartWith('admin.');
    }
});
