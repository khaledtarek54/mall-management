<?php

namespace App\Filament\Actions;

use App\Support\ResourceGuides;
use Filament\Actions\Action;
use Filament\Schemas\Components\Text;
use Illuminate\Support\HtmlString;

/**
 * "How does this screen work?" — the operator guide, in the screen.
 *
 * Renders `docs/business-model/NN-*.md` for the resource. The doc is the single source: nothing is
 * re-typed into the UI, so the guide cannot drift from what the module doc says, and improving one
 * improves the other.
 *
 * Placed as a HEADER action on a list page, which is where someone stops when they do not know what
 * a screen is for. Hidden entirely when the resource has no guide yet — an empty help panel is worse
 * than no help button, because it teaches the operator that help is not worth clicking.
 */
class GuideAction
{
    public static function for(string $resource): Action
    {
        return Action::make('guide')
            ->label(__('admin.guide.action'))
            ->icon('heroicon-o-book-open')
            ->color('gray')
            ->modalHeading(__('admin.guide.heading'))
            ->modalDescription(__('admin.guide.description'))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('admin.guide.close'))
            ->modalWidth('4xl')
            ->visible(fn (): bool => ResourceGuides::has($resource))
            ->schema(fn (): array => [
                Text::make(new HtmlString(
                    '<div class="prose prose-sm dark:prose-invert max-w-none">'
                    .(ResourceGuides::render($resource) ?? '')
                    .'</div>'
                )),
            ]);
    }
}
