<?php

namespace App\Filament\Actions;

use App\Support\ResourceGuides;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;

/**
 * "How this works" — a short, structured, translated answer, in native Filament components.
 *
 * Built with `Section` + `TextEntry` rather than injected HTML. The project's rule is native
 * Filament over Blade, and the first version broke it by hand-rolling a `<div class="prose">` —
 * classes this build does not even ship, so the panel rendered as unspaced raw text. Native
 * components also inherit RTL, dark mode and spacing for free, which matters here more than most
 * places: this panel exists to be read.
 *
 * A bulleted list is rendered as one `TextEntry` per item rather than a `<ul>`, so the rendering
 * stays inside Filament's own styling instead of depending on typography classes.
 */
class GuideAction
{
    public static function for(string $resource): Action
    {
        $key = ResourceGuides::keyFor($resource);

        return Action::make('guide')
            ->label(__('admin.guide.action'))
            ->icon('heroicon-o-book-open')
            ->color('gray')
            ->modalHeading(__('admin.guide.heading'))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('admin.guide.close'))
            ->modalWidth('2xl')
            ->visible(fn (): bool => $key !== null)
            ->schema(fn (): array => $key === null ? [] : array_values(array_filter([
                TextEntry::make('guide_purpose')
                    ->hiddenLabel()
                    ->state(ResourceGuides::purpose($key))
                    ->size('lg')
                    ->weight('bold'),

                self::list(__('admin.guide.steps'), ResourceGuides::steps($key), 'gray'),

                // The one nothing else in the system tells an operator: touch this, and THAT moves.
                self::list(__('admin.guide.affects'), ResourceGuides::affects($key), 'primary'),

                self::list(__('admin.guide.rules'), ResourceGuides::rules($key), 'warning'),
            ])));
    }

    /**
     * One section, one entry per line — never a hand-rolled `<ul>`.
     *
     * @param  array<int, string>  $items
     */
    private static function list(string $heading, array $items, string $color): ?Section
    {
        if ($items === []) {
            return null;
        }

        return Section::make($heading)
            ->compact()
            ->schema(array_map(
                fn (string $item, int $i) => TextEntry::make("guide_{$heading}_{$i}")
                    ->hiddenLabel()
                    ->state('• '.$item)
                    ->color($color),
                $items,
                array_keys($items),
            ));
    }
}
