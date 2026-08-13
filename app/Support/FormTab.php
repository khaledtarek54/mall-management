<?php

namespace App\Support;

use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Throwable;

/**
 * A form tab that tells you when the problem is inside it.
 *
 * **Why this exists.** Long resource forms are split into tabs so each screen is one coherent
 * group of settings (operator decision 2026-08-08). Tabs have one failure mode that a long scroll
 * does not: submit from tab 1 with a required field blank on tab 4 and the form refuses with the
 * error rendered on a panel nobody can see. Filament v4.11.8's `Tabs` ships **no** validation-error
 * indicator — there is no mention of errors anywhere in `Tabs.php`, `Tab.php` or their Blade — so
 * without this the tabbed form is strictly worse than the scroll it replaced.
 *
 * So every tab built here carries a live count of the validation errors inside it, as a danger
 * badge on the tab itself. The operator sees *which* tab to open.
 *
 * **How the count is derived.** At render time — not at build time — the tab is mounted and can
 * walk its own descendants: `getChildComponentContainers()` → `Schema::getFlatFields()` → each
 * field's state path, matched against the Livewire error bag. Deriving it means the badge can
 * never drift from the fields the tab actually contains, which a hand-maintained list of field
 * names certainly would. (Build-time traversal is not available: `getChildComponents()` throws
 * "container must not be accessed before initialization" on an unmounted component.)
 *
 * `withHidden: true` on both walks is deliberate — a conditionally-hidden field can still fail
 * validation, and a badge that silently ignores it recreates the exact problem this solves.
 */
class FormTab
{
    /**
     * Build a tab from its TRANSLATION KEY — not from an already-translated string.
     *
     * **Why the key and not the label.** Filament derives a tab's identity from its label:
     * `Tab::setUp()` sets the key to `Str::slug(Str::transliterate($label))`, and that key is what
     * `persistTabInQueryString()` writes into the URL and what `getActiveTab()` matches on. With a
     * translated label the identity is therefore per-LANGUAGE — the invoice's Line Items tab is
     * `line-items::data::tab` in English and `albnwd::data::tab` in Arabic. Same tab, two names.
     *
     * The consequence is quiet and was found in a browser, not in a test: a deep link to a tab —
     * a bookmark, a link pasted to a colleague, anything saved while the panel was in the other
     * language — matches no tab on arrival, so the form opens on tab ONE instead. Nothing errors;
     * the reader simply lands somewhere else and concludes the tab they wanted did not load. In a
     * bilingual system where operators switch language routinely, that is not an edge case.
     *
     * Deriving the id from the translation key fixes it at the only point where a stable, unique,
     * locale-independent name for the tab already exists. Taking the KEY as the argument rather
     * than adding an optional id is deliberate: an optional id is one somebody forgets, and a
     * forgotten one silently returns to the locale-dependent behaviour. Passing an already
     * translated string here is loudly wrong instead of quietly wrong — the raw key renders as
     * the tab's label.
     *
     * The `::{statePath}::tab` suffix mirrors Filament's own format exactly, so only the slug
     * changes and nothing that consumes the key sees a shape it does not expect.
     *
     * @param  string  $translationKey  e.g. `admin.sections.items`
     * @param  array<int, mixed>  $schema
     */
    public static function make(string $translationKey, array $schema): Tab
    {
        return Tab::make(__($translationKey))
            ->key(function (Tab $component) use ($translationKey): string {
                $statePath = $component->getStatePath();

                return Str::slug(str_replace('.', '-', $translationKey))
                    .'::'.(filled($statePath) ? "{$statePath}::tab" : 'tab');
            }, isInheritable: false)
            ->schema($schema)
            ->badge(fn (Tab $component, $livewire): ?int => static::errorCount($component, $livewire) ?: null)
            ->badgeColor('danger');
    }

    /** How many of this tab's own fields are currently failing validation. */
    public static function errorCount(Tab $component, mixed $livewire): int
    {
        if (! is_object($livewire) || ! method_exists($livewire, 'getErrorBag')) {
            return 0;
        }

        $errors = $livewire->getErrorBag();

        if ($errors->isEmpty()) {
            return 0;
        }

        return static::statePaths($component)
            ->filter(fn (string $path): bool => $errors->has($path))
            ->count();
    }

    /**
     * Every field state path inside this tab, at any nesting depth.
     *
     * Wrapped: this reaches into Filament's schema traversal, and a badge is decoration — if an
     * upgrade changes that API the form must still render. `FormTabErrorBadgeTest` pins the
     * behaviour so the degradation is loud in CI rather than silent in production.
     *
     * @return Collection<int, string|null>
     */
    protected static function statePaths(Tab $component): Collection
    {
        try {
            return collect($component->getChildComponentContainers(withHidden: true))
                ->flatMap(fn (Schema $schema): array => $schema->getFlatFields(withHidden: true))
                ->map(fn (Field $field): ?string => $field->getStatePath())
                ->filter()
                ->values();
        } catch (Throwable) {
            return collect();
        }
    }
}
