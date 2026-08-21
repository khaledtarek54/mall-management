<?php

/*
|--------------------------------------------------------------------------
| Turning a module off stops its scheduled work
|--------------------------------------------------------------------------
| `Modules::enabled()` gated the navigation, the resources and the actions — and exactly ONE of the
| thirty-four scheduled commands. The other thirty-three ran regardless, so disabling `facility` left
| the nightly generator raising preventive work orders and the hourly scan alerting staff about SLA
| breaches on screens nobody could open. An operator turned something off and it kept working, which
| is worse than never having had a switch.
|
| The guard is applied in ONE place (`ScheduledModules::guard()`, at the end of `routes/console.php`)
| rather than as a `->skip()` on each command, because thirty-three edits are thirty-three chances to
| forget and the next command added inherits nothing. This gate is the other half: a scheduled
| command must be classified, and a classification must name a module that exists.
|
| **The phantom-key trap is the reason for the second case.** `Modules::enabled()` returns TRUE for
| anything not in `KEYS` — that is how core commands stay on — so a typo'd or aspirational key is a
| guard that silently never fires. `Modules::enabled('billing')` was exactly that, and my own first
| draft of the registry mapped `marketing:ensure-budgets` to a non-existent `marketing` key.
*/

use App\Settings\ModulesSettings;
use App\Support\Modules;
use App\Support\ScheduledModules;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;

/** Every artisan command the scheduler runs. */
function scheduledCommandNames(): array
{
    return collect(app(Schedule::class)->events())
        ->map(fn ($event) => ScheduledModules::commandName($event))
        ->filter()
        ->unique()
        ->values()
        ->all();
}

it('classifies every scheduled command as owned by a module or stated to be core', function () {
    $names = scheduledCommandNames();

    // The premise first: if the schedule ever stops being readable this way, the rest of the gate
    // passes over an empty set and reports nothing.
    expect(count($names))->toBeGreaterThan(20, 'The schedule could not be read — this gate is inspecting nothing.');

    $unclassified = array_values(array_filter(
        $names,
        fn (string $n) => ! isset(ScheduledModules::OWNED_BY[$n]) && ! isset(ScheduledModules::CORE[$n]),
    ));

    expect($unclassified)->toBe([], implode("\n", [
        'These scheduled commands are neither owned by a module nor stated to be core:',
        '  '.implode("\n  ", $unclassified),
        '',
        'Add each to ScheduledModules::OWNED_BY (so turning that module off stops it) or to',
        'ScheduledModules::CORE with the reason it can never be switched off.',
    ]));
});

it('names only modules that exist, because an unknown key is a guard that never fires', function () {
    // `Modules::enabled()` returns TRUE for anything outside KEYS — deliberately, so core features
    // need no entry. That makes a wrong key silent: the command keeps running and the registry reads
    // as though it were gated.
    $phantom = array_values(array_diff(
        array_unique(array_values(ScheduledModules::OWNED_BY)),
        Modules::KEYS,
    ));

    expect($phantom)->toBe([], 'These are not `Modules::KEYS` entries, so the guard silently never fires: '.implode(', ', $phantom));
});

it('names no module key that does not exist, anywhere in the app', function () {
    // Not just the registry — EVERY `Modules::enabled('x')` literal under app/. The failure mode is
    // the quietest kind: `enabled()` returns TRUE for anything outside KEYS, so a typo'd or
    // aspirational key produces a call that reads as a gate, passes review, and can never refuse.
    // `Modules::enabled('billing')` sat on the billing-run preview doing exactly that.
    $offenders = [];

    foreach (File::allFiles(base_path('app')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $code = preg_replace('~/\*.*?\*/|//[^\n]*~s', '', $file->getContents()) ?? '';

        if (! preg_match_all("~Modules::enabled\(\s*'([a-z_]+)'~", $code, $matches)) {
            continue;
        }

        foreach ($matches[1] as $key) {
            if (! in_array($key, Modules::KEYS, true)) {
                $offenders[] = str_replace(base_path().'/', '', $file->getPathname())." — '{$key}'";
            }
        }
    }

    expect(array_values(array_unique($offenders)))->toBe([], implode("\n", [
        'These gate on a module key that is not in `Modules::KEYS`. `enabled()` returns TRUE for any',
        'unlisted key, so each of these reads as a toggle and can never refuse:',
        '  '.implode("\n  ", array_unique($offenders)),
        '',
        'Either add the key to Modules::KEYS (and its settings field), or delete the call and say in',
        'a comment that the feature is core.',
    ]));

    // The premise: the sweep can see calls at all.
    $total = 0;

    foreach (File::allFiles(base_path('app')) as $file) {
        $total += preg_match_all("~Modules::enabled\(~", (string) $file->getContents());
    }

    expect($total)->toBeGreaterThan(10, 'The sweep found almost no Modules::enabled() calls — it is looking at the wrong thing.');
});

it('has no stale entry for a command that is no longer scheduled', function () {
    $names = scheduledCommandNames();

    $stale = array_values(array_diff(
        array_merge(array_keys(ScheduledModules::OWNED_BY), array_keys(ScheduledModules::CORE)),
        $names,
    ));

    expect($stale)->toBe([], 'These are classified but not scheduled — remove them: '.implode(', ', $stale));
});

it('gives every core exemption a reason a reviewer can weigh', function () {
    foreach (ScheduledModules::CORE as $command => $reason) {
        // "core" on its own is a shrug. The reason is what makes moving a command out of this list
        // a decision somebody argued.
        expect(strlen($reason))->toBeGreaterThan(50, "The core exemption for {$command} does not say why it can never be switched off.");
    }
});

it('actually skips a disabled module, and spares a core command', function () {
    $settings = app(ModulesSettings::class);
    $settings->facility = false;

    $skipped = null;
    $core = null;

    foreach (app(Schedule::class)->events() as $event) {
        $name = ScheduledModules::commandName($event);

        if ($name === 'facility:generate-preventive') {
            $skipped = $event->filtersPass(app());
        }

        if ($name === 'accounting:sync-ledger') {
            $core = $event->filtersPass(app());
        }
    }

    // Both halves. A guard that skipped everything would satisfy the first assertion alone.
    expect($skipped)->toBeFalse('Disabling `facility` did not stop its nightly work order generator.')
        ->and($core)->toBeTrue('The guard skipped a CORE command — the ledger sweep must run whatever is off.');
});
