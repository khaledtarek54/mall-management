<?php

/**
 * The gate on a class reference that names nothing.
 *
 * WHY THIS EXISTS. `LeaseActions` called `app(ExerciseLeaseOptionService::class)` with no `use`
 * statement, so PHP resolved it against the file's own namespace and asked the container for
 * `App\Filament\Admin\Actions\ExerciseLeaseOptionService` — a class that has never existed. Opening
 * the Renew modal on any lease 500'd. The same file referenced `DB::table(...)` the same way, which
 * would have fataled the moment anyone opened Release Rentable Item (both fixed 2026-08-17).
 *
 * The reason it shipped is the interesting part: **an unimported name is valid PHP.** `php -l` is
 * happy, the file autoloads, the class constructs, and `::class` is just a string — nothing resolves
 * until the line actually runs. Both references sat inside CLOSURES (`fillForm`, `schema`) that only
 * execute when an operator opens the modal, so every test that built the actions passed.
 * `LeaseActionTopologyTest` enumerated all ten actions and asserted their shape without ever
 * evaluating one, which is exactly the blind spot: it checked the topology, and the bug was in the
 * bodies.
 *
 * So the property worth gating is not "the modal works" — it is the far cheaper, far broader one
 * underneath it: **every class this codebase names by symbol must resolve.** That is decidable
 * statically, costs no database and no boot, and catches the next one on any code path, closure or
 * not, before anyone clicks anything.
 *
 * Four forms name a class by symbol and all four are read: `Foo::`, `new Foo`, `instanceof Foo`,
 * and a TYPE DECLARATION (`fn (Foo $x)`, `private Foo $y`, `catch (Foo $e)`). The last was added
 * after a type-hinted closure parameter with no import shipped and 500'd the utility-meters list
 * while this gate was green — a gate reading three of the four ways is a gate that reports
 * coverage it does not have, which is worse than no gate for exactly the reason the class
 * docblock above gives.
 *
 * If you are here because this failed: you referenced a class the file never imported. Add the
 * `use` statement — do not fully-qualify it inline, which is how the two above read as deliberate.
 */

use Illuminate\Support\Str;

it('resolves every class named by symbol under app/', function () {
    /**
     * Resolve a name to the ONE class PHP would actually look for at runtime.
     *
     * The subtlety that decides whether this gate works: **class names do not fall back to the
     * global namespace.** An unqualified `Foo` inside `namespace App\Bar` means `App\Bar\Foo` and
     * nothing else — unlike a function or a constant, which do fall back. Laravel's `aliases` config
     * registers `DB`, `Str`, `Log` and friends as real classes in the global namespace, so a
     * resolver that tries `$name` as a fallback finds them and reports a file referencing a bare
     * `DB::table()` from inside a namespace as fine. It is not fine — that is the second half of the
     * bug this gate was written for, and an earlier draft of this closure sailed straight past it.
     *
     * @param  array<string, string>  $uses  alias => FQCN
     */
    $resolveTo = function (string $name, string $namespace, array $uses): string {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        [$head, $rest] = array_pad(explode('\\', $name, 2), 2, null);

        if (isset($uses[$head])) {
            return $rest === null ? $uses[$head] : $uses[$head].'\\'.$rest;
        }

        return $namespace ? $namespace.'\\'.$name : $name;
    };

    /**
     * Every class-shaped reference in a file, as [name, line], paired with the file's namespace and
     * alias table. Reads tokens rather than regex so a class name inside a string or a comment is
     * never mistaken for a reference.
     *
     * @return array{namespace: string, uses: array<string, string>, refs: array<int, array{0: string, 1: int}>}
     */
    $parse = function (string $source): array {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $namespace = '';
        $uses = [];
        $refs = [];

        $skipWhitespace = function (int $i) use ($tokens, $count): int {
            while ($i < $count && is_array($tokens[$i]) && in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $i++;
            }

            return $i;
        };

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token)) {
                continue;
            }

            // ---- namespace Foo\Bar;
            if ($token[0] === T_NAMESPACE) {
                $buffer = '';

                for ($j = $i + 1; $j < $count; $j++) {
                    if ($tokens[$j] === ';' || $tokens[$j] === '{') {
                        break;
                    }
                    if (is_array($tokens[$j]) && in_array($tokens[$j][0], [T_STRING, T_NAME_QUALIFIED, T_NS_SEPARATOR], true)) {
                        $buffer .= $tokens[$j][1];
                    }
                }

                $namespace = trim($buffer, '\\');

                continue;
            }

            // ---- use Foo\Bar; / use Foo\Bar as Baz; / use Foo\{Bar, Baz};
            if ($token[0] === T_USE) {
                $next = $skipWhitespace($i + 1);

                // A closure's `use (...)` and a trait's `use Foo;` inside a class body are not imports.
                // The trait case is harmless (it names a real trait), the closure case must be skipped.
                if (($tokens[$next] ?? null) === '(') {
                    continue;
                }

                $buffer = '';

                for ($j = $next; $j < $count; $j++) {
                    if ($tokens[$j] === ';') {
                        break;
                    }
                    $buffer .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                }

                // `use function` / `use const` import symbols that are not classes.
                if (Str::startsWith(ltrim($buffer), ['function ', 'const '])) {
                    continue;
                }

                // Group imports: `Foo\{Bar, Baz as Qux}` — flatten to the individual clauses.
                $prefix = '';
                if (preg_match('/^\s*(.+?)\\\\\{(.+)\}\s*$/s', $buffer, $m)) {
                    $prefix = trim($m[1], '\\').'\\';
                    $buffer = $m[2];
                }

                foreach (explode(',', $buffer) as $clause) {
                    $clause = trim($clause);

                    if ($clause === '') {
                        continue;
                    }

                    if (preg_match('/^(.+?)\s+as\s+(.+)$/is', $clause, $m)) {
                        $uses[trim($m[2])] = $prefix.trim($m[1], '\\');

                        continue;
                    }

                    $fqcn = $prefix.trim($clause, '\\');
                    $uses[Str::afterLast($fqcn, '\\')] = $fqcn;
                }

                continue;
            }

            // ---- A TYPE DECLARATION: `fn (Foo $x)`, `private Foo $y;`, `catch (Foo $e)`
            //
            // The fourth way PHP resolves a class name by symbol, and the one this gate did not
            // read until a `TextColumn->state(fn (UtilityMeter $record) => …)` with no import
            // shipped and 500'd the whole utility-meters list. A type hint fails at CALL time, in
            // the same closures the `::`-form fails in, and reads identically in review — the file
            // says `UtilityMeter` and means `App\Filament\…\Tables\UtilityMeter`.
            //
            // A type is the run of names immediately before a variable, so union and intersection
            // members are each resolved: `A|B $x` must resolve BOTH, or the gate passes on the
            // half it happened to look at.
            if ($token[0] === T_VARIABLE) {
                $at = $i - 1;
                $names = [];
                $seenName = false;

                while ($at >= 0) {
                    $prev = $tokens[$at];

                    if (is_array($prev) && in_array($prev[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                        $at--;

                        continue;
                    }

                    // `&$ref` and `...$variadic` sit between the type and the variable. They are
                    // safe to step over BEFORE a name because a name must still follow to collect
                    // anything — `$a & $b` walks back to a T_VARIABLE and stops with nothing.
                    if (! $seenName && (in_array($prev, ['&'], true) || (is_array($prev) && $prev[0] === T_ELLIPSIS))) {
                        $at--;

                        continue;
                    }

                    // `?` and `|` / `&` may only continue a run that has ALREADY produced a name.
                    // This is the whole difference between a nullable type and a ternary: `?Foo $x`
                    // puts the `?` BEFORE the name, while `$a->notes ? $b : $c` puts it after — and
                    // reading `?` as a nullable marker in the leading position made the gate report
                    // every ternary whose left side ends in a property as a missing import.
                    if ($seenName && in_array($prev, ['?', '|', '&'], true)) {
                        $at--;

                        continue;
                    }

                    if (is_array($prev) && in_array($prev[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                        // A property or class constant, not a type: `$x->notes`, `Foo::BAR`. The
                        // class half of `Foo::BAR` is resolved by the `::` branch below.
                        $before = $at - 1;
                        while ($before >= 0 && is_array($tokens[$before]) && in_array($tokens[$before][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                            $before--;
                        }
                        $beforeToken = $tokens[$before] ?? null;
                        if (is_array($beforeToken) && in_array($beforeToken[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
                            break;
                        }

                        $names[] = [$prev[1], $prev[2]];
                        $seenName = true;
                        $at--;

                        continue;
                    }

                    break;
                }

                foreach ($names as [$name, $line]) {
                    // Builtin types and relative-scope keywords name no class.
                    if (in_array(strtolower($name), [
                        'int', 'float', 'string', 'bool', 'array', 'object', 'callable', 'iterable',
                        'mixed', 'void', 'never', 'null', 'false', 'true', 'self', 'static', 'parent',
                    ], true)) {
                        continue;
                    }

                    $refs[] = [$name, $line];
                }

                continue;
            }

            // ---- Foo::bar() / Foo::class / new Foo / $x instanceof Foo
            $isStatic = $token[0] === T_DOUBLE_COLON;
            $isPrefixed = in_array($token[0], [T_NEW, T_INSTANCEOF], true);

            if (! $isStatic && ! $isPrefixed) {
                continue;
            }

            $at = $isStatic ? $i - 1 : $skipWhitespace($i + 1);

            if ($isStatic) {
                while ($at >= 0 && is_array($tokens[$at]) && $tokens[$at][0] === T_WHITESPACE) {
                    $at--;
                }
            }

            $subject = $tokens[$at] ?? null;

            // `new $class`, `new (expr)`, `$obj::` — nothing named, nothing to resolve.
            if (! is_array($subject)) {
                continue;
            }

            if (! in_array($subject[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            // `new class {...}` is an anonymous class, not a reference.
            if ($subject[0] === T_STRING && strtolower($subject[1]) === 'class') {
                continue;
            }

            if (in_array(strtolower($subject[1]), ['self', 'static', 'parent'], true)) {
                continue;
            }

            $refs[] = [$subject[1], $subject[2]];
        }

        return ['namespace' => $namespace, 'uses' => $uses, 'refs' => $refs];
    };

    $files = collect(
        iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())))
    )->filter(fn (SplFileInfo $f) => $f->isFile() && $f->getExtension() === 'php');

    expect($files)->not->toBeEmpty('the sweep found no PHP files — it is testing nothing');

    $unresolved = [];
    $checked = 0;

    foreach ($files as $file) {
        ['namespace' => $namespace, 'uses' => $uses, 'refs' => $refs] = $parse(file_get_contents($file->getPathname()));

        foreach ($refs as [$name, $line]) {
            $checked++;

            $fqcn = $resolveTo($name, $namespace, $uses);

            if (! class_exists($fqcn) && ! interface_exists($fqcn) && ! trait_exists($fqcn) && ! enum_exists($fqcn)) {
                $unresolved[] = Str::after($file->getPathname(), base_path().'/').':'.$line.'  '.$name;
            }
        }
    }

    // The sweep must have found references at all — a parser that silently matched nothing would
    // pass this test forever while gating precisely nothing (the failure mode
    // ActivityLogVocabularyConformanceTest was green under for a year).
    expect($checked)->toBeGreaterThan(1_000, 'the reference sweep matched almost nothing — it is not reading the code');

    expect($unresolved)->toBe([], "these names resolve to a class that does not exist — add the missing `use`:\n  ".implode("\n  ", $unresolved));
});
