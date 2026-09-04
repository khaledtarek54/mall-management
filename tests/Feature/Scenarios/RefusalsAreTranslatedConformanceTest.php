<?php

/**
 * **A refusal is the app talking to a person, so it speaks their language.**
 *
 * `bootstrap/app.php` renders a `DomainException` as a toast with a redirect back — it is not the
 * 500 page. So every one of them is a sentence an operator reads after pressing a button, and on
 * an Arabic panel it has to be Arabic.
 *
 * 62 of them were raw English on 2026-08-28 and were converted then. **Nine more had appeared by
 * 2026-08-30** — the CAM basis lock, the vendor-dispatch refusal, the overlapping charge schedule,
 * the four write-off guards and both owner-statement guards — which is exactly what happens to a
 * property that is fixed once and then not gated: it decays at the rate new code is written.
 *
 * Two of the nine were worse than untranslated. They interpolated a raw stored value into the
 * sentence — `Invoice INV-1 is 'written_off'` — so even the English read as half a business rule
 * and half a database column. Those resolve through `admin.statuses.*` now, the same catalogue the
 * screen labels from.
 *
 * `InvalidArgumentException` and `RuntimeException` are deliberately NOT swept. They are developer
 * errors — an unreachable state, a failed number allocation — and they render as a 500, which no
 * operator is meant to read in any language.
 */
final class RefusalTranslationExemptions
{
    /**
     * Throws whose message is composed from values that are ALREADY translated.
     *
     * @var array<string, string> `path:line-ish anchor` => why
     */
    public const COMPOSED_FROM_TRANSLATED = [
        'app/Services/Accounting/BudgetService.php' => 'Joins the per-row import errors, each of which is built with __() a few lines above '
            .'(admin.budget.errors.*). Wrapping the join in another __() would translate nothing '
            .'and would put a second sentence around sentences that already read correctly.',
        'app/Services/Accounting/ImportOpeningBalancesService.php' => 'Same shape: it joins admin.opening_balances.errors.at_line messages that are already '
            .'translated per row.',
        'app/Services/MonthlyBillingService.php' => 'Re-throws `$plan[\'reason_detail\']` on the WRITE path, and that string was built by '
            .'`scheduleClash()` as `__(\'admin.refusals.overlapping_charge_schedule\', …)` — SW-052 '
            .'moved the refusal here precisely so a PLAN could answer where a WRITE refuses, and the '
            .'sentence is deliberately the same one on both. Wrapping it in a second `__()` would '
            .'translate nothing; re-deriving the tokens here would be a second wording free to drift '
            .'from the four read-only screens that render the plan.',
    ];
}

it('raises every operator refusal through the translator', function () {
    $offenders = [];
    $swept = 0;

    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));

    foreach ($rii as $file) {
        if ($file->isDir() || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        $relative = 'app'.substr($path, strlen(app_path()));
        $source = file_get_contents($path);

        if (! preg_match_all('/throw new \\\\?DomainException\(/', $source, $m, PREG_OFFSET_CAPTURE)) {
            continue;
        }

        foreach ($m[0] as [$match, $offset]) {
            $swept++;

            // The message may span lines, so look at the whole argument list rather than the line.
            $argument = substr($source, $offset + strlen($match), 300);

            if (str_contains(substr($argument, 0, 200), '__(')) {
                continue;
            }

            if (array_key_exists($relative, RefusalTranslationExemptions::COMPOSED_FROM_TRANSLATED)) {
                continue;
            }

            $line = substr_count(substr($source, 0, $offset), "\n") + 1;
            $offenders[] = "{$relative}:{$line}  ".trim(preg_replace('/\s+/', ' ', substr($argument, 0, 110)));
        }
    }

    // The premise. A sweep that stopped finding throws would report nothing and pass.
    expect($swept)->toBeGreaterThan(250);

    expect($offenders)->toBe([], "A DomainException renders as a TOAST, so this is the app talking to an\n"
        ."operator — in English, on the Arabic panel. Raise it through __() with a key in\n"
        ."lang/{en,ar}/admin/refusals.php, and resolve any interpolated status or field name\n"
        ."through App\Support\Translate so the sentence names it the way the screen does:\n  "
        .implode("\n  ", $offenders));
});

it('holds no stale refusal exemption', function () {
    $stale = [];

    foreach (RefusalTranslationExemptions::COMPOSED_FROM_TRANSLATED as $relative => $why) {
        $path = base_path($relative);

        if (! file_exists($path)) {
            $stale[] = "{$relative} → no such file";

            continue;
        }

        $source = file_get_contents($path);

        if (! str_contains($source, 'DomainException(')) {
            $stale[] = "{$relative} → raises no DomainException any more";
        }

        expect(strlen($why))->toBeGreaterThan(60, "{$relative}: an exemption nobody can review is not an exemption");
    }

    expect($stale)->toBe([], "Stale entries in RefusalTranslationExemptions:\n  ".implode("\n  ", $stale));
});

it('carries every refusal key in BOTH catalogues, with real Arabic in the Arabic one', function () {
    $en = require lang_path('en/admin/refusals.php');
    $ar = require lang_path('ar/admin/refusals.php');

    $enKeys = array_keys($en['refusals']);
    $arKeys = array_keys($ar['refusals']);

    expect($enKeys)->not->toBeEmpty()
        ->and(array_diff($enKeys, $arKeys))->toBe([], 'Refusals missing from the Arabic catalogue')
        ->and(array_diff($arKeys, $enKeys))->toBe([], 'Refusals present only in Arabic');

    // `Lang::has()` cannot see this: the key EXISTS, it just has an English sentence in it — the
    // realistic failure when a batch of keys is added in one pass and reviewed in English.
    $english = [];

    foreach ($ar['refusals'] as $key => $text) {
        if (preg_match('/[\x{0600}-\x{06FF}]/u', (string) $text) !== 1) {
            $english[] = "{$key} → {$text}";
        }
    }

    expect($english)->toBe([], "Sitting in lang/ar/admin/refusals.php with no Arabic in it:\n  ".implode("\n  ", $english));
});
