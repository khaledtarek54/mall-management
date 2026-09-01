<?php

namespace Tests\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * **Every strip of controls the panel declares, read off the source.**
 *
 * A *strip* is what an operator reads as one row of controls: a page header, a table's row actions,
 * its header actions, its toolbar. This resolves each one to the act NAMES it renders, so a gate
 * can ask whether any of them offers the same act twice — the defect `EditInvoice` shipped on
 * 2026-09-01, where the header said "Regenerate payment link" twice because the page composed
 * `InvoiceActions::all()` AND kept an inline copy.
 *
 * A CLASS rather than file-scope functions, for the reason {@see RoleMatrix} gives and CLAUDE.md
 * records four times: a helper declared in two test files is a fatal redeclaration during
 * collection that exits the whole suite 255 with no output on either stream, and `--parallel`
 * hides it.
 *
 * **Tokenised, not grepped.** A regex cannot tell a top-level element of the strip from an action
 * declared inside a modal `schema()` closure five levels down, and counting those as siblings
 * reports duplicates no operator ever sees. `token_get_all()` gives real nesting.
 *
 * **What it cannot see, and why the behavioural half exists.** Acts supplied by a TRAIT, spread
 * from `parent::getHeaderActions()`, or composed at runtime (`LeaseActions::grouped()` resolves
 * `self::only(self::GROUPS[…])`) are invisible to any static read. `NoScreenRendersTheSameActTwiceTest`
 * mounts the real components and covers exactly those. What this covers that the behavioural sweep
 * cannot is the surfaces it cannot cheaply mount — every relation manager in the panel.
 */
class ActionStrips
{
    /** The methods whose array argument is a strip an operator reads as one row. */
    public const STRIP_METHODS = [
        'getHeaderActions', 'getActions', 'recordActions', 'headerActions',
        'toolbarActions', 'getTableActions', 'getTableHeaderActions',
    ];

    /**
     * Every PHP file under `app/Filament`.
     *
     * @return array<int, string>
     */
    public static function sources(): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(app_path('Filament'), RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * The act names each `App\Filament\Admin\Actions\*Actions` registry defines.
     *
     * @return array<string, array<int, string>>
     */
    public static function registries(): array
    {
        $registries = [];

        foreach (glob(app_path('Filament/Admin/Actions/*Actions.php')) as $file) {
            preg_match_all("/Action::make\('([^']+)'\)/", (string) file_get_contents($file), $matches);

            $registries[basename($file, '.php')] = array_values(array_unique($matches[1]));
        }

        return $registries;
    }

    /**
     * Every strip in one file, as `['method' => …, 'line' => …, 'members' => [[name, source], …]]`.
     *
     * @param  array<string, array<int, string>>  $registries
     * @return array<int, array{method: string, line: int, members: array<int, array{0: string, 1: string}>}>
     */
    public static function inFile(string $file, array $registries): array
    {
        $tokens = token_get_all((string) file_get_contents($file));
        $strips = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token) || $token[0] !== T_STRING || ! in_array($token[1], self::STRIP_METHODS, true)) {
                continue;
            }

            $open = self::openingBracket($tokens, $i, $count);

            if ($open === null) {
                continue;
            }

            $members = self::members($tokens, $open, $registries);

            if ($members !== []) {
                $strips[] = ['method' => $token[1], 'line' => $token[2], 'members' => $members];
            }
        }

        return $strips;
    }

    /** The `[` that opens this strip's array, if it is close enough to be one. */
    private static function openingBracket(array $tokens, int $i, int $count): ?int
    {
        for ($j = $i + 1; $j < min($i + 14, $count); $j++) {
            $text = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];

            if ($text === '[') {
                return $j;
            }

            if ($text === ';' || $text === '}') {
                return null;
            }
        }

        return null;
    }

    /**
     * Top-level members of the array opening at `$i`, descending into groups.
     *
     * A dropdown is part of the strip it sits in — putting one act in two groups of one header is
     * the same defect wearing a hat — so `ActionGroup` and `BulkActionGroup` are FLATTENED.
     *
     * @param  array<string, array<int, string>>  $registries
     * @return array<int, array{0: string, 1: string}>
     */
    private static function members(array $tokens, int $i, array $registries): array
    {
        $depth = 0;
        $names = [];
        $count = count($tokens);

        for (; $i < $count; $i++) {
            $token = $tokens[$i];
            $text = is_array($token) ? $token[1] : $token;

            if ($text === '[' || $text === '(' || $text === '{') {
                $depth++;

                continue;
            }

            if ($text === ']' || $text === ')' || $text === '}') {
                $depth--;

                if ($depth === 0) {
                    break;
                }

                continue;
            }

            // Elements of the strip itself — never an action declared inside a nested closure.
            if ($depth !== 1 || ! is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            $class = $token[1];
            $isStaticCall = isset($tokens[$i + 1]) && $tokens[$i + 1][0] === T_DOUBLE_COLON
                && isset($tokens[$i + 2]) && is_array($tokens[$i + 2]);

            if (! $isStaticCall) {
                continue;
            }

            // `...SomeActions::all()` — a registry spread. Expanded to what it renders.
            if ($tokens[$i + 2][1] !== 'make' && isset($registries[$class])) {
                foreach ($registries[$class] as $name) {
                    $names[] = [$name, $class.'::'.$tokens[$i + 2][1].'()'];
                }

                continue;
            }

            if ($tokens[$i + 2][1] !== 'make') {
                continue;
            }

            if ($class === 'ActionGroup' || $class === 'BulkActionGroup') {
                foreach (self::groupMembers($tokens, $i, $count, $registries) as $member) {
                    $names[] = $member;
                }

                continue;
            }

            $name = self::nameOf($tokens, $i, $class);

            if ($name !== null) {
                $names[] = [$name, $class];
            }
        }

        return $names;
    }

    /**
     * The acts inside a group literal. A group composed at runtime (`self::only(…)`) resolves to
     * nothing here — deliberately, and it is why the behavioural sweep exists.
     *
     * @param  array<string, array<int, string>>  $registries
     * @return array<int, array{0: string, 1: string}>
     */
    private static function groupMembers(array $tokens, int $i, int $count, array $registries): array
    {
        for ($j = $i + 3; $j < $count; $j++) {
            $text = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];

            if ($text === '[') {
                return self::members($tokens, $j, $registries);
            }

            if ($text === ')') {
                break;
            }
        }

        return [];
    }

    /** An action's name: the string it was given, else the one Filament derives from the class. */
    private static function nameOf(array $tokens, int $i, string $class): ?string
    {
        if (isset($tokens[$i + 4]) && is_array($tokens[$i + 4]) && $tokens[$i + 4][0] === T_CONSTANT_ENCAPSED_STRING) {
            return trim($tokens[$i + 4][1], "'\"");
        }

        // `EditAction::make()` — Filament derives `edit` from the class name.
        if (isset($tokens[$i + 3], $tokens[$i + 4]) && $tokens[$i + 3] === '(' && $tokens[$i + 4] === ')') {
            return lcfirst((string) preg_replace('/Action$/', '', $class));
        }

        return null;
    }
}
