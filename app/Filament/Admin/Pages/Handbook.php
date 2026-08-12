<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Actions\GuideAction;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * The visual handbook, inside the panel.
 *
 * **Why an iframe, and why that is the right answer rather than a compromise.** The handbook runs a
 * Vue application — the posting explorer, the state machines, the two calculators — and the panel
 * runs Livewire + Alpine. Rendering the built HTML inline would put two SPA runtimes in one
 * document, competing for the same DOM and the same hydration: the interactive components would be
 * the first thing to break, and they are the reason the handbook is worth opening. A same-origin
 * iframe isolates the two runtimes completely while still letting the parent drive the child
 * directly (see the Blade view — no postMessage handshake needed).
 *
 * **What makes it read as part of the panel rather than a website pasted into one:** the child
 * takes the panel's light/dark mode and its per-property primary colour, it drops its own top
 * navigation because Filament already supplies the chrome, and it fills the content area with no
 * second scrollbar. All of that lives in the Blade view and in the handbook's own embed styles.
 *
 * The route it frames (`/handbook`) stays reachable on its own — it is what the deploy builds and
 * what `tests/Feature/Handbook` proves, and it is the only way to read the handbook if the panel
 * itself is what you are trying to debug.
 */
class Handbook extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'handbook';

    protected string $view = 'filament.pages.handbook';

    /**
     * Deliberately NOT in a navigation group.
     *
     * Every group in the sidebar is a module an operator works IN. The handbook is about the whole
     * system, so filing it under Accounting or Operations would say it belongs to whoever owns that
     * group — the same reasoning the notification centre sits at the top level.
     */
    protected static string|UnitEnum|null $navigationGroup = null;

    /**
     * No permission gates it, and none should.
     *
     * It documents how the system works, not what any particular property's numbers are — there is
     * nothing in it scoped to a role. A `{module}.view` check would gate a reader out of the manual
     * for the software they are already signed in to.
     */
    public static function canAccess(): bool
    {
        return Auth::check();
    }

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::class),
            // The frame is a fixed slice of the viewport, which is right for looking something up
            // and wrong for reading a chapter. This is the escape hatch — and it opens the FULL
            // site (no `embed=1`), so a second window gets its own navigation back.
            Action::make('openInTab')
                ->label(__('admin.handbook.open_in_tab'))
                ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                ->color('gray')
                ->url(fn (): string => app()->getLocale() === 'ar' ? '/handbook/ar/' : '/handbook/', shouldOpenInNewTab: true),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.handbook.nav_label');
    }

    public function getTitle(): string
    {
        return __('admin.handbook.page_title');
    }

    public function getSubheading(): ?string
    {
        return __('admin.handbook.subheading');
    }

    /**
     * Where the frame starts.
     *
     * Follows the panel's language, so an operator working in Arabic lands on the Arabic handbook
     * rather than on English with a language switcher to find. `embed=1` is what tells the handbook
     * to drop its own navigation — it is passed explicitly rather than sniffed from `window.top`,
     * because a reader who opens `/handbook` in a second tab to sit beside the panel should get the
     * full site, and that is indistinguishable from an iframe otherwise.
     */
    public function getFrameUrl(): string
    {
        $base = app()->getLocale() === 'ar' ? '/handbook/ar/' : '/handbook/';

        return $base.'?embed=1';
    }
}
