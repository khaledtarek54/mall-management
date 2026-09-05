<?php

namespace Tests\Support;

use Filament\Actions\Imports\Importer as ImporterContract;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Throwable;

/**
 * **Every door onto a string column, with the length it will accept.**
 *
 * A door is a form field (`TextInput`/`Textarea`) or an importer column, and the question this
 * answers is the one SW-243 was found by: *does this door agree with the column, and with the
 * other doors onto it?* Two failures live here and they look nothing alike from inside either file.
 *
 * **A door WIDER than its column** validates a value the INSERT then refuses. Under an importer
 * that is a raw "Data too long for column" in `failed_import_rows` — a database message where a
 * field-level one belongs — and on a connection that is not strict it is a silently truncated
 * national ID or email address that nobody notices until a document bounces. Four shipped:
 * `charges.type` at 64 into a varchar(32), `employees.national_id` at 32 into 20, `employees.phone`
 * at 32 into 30, `vendors.email` at 255 into 200.
 *
 * **A form NARROWER than the importer beside it** is the lockout: the importer accepts a migrating
 * operator's real row, and the Edit page then refuses to save it, with a length message about data
 * the system itself put there and on a field nobody touched. `ad651fb4` fixed that for Tenant;
 * `LedgerAccount::code` had it too — a form capped at 20 over a `varchar(255)` whose importer
 * deliberately allows 32 while the 8-vs-10-digit chart question is open with the accountant, on
 * the one register a migrating operator is certain to import.
 *
 * **A CLASS, not file-scope helpers** — a helper declared in two test files is a fatal
 * redeclaration during collection that exits the suite 255 with no output at all.
 *
 * **Tokenised, not grepped.** A chain is terminated at the sibling comma at its own nesting depth,
 * so `->rules([...])` and nested closures belong to the field they are written on rather than
 * bleeding into the next one. The character-counting slicer that `MoneyDocumentDoors`' review
 * caught failing open on a `[` inside a string is exactly what this avoids.
 */
class FieldWidths
{
    /** Components that carry a `maxLength()` and bind to a column. */
    private const FORM_COMPONENTS = ['TextInput', 'Textarea'];

    /**
     * Files whose model cannot be resolved from where they sit, with the reason.
     *
     * A relation manager filed OUTSIDE a resource directory writes a relation whose owner is
     * decided by whichever page mounts it — several, for most of these — so there is no single
     * model to check the column against. Attributing them to a guess is how a gate comes to fire
     * on noise, and a gate that fires on noise gets weakened rather than fixed: the first pass of
     * this sweep read `ContactsRelationManager` as writing `Vendor` (its parent resource's model)
     * and reported a `vendors.email` divergence for a field that writes `vendor_contacts`.
     */
    public const UNRESOLVED_REASON = 'no owning resource directory — the relation'
        .' manager is mounted by more than one page, so its model is not a fact about its path';

    /** @return list<string> every PHP file under app/Filament */
    public static function filamentFiles(): array
    {
        $out = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('app/Filament'))) as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.php')) {
                $out[] = $f->getPathname();
            }
        }
        sort($out);

        return $out;
    }

    /**
     * Resource directory => model class, longest path first so the most specific wins.
     *
     * @return array<string, class-string<Model>>
     */
    public static function resourceDirectories(): array
    {
        static $map = null;

        if ($map !== null) {
            return $map;
        }

        $map = [];
        foreach (self::filamentFiles() as $path) {
            if (! str_ends_with($path, 'Resource.php')) {
                continue;
            }
            $class = self::classFor($path);
            if (! class_exists($class) || ! is_subclass_of($class, Resource::class)) {
                continue;
            }
            try {
                $map[dirname($path)] = $class::getModel();
            } catch (Throwable) {
                // A resource that cannot name its model is another gate's finding.
            }
        }
        krsort($map);

        return $map;
    }

    /**
     * Which model a form file writes.
     *
     * A relation manager writes the RELATED model, resolved through the relationship itself rather
     * than guessed from its name — `contacts` on `Vendor` is a `VendorContact`, and reading it off
     * the parent resource is the false attribution this gate exists not to make.
     *
     * @return class-string<Model>|null
     */
    public static function modelFor(string $path): ?string
    {
        $owner = null;
        foreach (self::resourceDirectories() as $dir => $model) {
            if (str_starts_with($path, $dir.DIRECTORY_SEPARATOR)) {
                $owner = $model;
                break;
            }
        }

        if ($owner === null) {
            return null;
        }

        $class = self::classFor($path);

        if (! class_exists($class) || ! is_subclass_of($class, RelationManager::class)) {
            return $owner;
        }

        $relationship = (new ReflectionClass($class))->getStaticPropertyValue('relationship', null);

        if (! is_string($relationship) || $relationship === '') {
            return null;
        }

        try {
            return (new $owner)->{$relationship}()->getRelated()::class;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Character widths of a model's string columns. `null` for a TEXT column, which has no width
     * a `maxLength` could exceed.
     *
     * @return array<string, int|null>
     */
    public static function columnWidths(string $model): array
    {
        static $cache = [];

        $table = (new $model)->getTable();

        if (isset($cache[$table])) {
            return $cache[$table];
        }

        $out = [];
        foreach (Schema::getColumns($table) as $column) {
            $type = (string) ($column['type'] ?? '');

            if (preg_match('/^(?:var)?char\s*\(\s*(\d+)\s*\)/i', $type, $m)) {
                $out[$column['name']] = (int) $m[1];
            } elseif (preg_match('/^(?:tiny|medium|long)?text$/i', $type)) {
                $out[$column['name']] = null;
            }
        }

        return $cache[$table] = $out;
    }

    /**
     * Every `Component::make('x')-> …` chain in a file, as its own text.
     *
     * @param  list<string>  $components
     * @return list<array{name: ?string, component: string, chain: string, line: int}>
     */
    public static function chains(string $code, array $components): array
    {
        $tokens = array_values(array_filter(
            token_get_all($code),
            fn ($t) => ! is_array($t) || ! in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true),
        ));

        $out = [];

        for ($i = 0; $i < count($tokens); $i++) {
            $token = $tokens[$i];

            if (! is_array($token) || $token[0] !== T_STRING || ! in_array($token[1], $components, true)) {
                continue;
            }
            if (($tokens[$i + 1][0] ?? null) !== T_DOUBLE_COLON
                || ($tokens[$i + 2][1] ?? null) !== 'make'
                || ($tokens[$i + 3] ?? null) !== '(') {
                continue;
            }

            $name = (is_array($tokens[$i + 4] ?? null) && $tokens[$i + 4][0] === T_CONSTANT_ENCAPSED_STRING)
                ? trim($tokens[$i + 4][1], "'\"")
                : null;

            // Walk to the end of the chain — the sibling comma at depth 0, never the next
            // component, so a nested closure's own commas cannot terminate it early.
            $depth = 0;
            $chain = [];
            for ($j = $i + 3; $j < count($tokens); $j++) {
                $t = $tokens[$j];

                if ($t === '(' || $t === '[') {
                    $depth++;
                } elseif ($t === ')' || $t === ']') {
                    $depth--;
                    if ($depth < 0) {
                        break;
                    }
                } elseif ($depth === 0 && ($t === ',' || $t === ';')) {
                    break;
                }

                $chain[] = is_array($t) ? $t[1] : $t;
            }

            $out[] = [
                'name' => $name,
                'component' => $token[1],
                'chain' => implode('', $chain),
                'line' => $token[2],
            ];
        }

        return $out;
    }

    /** @return list<string> the form components this sweep reads */
    public static function formComponents(): array
    {
        return self::FORM_COMPONENTS;
    }

    /**
     * Every importer on disk, path => the model it fills.
     *
     * On the class rather than in a test file: a file-scope helper declared in two test files is a
     * fatal redeclaration during collection, which exits the whole suite 255 with no output.
     *
     * @return array<string, class-string<Model>>
     */
    public static function importers(): array
    {
        $out = [];

        foreach (glob(base_path('app/Filament/Imports/*.php')) as $path) {
            $class = 'App\\Filament\\Imports\\'.basename($path, '.php');

            if (! class_exists($class) || ! is_subclass_of($class, ImporterContract::class)) {
                continue;
            }

            $out[$path] = $class::getModel();
        }

        return $out;
    }

    /**
     * Every form field that states a length, as model => column => list of [length, where].
     *
     * @return array<class-string<Model>, array<string, list<array{0: int, 1: string}>>>
     */
    public static function formLimits(): array
    {
        $out = [];

        foreach (self::filamentFiles() as $path) {
            $code = file_get_contents($path);

            if (! str_contains($code, 'TextInput::make(') && ! str_contains($code, 'Textarea::make(')) {
                continue;
            }

            $model = self::modelFor($path);

            if ($model === null) {
                continue;   // self::UNRESOLVED_REASON
            }

            foreach (self::chains($code, self::FORM_COMPONENTS) as $field) {
                $max = self::maxLengthOf($field['chain']);

                if ($field['name'] === null || $max === null) {
                    continue;
                }

                $where = str_replace(base_path().DIRECTORY_SEPARATOR, '', $path).':'.$field['line'];
                $out[$model][$field['name']][] = [$max, $where];
            }
        }

        return $out;
    }

    /**
     * The length a `->maxLength(...)` accepts, or null when it is not a value this can resolve.
     *
     * Literals and the `Model::CONST['key']` shape both resolve, because `Tenant::FIELD_MAX` is
     * exactly how this project states a shared width and a sweep that skipped it would be blind to
     * the five fields the SW-243 fix routed through it.
     */
    public static function maxLengthOf(string $chain): ?int
    {
        if (preg_match('/->maxLength\(\s*(\d+)\s*\)/', $chain, $m)) {
            return (int) $m[1];
        }

        if (preg_match('/->maxLength\(\s*([A-Za-z_\\\\]+)::([A-Z_][A-Z0-9_]*)\[[\'"](\w+)[\'"]\]\s*\)/', $chain, $m)) {
            return self::constantEntry($m[1], $m[2], $m[3]);
        }

        return null;
    }

    /** The length a `max:N` validation rule accepts, resolved the same two ways. */
    public static function maxRuleOf(string $chain): ?int
    {
        if (preg_match("/'max:(\d+)'/", $chain, $m)) {
            return (int) $m[1];
        }

        if (preg_match('/max:\'\.([A-Za-z_\\\\]+)::([A-Z_][A-Z0-9_]*)\[[\'"](\w+)[\'"]\]/', $chain, $m)) {
            return self::constantEntry($m[1], $m[2], $m[3]);
        }

        return null;
    }

    private static function constantEntry(string $class, string $constant, string $key): ?int
    {
        foreach ([$class, 'App\\Models\\'.$class] as $candidate) {
            if (defined($candidate.'::'.$constant)) {
                $value = constant($candidate.'::'.$constant);

                return is_array($value) && isset($value[$key]) ? (int) $value[$key] : null;
            }
        }

        return null;
    }

    private static function classFor(string $path): string
    {
        return 'App\\'.str_replace(
            [base_path('app').DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR, '.php'],
            ['', '\\', ''],
            $path,
        );
    }
}
