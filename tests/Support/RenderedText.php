<?php

namespace Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * **The strings a person can actually READ on a screen, a document or a page — loaded, not grepped.**
 *
 * Two sweeps need the same sources and a third copy is what this class exists to stop:
 * the translation catalogues as VALUES (a translator reads values; a comment above one is not
 * output), the string LITERALS of a PHP file with comments tokenised away (a docblock may go on
 * discussing whatever it likes — the gate that fires on a sentence is the gate that gets weakened,
 * recorded three times in CLAUDE.md), and a Blade template with its `{{-- --}}` comments removed
 * (they never reach the browser; an HTML `<!-- -->` does, and stays).
 *
 * A CLASS, for the reason every other support helper here is one: a file-scope function declared
 * twice exits the suite 255 with no output.
 */
final class RenderedText
{
    /**
     * Every leaf string of every translation catalogue, keyed by `locale:file:dotted.key`,
     * grouped by locale — so a sweep can count PER LOCALE, never as a total (`lang/en` alone
     * clears any global floor while `lang/ar` silently stops being loaded).
     *
     * @return array<string, array<string, string>>
     */
    public static function catalogueStrings(): array
    {
        $byLocale = [];

        foreach (glob(lang_path('*'), GLOB_ONLYDIR) ?: [] as $localeDir) {
            $locale = basename($localeDir);
            $byLocale[$locale] ??= [];

            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($localeDir, FilesystemIterator::SKIP_DOTS));

            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $loaded = require $file->getPathname();

                if (! is_array($loaded)) {
                    continue;
                }

                $label = $locale.':'.substr($file->getPathname(), strlen(lang_path()) + 1);

                self::flatten($loaded, $label, $byLocale[$locale]);
            }
        }

        // JSON catalogues are the other half of Laravel's translation system — `__('Hello!')` in
        // a mail template reads `lang/ar.json`, not `lang/ar/`.
        foreach (glob(lang_path('*.json')) ?: [] as $jsonFile) {
            $locale = basename($jsonFile, '.json');
            $byLocale[$locale] ??= [];

            $loaded = json_decode((string) file_get_contents($jsonFile), true);

            if (is_array($loaded)) {
                self::flatten($loaded, $locale.':'.basename($jsonFile), $byLocale[$locale]);
            }
        }

        return $byLocale;
    }

    /**
     * Every string LITERAL in a PHP file, with comments and code tokenised away.
     *
     * Inline HTML (a Blade template is a PHP file to the tokeniser) has its Blade comments
     * removed first, because `{{-- --}}` is the one comment shape `token_get_all()` cannot see.
     *
     * @return array<int, array{0: int, 1: string}> line number and value
     */
    public static function stringLiterals(string $path): array
    {
        $found = [];

        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (! is_array($token)) {
                continue;
            }

            if ($token[0] === T_INLINE_HTML) {
                $found[] = [$token[2], self::withoutBladeComments($token[1])];
            } elseif (in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $found[] = [$token[2], $token[1]];
            }
        }

        return $found;
    }

    /** A Blade template as the browser receives it: `{{-- --}}` gone, everything else kept. */
    public static function withoutBladeComments(string $source): string
    {
        return (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);
    }

    /**
     * Every `.php` file under a directory, recursively.
     *
     * @return array<int, string>
     */
    public static function phpFilesUnder(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $paths = [];

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $paths[] = $file->getPathname();
            }
        }

        sort($paths);

        return $paths;
    }

    /** @param  array<string, mixed>  $node */
    private static function flatten(array $node, string $prefix, array &$out): void
    {
        foreach ($node as $key => $value) {
            $path = $prefix.':'.$key;

            if (is_array($value)) {
                self::flatten($value, $path, $out);
            } elseif (is_string($value)) {
                $out[$path] = $value;
            }
        }
    }
}
