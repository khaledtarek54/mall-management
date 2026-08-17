<?php

use App\Support\FieldHelp;
use Symfony\Component\Finder\Finder;

/**
 * Field guidance stays sorted: short and visible, or long and one hover away.
 *
 * The catalogue drifts back the moment nobody is measuring it — a paragraph gets appended to an
 * existing helper because that is where the words fit, and nothing anywhere objects. These are the
 * four ways this can rot, each failing differently:
 *
 *   A — an always-visible helper grew into a paragraph again
 *   B — a hint icon that says nothing (the state the ONE pre-existing hintIcon shipped in)
 *   C — a hint written but never shown, or shown but never written
 *   D — the exemption list stayed behind after its reason went away
 */
it('A: keeps every always-visible helper to one readable line', function () {
    $helpers = (require lang_path('en/admin.php'))['helpers'];

    // Vacuity guard: a catalogue that failed to load would pass everything below.
    expect(count($helpers))->toBeGreaterThan(100);

    $tooLong = [];

    foreach ($helpers as $key => $text) {
        if (! is_string($text) || FieldHelp::isSectionDescription($key) || FieldHelp::isExempt($key)) {
            continue;
        }

        $words = str_word_count($text);

        if ($words > FieldHelp::WORD_BUDGET) {
            $tooLong[] = "{$key} ({$words} words)";
        }
    }

    // The same budget, one catalogue over. A field inside an ACTION MODAL takes its helper from
    // `admin.actions.*_helper`, not `admin.helpers.*` — so it rendered permanently under the field
    // exactly like the strings above while being measured by nothing. Caught the day a 37-word
    // paragraph went under the terminate modal's `credit_unearned` toggle and this gate passed.
    // Six keys, and the budget already held for five of them: the standard was never in doubt, only
    // the measurement.
    // Flattened rather than walked two levels deep: the catalogue nests unevenly (`actions.groups.*`
    // is three deep) and a fixed depth would skip whatever someone nests tomorrow.
    $flatten = function (array $tree, string $prefix = '') use (&$flatten): array {
        $flat = [];

        foreach ($tree as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            $flat += is_array($value) ? $flatten($value, $path) : [$path => $value];
        }

        return $flat;
    };

    foreach ($flatten(require lang_path('en/admin/actions.php')) as $key => $text) {
        if (! is_string($text) || ! str_contains($key, 'helper')) {
            continue;
        }

        $words = str_word_count($text);

        if ($words > FieldHelp::WORD_BUDGET && ! FieldHelp::isExempt($key)) {
            $tooLong[] = "{$key} ({$words} words)";
        }
    }

    expect($tooLong)->toBe([], "These sit permanently under a field and have grown into paragraphs.\n"
        ."Keep the line that changes what the operator types, move the WHY to `admin.hints.*`\n"
        ."behind a ->hintIcon(), or register the string in `App\\Support\\FieldHelp::LONG_BY_DESIGN`\n"
        ."with the reason:\n  ".implode("\n  ", $tooLong));
})->group('conformance');

it('B: never renders a hint icon that says nothing', function () {
    // The panel shipped exactly one `hintIcon` before this work and it passed no tooltip — an icon
    // inviting a hover that answers nothing. That is worse than no icon, because it spends the
    // operator's attention and returns nothing for it.
    $bare = [];

    foreach ((new Finder)->files()->in(app_path('Filament'))->name('*.php') as $file) {
        $body = (string) file_get_contents($file->getRealPath());

        // `->hintIcon(` with a single argument — no tooltip, and no `->hintIconTooltip()` anywhere
        // on the file to supply one.
        if (preg_match_all('/->hintIcon\(\s*([^,()]|\((?:[^()]*)\))*\)/', $body, $m)) {
            foreach ($m[0] as $call) {
                if (! str_contains($body, 'hintIconTooltip')) {
                    $bare[] = str_replace(base_path().'/', '', $file->getRealPath()).': '.trim($call);
                }
            }
        }
    }

    expect($bare)->toBe([], "A hint icon with no tooltip invites a hover that answers nothing:\n  "
        .implode("\n  ", $bare));
})->group('conformance');

it('C: shows every hint it writes, and writes every hint it shows', function () {
    $hints = (require lang_path('en/admin.php'))['hints'] ?? [];

    expect($hints)->not->toBeEmpty('The hints catalogue is missing entirely.');

    $used = [];
    foreach ((new Finder)->files()->in(app_path('Filament'))->name('*.php') as $file) {
        if (preg_match_all('/admin\.hints\.([a-z0-9_]+)/', (string) file_get_contents($file->getRealPath()), $m)) {
            foreach ($m[1] as $key) {
                $used[$key] = true;
            }
        }
    }

    // A hint nobody renders is text written into a file and never shown to anyone — the same
    // failure as a guide with no button, one layer down.
    $orphaned = array_values(array_diff(array_keys($hints), array_keys($used)));
    expect($orphaned)->toBe([], "Written but never shown — wire a ->hintIcon() or delete them:\n  ".implode("\n  ", $orphaned));

    // And the reverse: `__()` returns the key itself when it is missing, so this is how an operator
    // ends up hovering a question mark and reading "admin.hints.billing_frequency".
    $undefined = array_values(array_diff(array_keys($used), array_keys($hints)));
    expect($undefined)->toBe([], "Shown but never written — the operator would hover and read the raw key:\n  ".implode("\n  ", $undefined));
})->group('conformance');

it('D: keeps the long-by-design list honest', function () {
    $helpers = (require lang_path('en/admin.php'))['helpers'];

    foreach (FieldHelp::LONG_BY_DESIGN as $key => $reason) {
        // `toHaveKey($key, $value)` asserts the VALUE, not a message — passing an explanation there
        // compares the helper text against the sentence and fails for the wrong reason.
        expect(array_key_exists($key, $helpers))->toBeTrue("'{$key}' is exempted from the length budget but no longer exists.");
        expect(trim($reason))->not->toBe('', "'{$key}' is exempted without a stated reason.");

        // An exemption for a string that is now SHORT classifies nothing — it just makes the
        // registry look considered. Same failure the guide registry's EXEMPT list is gated on.
        expect(str_word_count((string) $helpers[$key]))->toBeGreaterThan(
            FieldHelp::WORD_BUDGET,
            "'{$key}' is exempted from the length budget but is already within it. Remove the exemption."
        );
    }
})->group('conformance');
