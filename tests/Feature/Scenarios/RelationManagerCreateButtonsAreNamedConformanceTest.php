<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * A `CreateAction` WITH NO LABEL NAMES ITSELF AFTER THE MODEL CLASS.
 *
 * Filament derives the label from the relationship's model, so `LeasePercentageRentTier` rendered
 * the button as **"New lease percentage rent tier"**. Reported from the panel as the button not
 * being there at all — which is exactly what happens: an operator looking for "add a band" scans
 * past a sentence built out of a table name and concludes the screen cannot do it.
 *
 * It is an ARABIC failure too. The derived label is English however the panel is set, so a
 * relation manager without one is untranslated chrome on every RTL screen —
 * `ArabicPanelHasNoEnglishChromeConformanceTest` covers form and column labels and does not reach
 * an action's derived default.
 *
 * Nine of them shipped that way. The gate is cheap and the fix is one line each.
 */
it('gives every relation-manager create button a name of its own', function (): void {
    $offenders = [];
    $swept = 0;

    foreach (Finder::create()->files()->in(app_path('Filament'))->path('RelationManagers')->name('*.php') as $file) {
        $source = $file->getContents();

        if (! preg_match_all('/CreateAction::make\(\)/', $source, $matches, PREG_OFFSET_CAPTURE)) {
            continue;
        }

        foreach ($matches[0] as [$match, $offset]) {
            $swept++;

            // The chain runs to the element's closing `),` — a label declared after that belongs
            // to the next action, not this one.
            $tail = substr($source, $offset + strlen($match), 400);
            $stop = strpos($tail, '),');
            $chain = $stop === false ? $tail : substr($tail, 0, $stop);

            // The BUTTON and the modal's HEADING are derived separately — labelling the button
            // left "Create Lease Percentage Rent Tier" on the modal it opens, which is the same
            // sentence in the place an operator reads it second.
            foreach (['->label(' => 'button', '->modalHeading(' => 'modal heading'] as $call => $what) {
                if (! str_contains($chain, $call)) {
                    $offenders[] = $file->getRelativePathname().' ('.$what.')';
                }
            }
        }
    }

    // A sweep that examined nothing passes for the wrong reason.
    expect($swept)->toBeGreaterThan(5, 'the sweep found almost no create actions');

    expect($offenders)->toBe([], implode("\n", array_merge(
        ['These render a label Filament derives from the MODEL CLASS — "New lease percentage rent'],
        ['tier" — which is unreadable, and English on the Arabic panel however it is set:'],
        $offenders,
    )));
});
