<?php

namespace App\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * A field on a modal that the write does not carry is a control that saves NOTHING.
 *
 * It renders, it validates, the operator fills it in — and the record keeps none of it. That is
 * strictly worse than not offering the field, because the operator has been told the answer was
 * taken. It is also invisible from either half: the schema is correct, the `create()` is correct,
 * and only the pair is wrong.
 *
 * Shipped twice in three days. `LeaseActions::recordDeposit()` got a bank-account picker whose value
 * never reached `DepositTransaction::create()` — caught in review, before it left. The sibling
 * failure one layer down is already pinned for the two SERVICE writers in
 * `TwoBanksInOneMallReconcileSeparatelyTest` ("a field added to the form and not to the service is a
 * control that saves NOTHING"); this is the same trap in an action's own closure.
 *
 * ## Why this must be TOKENISED, not grepped
 *
 * `LeaseActions` declares **thirteen** actions in one file. A file-wide comparison of "fields
 * declared" against "keys written" reports every field of every other action as dropped — measured,
 * it produced a 29-name list of pure noise and two false positives elsewhere. The question is only
 * meaningful per ACTION, which means finding each `Action::make()`'s balanced extent, and that needs
 * the tokenizer. Same reasoning as `Tests\Support\ActionStrips`, for the same file.
 *
 * **The `::` trap:** PHP returns `T_DOUBLE_COLON` as an ARRAY (`[T_DOUBLE_COLON, '::']`), not the
 * plain string `'::'`. A `$tokens[$i + 1] !== '::'` test therefore matches nothing at all — the
 * first cut of this scanned 0 actions and reported a clean sweep, which is this codebase's signature
 * failure: a gate reporting on a set it silently never collected.
 *
 * ## What it can and cannot see
 *
 * Only an action that builds its row INLINE (`Model::create([…])` inside its own chain) can be asked
 * this question. An action that hands its data to a service is a different shape and is covered by
 * that service's own tests; a resource form is saved by Filament from the whole schema and cannot
 * drop a field this way. So a clean sweep here is a statement about inline builders, and
 * {@see scan()} reports how many it examined so the count can be asserted rather than assumed.
 */
final class ModalFieldReach
{
    /**
     * Filament components that ASK for a value.
     *
     * A display-only component (`Placeholder`, `TextEntry`) collects nothing and must not be read as
     * a dropped field; a layout component (`Section`, `Grid`) has no state of its own.
     */
    private const ASKING_COMPONENTS = [
        'Select', 'Radio', 'ToggleButtons', 'TextInput', 'DatePicker', 'DateTimePicker', 'TimePicker',
        'Textarea', 'Toggle', 'Checkbox', 'MonthPicker', 'EntitySelect', 'FileUpload', 'RichEditor',
        'ColorPicker', 'KeyValue',
    ];

    /**
     * Fields an action deliberately collects without persisting them on the row it builds, with why.
     *
     * A field can legitimately drive the ACT rather than the RECORD — a confirmation tick, a
     * "also send an email" switch, a quantity the closure spends on something else. Each such field
     * is registered here so the claim is reviewable, keyed `path.php::action::field`.
     *
     * Empty, and that is the intended state.
     *
     * @var array<string, string>
     */
    public const COLLECTS_WITHOUT_PERSISTING = [];

    /**
     * Every inline-building action on disk, with the fields it asks for and the keys it writes.
     *
     * @return array<int, array{file: string, action: string, model: string, asks: array<int, string>, writes: array<int, string>, dropped: array<int, string>}>
     */
    public static function scan(): array
    {
        $found = [];

        foreach (self::filamentFiles() as $path => $source) {
            if (! str_contains($source, '::create([')) {
                continue;
            }

            foreach (self::actionsIn($source) as $action) {
                if (! preg_match('/([A-Z][A-Za-z]+)::create\(\[(.*?)\]\)/s', $action['text'], $create)) {
                    continue;
                }

                $asks = self::fieldsIn($action['text']);

                if ($asks === []) {
                    continue;
                }

                preg_match_all("/'([a-z0-9_]+)'\s*=>/", $create[2], $keys);
                $writes = $keys[1] ?? [];

                $found[] = [
                    'file' => $path,
                    'action' => $action['name'],
                    'model' => $create[1],
                    'asks' => $asks,
                    'writes' => $writes,
                    'dropped' => array_values(array_diff($asks, $writes)),
                ];
            }
        }

        return $found;
    }

    /** @return array<int, string> */
    private static function fieldsIn(string $text): array
    {
        $names = [];

        foreach (self::ASKING_COMPONENTS as $component) {
            if (preg_match_all('/'.$component."::make\\('([a-z0-9_]+)'\\)/", $text, $m)) {
                $names = array_merge($names, $m[1]);
            }
        }

        // `BankAccountField::for(X::class)` names its own column rather than taking one.
        if (preg_match('/BankAccountField::(for|make)\(/', $text)) {
            $names[] = 'bank_account_id';
        }

        return array_values(array_unique($names));
    }

    /**
     * Each `Action::make('name')` and the source of its whole chain.
     *
     * The extent ends where the chain does: depth returns to zero AND the next meaningful token is
     * not `->`. Without that second condition every action would end at its first closing bracket,
     * which is `make('name')` itself.
     *
     * @return array<int, array{name: string, text: string}>
     */
    private static function actionsIn(string $source): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $actions = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token) || $token[0] !== T_STRING || ! str_ends_with($token[1], 'Action')) {
                continue;
            }

            // `T_DOUBLE_COLON` is an ARRAY, not the string '::' — see the class docblock.
            if (self::text($tokens[$i + 1] ?? '') !== '::' || self::text($tokens[$i + 2] ?? '') !== 'make') {
                continue;
            }

            $name = null;

            for ($j = $i + 3; $j < min($i + 9, $count); $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_CONSTANT_ENCAPSED_STRING) {
                    $name = trim($tokens[$j][1], "'\"");

                    break;
                }
            }

            if ($name === null) {
                continue;
            }

            $actions[] = ['name' => $name, 'text' => self::extent($tokens, $i, $count)];
        }

        return $actions;
    }

    /** @param array<int, mixed> $tokens */
    private static function extent(array $tokens, int $start, int $count): string
    {
        $depth = 0;
        $end = $count - 1;

        for ($j = $start; $j < $count; $j++) {
            $text = self::text($tokens[$j]);

            if ($text === '(' || $text === '[' || $text === '{') {
                $depth++;

                continue;
            }

            if ($text !== ')' && $text !== ']' && $text !== '}') {
                continue;
            }

            $depth--;

            if ($depth > 0) {
                continue;
            }

            $next = $j + 1;

            while ($next < $count && is_array($tokens[$next])
                && in_array($tokens[$next][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $next++;
            }

            // The chain continues — `->schema(…)->action(…)` — so this bracket was not the end.
            if ($next < $count && is_array($tokens[$next]) && $tokens[$next][0] === T_OBJECT_OPERATOR) {
                $depth = 0;

                continue;
            }

            $end = $j;

            break;
        }

        $source = '';

        for ($j = $start; $j <= $end; $j++) {
            $source .= self::text($tokens[$j]);
        }

        return $source;
    }

    private static function text(mixed $token): string
    {
        return is_array($token) ? $token[1] : (string) $token;
    }

    /** @return array<string, string> relative path => source */
    private static function filamentFiles(): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Filament')));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[str_replace(base_path().'/', '', $file->getPathname())] = (string) file_get_contents($file->getPathname());
            }
        }

        ksort($files);

        return $files;
    }
}
