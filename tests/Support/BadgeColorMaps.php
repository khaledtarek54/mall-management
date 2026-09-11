<?php

namespace Tests\Support;

use App\Support\ValueSets;
use Filament\Facades\Filament;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Throwable;

/**
 * **Every place the panel decides what colour a classification value renders in, read off the
 * source.**
 *
 * Two shapes are found on a `X::make('column')` chain: an inline `->color(fn … => match ($state)
 * { … })` map, and a `->color(BadgeColors::of('set'))` read of the registry. Each is attributed to
 * a `ValueSets` key — by the FILE's model where that is a fact about the path (a resource's own
 * directory, or a relation manager resolved through the relationship it declares), and only then
 * by the values the map names, because two vocabularies can share every value (`expenses.status`
 * and `deposit_transactions.status` both hold `recorded | cancelled`; `vendor_contracts.status`
 * overlaps `leases.status`) and the first measurement of this attributed all three to the wrong
 * set. A dotted column (`payroll.status` on a payslip row) is attributed by values, since the
 * table prefix is the wrong answer for it by construction.
 *
 * **Tokenised, not grepped.** A regex from `::make('status')` to the next `->color(` reads straight
 * through the sibling columns in between and reported nine maps where there were fifty — the same
 * trap {@see ActionStrips} records. `token_get_all()` gives the real chain.
 *
 * A CLASS, for the reason every other support helper here is one: a file-scope function declared
 * twice exits the suite 255 with no output.
 */
final class BadgeColorMaps
{
    /** The components whose `->color()` renders a classification badge. */
    public const COMPONENTS = ['TextColumn', 'IconColumn', 'BadgeColumn', 'SelectColumn', 'TextEntry', 'IconEntry'];

    /** Every PHP file under the panel trees. */
    public static function files(): array
    {
        $files = [];

        foreach ([app_path('Filament'), app_path('Support/Filament'), app_path('Livewire')] as $dir) {
            if (! is_dir($dir)) {
                continue;
            }

            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $f) {
                if ($f->isFile() && $f->getExtension() === 'php') {
                    $files[] = $f->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * Every colour decision in the panel.
     *
     * @return array<int, array{file: string, line: int, column: string, kind: 'inline'|'registry', set: ?string, keys: array<int, string>}>
     */
    public static function scan(): array
    {
        $sets = self::sets();
        $tables = self::tablesByFile();
        $found = [];

        foreach (self::files() as $file) {
            $src = self::withoutComments((string) file_get_contents($file));
            $imports = self::imports($src);
            $relative = str_replace(base_path().'/', '', $file);

            foreach (self::chains($src) as $chain) {
                $decision = self::colourOf($chain['text'], $imports);

                if ($decision === null) {
                    continue;
                }

                $table = str_contains($chain['column'], '.') ? null : ($tables[$file] ?? null);

                if ($decision['kind'] === 'registry') {
                    $set = $decision['set'];
                } elseif ($table !== null) {
                    $set = isset($sets[$table.'.'.$chain['column']]) ? $table.'.'.$chain['column'] : null;

                    // The file's model says which table; the map must then fit that table's set,
                    // or it is colouring something this attribution cannot name.
                    if ($set !== null && count(array_intersect($decision['keys'], $sets[$set])) !== count($decision['keys'])) {
                        $set = null;
                    }
                } else {
                    $set = self::byValues($decision['keys'], $chain['column'], $sets);
                }

                $found[] = [
                    'file' => $relative,
                    'line' => $chain['line'],
                    'column' => $chain['column'],
                    'kind' => $decision['kind'],
                    'set' => $set,
                    'keys' => $decision['keys'],
                    'table' => $table,
                ];
            }
        }

        return $found;
    }

    /**
     * Every `ValueSets` set, values stringified.
     *
     * @return array<string, array<int, string>>
     */
    public static function sets(): array
    {
        $sets = [];

        foreach (array_keys(ValueSets::SETS) as $key) {
            [$table, $column] = explode('.', $key, 2);
            $sets[$key] = array_map(
                fn ($v) => $v instanceof \BackedEnum ? $v->value : (string) $v,
                ValueSets::allowed($table, $column) ?? [],
            );
        }

        return $sets;
    }

    /**
     * The table a file's rows come from, where the path says so.
     *
     * @return array<string, string> absolute file → table
     */
    private static function tablesByFile(): array
    {
        $byDir = [];
        $byFile = [];

        foreach (Filament::getPanels() as $panel) {
            try {
                $resources = $panel->getResources();
            } catch (Throwable) {
                continue;
            }

            foreach ($resources as $resource) {
                $model = $resource::getModel();
                $byDir[dirname((string) (new ReflectionClass($resource))->getFileName())] = (new $model)->getTable();

                foreach ($resource::getRelations() as $relation) {
                    $manager = is_string($relation)
                        ? $relation
                        : (method_exists($relation, 'getManager') ? $relation->getManager() : null);

                    if (! is_string($manager) || ! class_exists($manager)) {
                        continue;
                    }

                    $reflection = new ReflectionClass($manager);

                    if (! $reflection->hasProperty('relationship')) {
                        continue;
                    }

                    $property = $reflection->getProperty('relationship');
                    $property->setAccessible(true);

                    try {
                        $byFile[(string) $reflection->getFileName()] = (new $model)->{$property->getValue()}()->getRelated()->getTable();
                    } catch (Throwable) {
                        continue;
                    }
                }
            }
        }

        $out = $byFile;

        foreach (self::files() as $file) {
            if (isset($out[$file])) {
                continue;
            }

            foreach ($byDir as $dir => $table) {
                if (str_starts_with($file, $dir.'/')) {
                    $out[$file] = $table;

                    break;
                }
            }
        }

        return $out;
    }

    /** The set whose values contain every key, preferring one named after the column. */
    private static function byValues(array $keys, string $column, array $sets): ?string
    {
        if ($keys === []) {
            return null;
        }

        $best = null;
        $bestScore = -INF;

        foreach ($sets as $set => $allowed) {
            if (count(array_intersect($keys, $allowed)) !== count($keys)) {
                continue;
            }

            $score = (str_ends_with($set, '.'.$column) ? 5 : 0) - count($allowed) / 100;

            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $set;
            }
        }

        return $best;
    }

    /**
     * Each badge-bearing `X::make('column')` chain in a file, with its full text.
     *
     * @return array<int, array{column: string, line: int, text: string}>
     */
    private static function chains(string $src): array
    {
        $tokens = token_get_all($src);
        $n = count($tokens);
        $out = [];

        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];

            if (! is_array($t) || $t[0] !== T_STRING || ! in_array($t[1], self::COMPONENTS, true)) {
                continue;
            }

            if (! isset($tokens[$i + 2]) || ! is_array($tokens[$i + 1]) || $tokens[$i + 1][0] !== T_DOUBLE_COLON
                || ! is_array($tokens[$i + 2]) || $tokens[$i + 2][1] !== 'make') {
                continue;
            }

            if (! isset($tokens[$i + 4]) || ! is_array($tokens[$i + 4]) || $tokens[$i + 4][0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $depth = 0;
            $text = '';

            for ($j = $i; $j < $n; $j++) {
                $s = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];

                if ($s === '(' || $s === '[' || $s === '{') {
                    $depth++;
                } elseif ($s === ')' || $s === ']' || $s === '}') {
                    if ($depth === 0) {
                        break;
                    }
                    $depth--;
                } elseif (($s === ',' || $s === ';') && $depth === 0) {
                    break;
                }

                $text .= $s;
            }

            $out[] = ['column' => trim($tokens[$i + 4][1], "'\""), 'line' => $t[2], 'text' => $text];
        }

        return $out;
    }

    /**
     * What one chain's `->color()` decides, if it decides by classification value.
     *
     * @return array{kind: 'inline'|'registry', set: ?string, keys: array<int, string>}|null
     */
    private static function colourOf(string $chain, array $imports): ?array
    {
        if (preg_match('/->color\(\s*BadgeColors::of\(\s*\'([a-z_.]+)\'\s*\)\s*\)/', $chain, $m)) {
            return ['kind' => 'registry', 'set' => $m[1], 'keys' => []];
        }

        if (! preg_match('/->color\(\s*(?:static\s+)?fn\s*\([^)]*\)\s*(?::\s*\??[\w|]+)?\s*=>\s*match\s*\(\s*\$\w+\s*\)\s*\{(.*?)\n\s*\}\s*\)/s', $chain, $m)) {
            return null;
        }

        $keys = [];

        foreach (preg_split('/,\s*\n/', $m[1]) as $arm) {
            $arm = trim($arm, " ,\n\t");

            if ($arm === '' || ! str_contains($arm, '=>')) {
                continue;
            }

            [$k] = explode('=>', $arm, 2);

            foreach (array_map('trim', explode(',', $k)) as $kk) {
                if ($kk === 'default') {
                    continue;
                }

                if (preg_match('/^[\'"](.*)[\'"]$/', $kk, $q)) {
                    $keys[] = $q[1];

                    continue;
                }

                if (preg_match('/^(\w+)::(\w+)$/', $kk, $c)) {
                    $class = $imports[$c[1]] ?? ('App\\Models\\'.$c[1]);

                    if (defined("{$class}::{$c[2]}")) {
                        $v = constant("{$class}::{$c[2]}");
                        $keys[] = $v instanceof \BackedEnum ? $v->value : (string) $v;

                        continue;
                    }
                }

                // A key this reader cannot resolve makes the whole map unattributable — reported
                // as inline with no set, never silently dropped.
                $keys[] = '?'.$kk;
            }
        }

        return ['kind' => 'inline', 'set' => null, 'keys' => array_values(array_unique($keys))];
    }

    /** @return array<string, string> alias → FQCN */
    private static function imports(string $src): array
    {
        preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+(\w+))?;/m', $src, $uses, PREG_SET_ORDER);
        $imports = [];

        foreach ($uses as $u) {
            $imports[$u[2] ?? substr((string) strrchr('\\'.$u[1], '\\'), 1)] = $u[1];
        }

        return $imports;
    }

    /** Comments blanked to spaces — offsets intact, a docblock naming `match` invisible. */
    private static function withoutComments(string $source): string
    {
        $out = $source;

        foreach (token_get_all($source) as $token) {
            if (! is_array($token) || ! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $at = strpos($out, $token[1]);

            if ($at !== false) {
                $out = substr_replace($out, str_repeat(' ', strlen($token[1])), $at, strlen($token[1]));
            }
        }

        return $out;
    }
}
