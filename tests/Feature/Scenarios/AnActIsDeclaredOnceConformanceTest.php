<?php

use Tests\Support\ActionStrips;

/*
|--------------------------------------------------------------------------
| One act, one place in the strip that renders it (2026-09-01)
|--------------------------------------------------------------------------
| `EditInvoice` composed `InvoiceActions::all()` — which defines `regeneratePaymentLink` — AND kept
| a second, inline copy of the same act. Both rotated the same token, so neither was wrong; what
| was wrong is that the operator read the same DESTRUCTIVE red button twice in one header, with
| nothing to say which was which. Reported from the panel.
|
| It is invisible from either file. Each is correct alone, and `cacheAction()` keys by name — so
| `mountAction('regeneratePaymentLink')` resolved, `assertActionVisible` passed, and the two tests
| that drive that exact act on that exact page were green throughout. **Only the rendered strip
| shows two.**
|
| ## Two gates, because neither can see what the other does
|
| This is the SOURCE half. It reads every strip the panel declares — including all 49 relation
| managers, which the behavioural half cannot cheaply mount — and it can expand a registry spread
| to the acts it brings in.
|
| `NoScreenRendersTheSameActTwiceTest` is the BEHAVIOURAL half. It mounts the real components and
| sees the three shapes no static read can: an act supplied by a TRAIT, one spread from
| `parent::getHeaderActions()`, and a group composed at runtime (`LeaseActions::grouped()` resolves
| `self::only(self::GROUPS[…])`, so its contents do not exist until the page is built).
|
| Both are mutation-proved against the real historical defect: restore `EditInvoice` as it stood at
| `d4edce7c^` and each one names it.
*/

it('never renders the same act twice in one strip of controls', function () {
    $registries = ActionStrips::registries();

    // Premise: with no registry to expand, a spread is invisible and this sweep is much weaker
    // than it reads.
    expect($registries)->not->toBeEmpty();

    $offenders = [];
    $strips = 0;
    $acts = 0;

    foreach (ActionStrips::sources() as $file) {
        foreach (ActionStrips::inFile($file, $registries) as $strip) {
            $strips++;
            $acts += count($strip['members']);

            $sources = [];

            foreach ($strip['members'] as [$name, $source]) {
                $sources[$name][] = $source;
            }

            foreach ($sources as $name => $declaredBy) {
                if (count($declaredBy) > 1) {
                    $offenders[] = str_replace(base_path().'/', '', $file)
                        .':'.$strip['line']." · {$strip['method']}() renders '{$name}' from "
                        .implode(' + ', $declaredBy);
                }
            }
        }
    }

    expect($offenders)->toBe([], implode("\n  ", array_merge(
        ['These strips offer the same act more than once — the operator reads one button twice:'],
        $offenders,
    )));

    // Vacuity guards. Measured at 330 strips and 645 acts when this was written; a sweep that
    // silently stops collecting is how three gates in this project stopped gating, and each time
    // the tell was that nobody had asked it what it had counted.
    expect($strips)->toBeGreaterThan(300, 'The sweep found far fewer action strips than this panel declares.');
    expect($acts)->toBeGreaterThan(600, 'The sweep read far fewer acts than this panel declares.');
});
