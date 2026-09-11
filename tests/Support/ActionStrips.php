<?php

namespace Tests\Support;

use App\Support\PhpSource;
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
     * The act names each `*Actions` registry defines, keyed by FQCN.
     *
     * By FQCN, not basename: the portal has its own `InvoiceActions` beside the admin panel's
     * (2026-09-11), and a basename key let one overwrite the other — so a portal strip spreading
     * `...InvoiceActions::all()` expanded to the ADMIN invoice acts and the gate compared the wrong
     * list. A file's spread is resolved through its own `use` imports ({@see importsOf()}).
     *
     * Two homes: `app/Filament/{Panel}/Actions/` for a panel's per-record registries and
     * `app/Filament/Actions/` for the holder-agnostic ones (`RentableItemHoldingActions` serves a
     * lease and a unit ownership). A registry that COMPOSES another — `LeaseActions::all()` lists
     * `RentableItemHoldingActions::assign()` — carries that registry's names too, resolved through
     * {@see methodNames()}, or the source half of the gate could not see a duplicate of any act a
     * registry defines by delegating. The review of the factory change found exactly that blind
     * spot: measured, `LeaseActions` had gone from 12 names to 10.
     *
     * @return array<class-string, array<int, string>>
     */
    public static function registries(): array
    {
        static $memo = null;

        if ($memo !== null) {
            return $memo;
        }

        $files = [];

        foreach (array_merge(
            glob(app_path('Filament/Actions/*Actions.php')) ?: [],
            glob(app_path('Filament/*/Actions/*Actions.php')) ?: [],
        ) as $file) {
            $files[self::classOf($file)] = $file;
        }

        $registries = [];

        foreach ($files as $class => $file) {
            preg_match_all("/Action::make\('([^']+)'\)/", PhpSource::withoutComments((string) file_get_contents($file)), $matches);

            $registries[$class] = array_values(array_unique($matches[1]));
        }

        // Names a registry acquires by composing another registry's method (one pass is enough:
        // no registry composes a registry that itself composes a third).
        foreach ($files as $class => $file) {
            $imports = self::importsOf($file);

            foreach ($registries as $other => $names) {
                if ($other === $class) {
                    continue;
                }

                $short = substr((string) strrchr('\\'.$other, '\\'), 1);

                if (($imports[$short] ?? null) !== $other) {
                    continue;
                }

                preg_match_all('/\b'.preg_quote($short, '/').'::(\w+)\(/', PhpSource::withoutComments((string) file_get_contents($file)), $calls);

                foreach (array_unique($calls[1]) as $method) {
                    $registries[$class] = array_values(array_unique(array_merge(
                        $registries[$class],
                        self::namesOfCall($other, $method, $registries),
                    )));
                }
            }
        }

        return $memo = $registries;
    }

    /**
     * What ONE registry method renders: a method whose body declares a single act (`assign()` →
     * `assignRentableItem`) resolves to that act; anything else (`all()`, `grouped()`,
     * `forOwner()`, `only()`) to every name the registry defines — the over-expansion the gate has
     * always made for a spread, and the reason a page composes a registry as ONE spread.
     *
     * @param  array<class-string, array<int, string>>  $registries
     * @return array<int, string>
     */
    public static function namesOfCall(string $class, string $method, array $registries): array
    {
        $single = self::methodNames()[$class][$method] ?? null;

        return $single !== null ? [$single] : ($registries[$class] ?? []);
    }

    /**
     * Registry method → the one act it declares, for every registry method that declares exactly one.
     *
     * @return array<class-string, array<string, string>>
     */
    public static function methodNames(): array
    {
        static $memo = null;

        if ($memo !== null) {
            return $memo;
        }

        $memo = [];

        foreach (array_merge(
            glob(app_path('Filament/Actions/*Actions.php')) ?: [],
            glob(app_path('Filament/*/Actions/*Actions.php')) ?: [],
        ) as $file) {
            $class = self::classOf($file);
            $source = PhpSource::withoutComments((string) file_get_contents($file));

            // Each `public static function name(...): Action { ... }` body, up to the next method.
            preg_match_all('/public static function (\w+)\([^)]*\): Action\s*\{(.*?)(?=\n    (?:public|private|protected) |\n\}\s*$)/s', $source, $methods, PREG_SET_ORDER);

            foreach ($methods as [, $method, $body]) {
                if (preg_match_all("/Action::make\('([^']+)'\)/", $body, $names) === 1) {
                    $memo[$class][$method] = $names[1][0];
                }
            }
        }

        return $memo;
    }

    /**
     * The act each `app/Filament/Actions/*Action.php` FACTORY builds, keyed by FQCN.
     *
     * `PostMonthAction::make('invoices.edit')` takes a PERMISSION as its argument and
     * `OpenRecordAction::make(InvoiceResource::class)` a resource; reading the first string
     * argument as the act's name — what `nameOf()` does for a bare `Action::make('x')` — named the
     * former after a permission and the latter after nothing. The name is the literal the factory
     * itself declares.
     *
     * @return array<class-string, string>
     */
    public static function factories(): array
    {
        $out = [];

        foreach (glob(app_path('Filament/Actions/*Action.php')) ?: [] as $file) {
            $source = PhpSource::withoutComments((string) file_get_contents($file));

            if (preg_match("/Action::make\('([^']+)'\)/", $source, $m)) {
                $out[self::classOf($file)] = $m[1];
            } elseif (preg_match('/Action::make\(self::(\w+)\)/', $source, $m)
                && preg_match("/const {$m[1]} = '([^']+)';/", $source, $c)) {
                // `OpenRecordAction::make()` names its act through a constant, so the row-click
                // seam can read the same name.
                $out[self::classOf($file)] = $c[1];
            }
        }

        return $out;
    }

    /** `app/Filament/Foo/Bar/Baz.php` → `App\Filament\Foo\Bar\Baz`. */
    private static function classOf(string $file): string
    {
        return 'App\\'.str_replace('/', '\\', substr($file, strlen(app_path()) + 1, -4));
    }

    /**
     * Short name → FQCN for every `use` statement in a file, so a registry spread resolves to the
     * registry the file actually imported.
     *
     * @return array<string, class-string>
     */
    public static function importsOf(string $file): array
    {
        preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+(\w+))?;/m', (string) file_get_contents($file), $uses, PREG_SET_ORDER);

        $imports = [];

        foreach ($uses as $use) {
            $imports[$use[2] ?? substr((string) strrchr('\\'.$use[1], '\\'), 1)] = $use[1];
        }

        return $imports;
    }

    /**
     * Every strip in one file, as `['method' => …, 'line' => …, 'members' => [[name, source], …]]`.
     *
     * @param  array<string, array<int, string>>  $registries
     * @return array<int, array{method: string, line: int, members: array<int, array{0: string, 1: string}>}>
     */
    public static function inFile(string $file, array $registries): array
    {
        // The registries THIS file can spread, keyed by the short name it uses for each. A file in
        // the registry's own namespace needs no import, so the namespace is tried too.
        $imports = self::importsOf($file);
        $namespace = preg_match('/^namespace\s+([^;]+);/m', (string) file_get_contents($file), $ns) ? $ns[1] : '';
        $local = [];

        foreach (array_keys($registries) as $class) {
            $short = substr((string) strrchr('\\'.$class, '\\'), 1);

            if (($imports[$short] ?? null) === $class || $namespace.'\\'.$short === $class) {
                $local[$short] = $class;
            }
        }

        // From here on `$registries` is short name → FQCN for THIS file; names resolve through
        // `namesOfCall()` against the full registry map.
        $registries = $local;

        $factories = [];

        foreach (self::factories() as $class => $name) {
            $short = substr((string) strrchr('\\'.$class, '\\'), 1);

            if (($imports[$short] ?? null) === $class || $namespace.'\\'.$short === $class) {
                $factories[$short] = $name;
            }
        }

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

            $members = self::members($tokens, $open, $registries, $factories);

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
     * @param  array<string, class-string>  $registries  short name → FQCN, for this file
     * @param  array<string, string>  $factories  short name → the act a factory declares
     * @return array<int, array{0: string, 1: string}>
     */
    private static function members(array $tokens, int $i, array $registries, array $factories = []): array
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

            // `...SomeActions::all()` — a registry spread — or `SomeActions::assign()`, one act of
            // it. Expanded to what it renders.
            if ($tokens[$i + 2][1] !== 'make' && isset($registries[$class])) {
                foreach (self::namesOfCall($registries[$class], $tokens[$i + 2][1], self::registries()) as $name) {
                    $names[] = [$name, $class.'::'.$tokens[$i + 2][1].'()'];
                }

                continue;
            }

            if ($tokens[$i + 2][1] !== 'make') {
                continue;
            }

            // `OpenRecordAction::make(Resource::class)` — a factory; its act is the one it declares.
            if (isset($factories[$class])) {
                $names[] = [$factories[$class], $class];

                continue;
            }

            if ($class === 'ActionGroup' || $class === 'BulkActionGroup') {
                foreach (self::groupMembers($tokens, $i, $count, $registries, $factories) as $member) {
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
    private static function groupMembers(array $tokens, int $i, int $count, array $registries, array $factories = []): array
    {
        for ($j = $i + 3; $j < $count; $j++) {
            $text = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];

            if ($text === '[') {
                return self::members($tokens, $j, $registries, $factories);
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
