<?php

use Illuminate\Support\Facades\File;

/**
 * A Livewire view whose classes Tailwind never scanned renders perfectly and looks unbuilt.
 *
 * **The failure this pins, measured:** the floating assistant's view lives in
 * `resources/views/livewire/`, and `theme.css` sourced `app/Livewire/**` — where the COMPONENT is —
 * but no glob covering the VIEW, which is where the classes actually are. So `bottom-6`, `h-14`,
 * `w-14` and `z-50` were never generated. The markup rendered, the page was a clean 200, the SVG
 * was in the HTML, and every test passed — while the button had no width, no height and no
 * `position: fixed`, so it collapsed to nothing at the end of the document. It reads as "the
 * feature was never built", which is exactly how it was reported.
 *
 * **Scoped to LIVEWIRE views, deliberately.** A first draft asked the question of every directory
 * under `resources/views` carrying a `class="` and flagged seventeen — the PDF templates, the mail
 * templates, the error pages — none of which is styled by the panel theme at all: they carry their
 * own CSS, and mpdf never sees Tailwind. Forcing globs for those would bloat the built stylesheet
 * to silence a gate that was asking the wrong question. A Livewire component renders INSIDE the
 * panel, so its view is exactly the case where the panel's theme has to have seen it.
 *
 * **Checked against `theme.css`, not the compiled output**, because `/public/build` is git-ignored:
 * a gate reading it would pass or fail depending on whether somebody had run `npm run build` on
 * that machine — the green-on-one-laptop failure already recorded here for the PHPStan baseline.
 * The cause is the missing glob, and the glob is in the repository.
 */
it('lets Tailwind see every Livewire view rendered inside the panel', function () {
    $components = collect(File::allFiles(app_path('Livewire')))
        ->filter(fn ($f): bool => str_ends_with($f->getFilename(), '.php'));

    // The premise: a sweep that silently stopped collecting would report no offenders and pass.
    expect($components)->not->toBeEmpty();

    $theme = File::get(resource_path('css/filament/theme.css'));
    preg_match_all("/@source\s+'([^']+)'/", $theme, $matches);

    $sourced = collect($matches[1])
        ->map(fn (string $glob): string => (string) realpath(resource_path('css/filament/'.explode('/**', $glob)[0])))
        ->filter()
        ->all();

    $unseen = [];

    foreach ($components as $component) {
        // The view each component renders, read from its own `view('...')` call.
        if (! preg_match("/view\(\s*'([a-z0-9_.-]+)'/i", File::get($component->getRealPath()), $m)) {
            continue;
        }

        $viewPath = resource_path('views/'.str_replace('.', '/', $m[1]).'.blade.php');

        if (! File::exists($viewPath) || ! str_contains(File::get($viewPath), 'class="')) {
            continue;
        }

        $covered = collect($sourced)->contains(
            fn (string $root): bool => str_starts_with((string) realpath($viewPath), $root)
        );

        if (! $covered) {
            $unseen[] = str_replace(resource_path('views').'/', '', $viewPath);
        }
    }

    expect($unseen)->toBe([], "Tailwind never scans these Livewire views, so their utility classes are\n"
        ."not in the built CSS — the markup renders and the styling is simply absent.\n"
        ."Add an @source glob in resources/css/filament/theme.css covering:\n  ".implode("\n  ", $unseen));
});
