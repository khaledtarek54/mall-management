<?php

namespace App\Filament\Actions;

use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\View\View;

/**
 * The keyboard shortcuts this panel already has — said out loud, once, where anyone can find them.
 *
 * ## Why this exists rather than more bindings
 *
 * The bindings were already there and had been all along. Filament v4 binds `mod+s` to Save on
 * every Edit page and to Create on every Create page, and `mod+shift+s` to "Create & create
 * another" ({@see EditRecord::getSaveFormAction()} and its Create twin);
 * this panel adds `⌘K` for global search. Verified by rendering a real lease edit page and finding
 * the `x-mousetrap.global.mod-s` attribute Filament emits — a first look searched the page for the
 * literal string `mod+s` and concluded, wrongly, that nothing was bound.
 *
 * What was missing is the only thing that makes a shortcut real: somewhere to learn it. `⌘K` is
 * used because `globalSearchFieldKeyBindingSuffix()` prints the binding inside the search field —
 * the panel's own proof that an advertised shortcut gets used and a silent one does not. Save has
 * had a shortcut for as long as the panel has existed and no operator has been told.
 *
 * ## Why the user menu
 *
 * It is the one surface on every page of both panels that is not about the record in front of you,
 * and it already carries the account-level items (profile, 2FA, language). A shortcut list is the
 * same kind of thing: about how you use the system, not about what you are looking at. The screen
 * guides (`ScreenGuides`) deliberately answer a different question — what THIS screen does — and
 * putting a global list inside one of them would be a hundred copies of the same page.
 */
class KeyboardShortcutsAction
{
    /**
     * The shortcuts, as `binding => translation key`.
     *
     * A registry rather than a blade of hardcoded rows: the modal renders from this, and
     * `KeyboardShortcutsAreAdvertisedTest` asserts every binding named here is one Filament
     * actually emits — so a list that drifts from the panel turns the build red instead of teaching
     * operators a key that does nothing.
     *
     * @var array<string, string> the mousetrap binding => the lang key describing what it does
     */
    public const SHORTCUTS = [
        // Written exactly as the panel BINDS it, not as it reads. Global search is registered by
        // `globalSearchKeyBindings(['command+k', 'ctrl+k'])` and renders as
        // `x-mousetrap.global.command-k.ctrl-k`; the form actions use Mousetrap's platform-neutral
        // `mod`. Storing our own tidier spelling is what let the first version of this list
        // advertise `mod+k`, which nothing in the panel binds — caught by the test, which is the
        // whole reason it compares against the rendered page rather than against this constant.
        'command+k' => 'search',
        'mod+s' => 'save',
        'mod+shift+s' => 'save_and_new',
    ];

    /**
     * **Every string is a CLOSURE, and that is load-bearing.**
     *
     * This action is passed to `Panel::userMenuItems()`, and a panel builder argument is evaluated
     * ONCE — when the panel is registered, at boot, before any request has a locale. Calling
     * `__()` eagerly would freeze whichever language happened to boot first and serve it to every
     * operator in both languages thereafter. The same trap has already been recorded twice in this
     * codebase: `->colors()` and the 2FA `condition:` argument, where an eagerly-evaluated
     * expression silently disabled the feature for everybody.
     *
     * `modalContent` is a closure for the same reason plus one more: it renders {@see rows()},
     * which translates.
     */
    public static function make(): Action
    {
        return Action::make('keyboardShortcuts')
            ->label(fn (): string => __('admin.shortcuts.title'))
            ->icon(Heroicon::OutlinedCommandLine)
            ->modalHeading(fn (): string => __('admin.shortcuts.title'))
            ->modalDescription(fn (): string => __('admin.shortcuts.description'))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(fn (): string => __('admin.actions.close'))
            ->modalContent(fn (): View => view('filament.modals.keyboard-shortcuts', [
                'shortcuts' => self::rows(),
            ]));
    }

    /**
     * The rows the modal renders: the binding written the way a keyboard shows it, and what it does.
     *
     * `mod` is Mousetrap's platform-neutral modifier — ⌘ on a Mac, Ctrl everywhere else. Rendering
     * one and not the other would be wrong for half the operators, so both are shown.
     *
     * @return array<int, array{keys: string, label: string}>
     */
    public static function rows(): array
    {
        return collect(self::SHORTCUTS)
            ->map(fn (string $key, string $binding): array => [
                'keys' => str_replace(
                    ['mod', 'command', 'shift', '+'],
                    ['⌘ / Ctrl', '⌘ / Ctrl', '⇧ Shift', ' + '],
                    $binding,
                ),
                'label' => __("admin.shortcuts.{$key}"),
            ])
            ->values()
            ->all();
    }
}
