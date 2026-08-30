<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * A `self::` CALL TO A METHOD THAT NO LONGER EXISTS IS A 500, AND NOTHING CHEAP SEES IT.
 *
 * `php -l` passes — the call is valid PHP until it runs. PHPStan is on hold here. And a test only
 * catches it if it happens to drive that exact branch, which for an action closure means mounting
 * the modal AND pressing the button.
 *
 * Written after doing it: extracting the four sales-declaration actions out of their table moved
 * the six helpers the ACTIONS called and missed `isAnnualLease()`, which one of those helpers
 * called. The lock ran, raised its invoice and sent its notification — and then the success path
 * fataled, so the operator saw an error page over work that had actually succeeded. Enumerating
 * from the diff instead of from the code, which this repo names as its most repeated defect.
 *
 * TOKENISED, NOT GREPPED. The first version matched `{@see self::applyTo()}` inside four docblocks
 * and reported them as fatals — the prose false-positive two other gates here already had, and the
 * reason a gate gets weakened rather than fixed. `token_get_all()` sees comments as comments.
 *
 * Deliberately narrow: a class only, never an enum (`self::cases()` is built in) and never a trait
 * (its `self::` resolves against whatever host uses it), and skipped entirely when the class has a
 * parent or uses traits, either of which can supply the method.
 */
it('never calls self:: on a method the class does not declare', function (): void {
    $offenders = [];
    $swept = 0;

    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        $tokens = token_get_all($file->getContents());

        $kind = null;
        $hasParent = false;
        $usesTrait = false;
        $declared = [];
        $called = [];
        $depth = 0;

        foreach ($tokens as $i => $token) {
            if (! is_array($token)) {
                continue;
            }

            [$id, $text] = $token;

            if (in_array($id, [T_CLASS, T_TRAIT, T_ENUM, T_INTERFACE], true) && $kind === null) {
                $kind = $id;
            } elseif ($id === T_EXTENDS) {
                $hasParent = true;
            } elseif ($id === T_USE && $kind !== null) {
                // `use` INSIDE the type is a trait import; the ones above it are namespace imports.
                $usesTrait = true;
            } elseif ($id === T_FUNCTION) {
                for ($j = $i + 1; $j < $i + 4; $j++) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                        $declared[] = $tokens[$j][1];
                        break;
                    }
                }
            } elseif (($id === T_STRING && in_array(strtolower($text), ['self', 'static'], true))
                || $id === T_STATIC) {
                // `self::name(` — the `(` is what makes it a CALL rather than a constant.
                if (($tokens[$i + 1][0] ?? null) === T_DOUBLE_COLON
                    && is_array($tokens[$i + 2] ?? null)
                    && $tokens[$i + 2][0] === T_STRING
                    && ($tokens[$i + 3] ?? null) === '(') {
                    $called[] = $tokens[$i + 2][1];
                }
            }
        }

        if ($kind !== T_CLASS || $hasParent || $usesTrait || $called === []) {
            continue;
        }

        $swept++;

        foreach (array_unique($called) as $name) {
            if (! in_array($name, $declared, true)) {
                $offenders[] = $file->getRelativePathname().' → self::'.$name.'()';
            }
        }
    }

    // A sweep that examined nothing passes for the wrong reason.
    expect($swept)->toBeGreaterThan(10, 'the sweep found almost no plain classes calling self::');

    expect($offenders)->toBe([], implode("\n", array_merge(
        ['These call a static method their own class does not declare — a fatal at run time, which'],
        ['`php -l` cannot see and a test only catches by driving that exact branch:'],
        $offenders,
    )));
});
