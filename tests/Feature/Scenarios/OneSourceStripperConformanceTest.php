<?php

use App\Support\PhpSource;

/*
|--------------------------------------------------------------------------
| ONE tokenizer strips comments out of source (2026-09-12)
|--------------------------------------------------------------------------
| Measured before `App\Support\PhpSource` existed: twenty-four copies of a comments-out-of-source
| function across twenty-two files, under ten names — eleven REMOVED comment tokens, three
| replaced each with a single space, ten blanked them to their own length — so a gate written
| against one and run against another reported positions off by every comment above them, and a
| fixed-width lookahead tuned to one semantic broke under the other (two such windows were found
| by the review of this change). `TestHelperUniquenessConformanceTest` refuses a duplicate NAME,
| and the reflex that produced was to rename the copy rather than reuse the original.
|
| The rule is about SHAPE, not names: a function that turns `token_get_all()` back into a STRING
| while dropping comment tokens is a stripper and must be `PhpSource`. A token WALK — a reader that
| indexes the token array to follow nesting and steps past comment tokens in place
| (`ModalFieldReach`, `UnresolvedClassReference…`, `TestHelperUniqueness…`) — is a different
| thing and is left alone. The tell is string REASSEMBLY beside the comment-token test; a walker
| that later concatenates a chain it extracted (`FieldWidths::chains()`) is outside that window
| and is the known edge of this gate — it filters comments out of the token ARRAY, which is a
| walker's shape, and reassembles later.
*/

/** Every PHP file under app/ and tests/, except the seam itself. */
function sourceStripperCandidates(): array
{
    $files = [];

    foreach (['app', 'tests'] as $root) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($root))) as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $files[] = $f->getPathname();
            }
        }
    }

    sort($files);

    return array_values(array_filter($files, fn (string $f): bool => $f !== app_path('Support/PhpSource.php')));
}

it('has exactly one function that strips comments out of PHP source', function () {
    $offenders = [];
    $walkers = 0;

    foreach (sourceStripperCandidates() as $file) {
        $raw = (string) file_get_contents($file);

        // Cheap pre-filter on the raw text (2,860 files); the tokenizer runs on the few that
        // name the token id at all.
        if (! str_contains($raw, 'T_DOC_COMMENT')) {
            continue;
        }

        // Read with the seam itself, strings blanked too — a docblock DESCRIBING the pattern is
        // not the pattern, and neither is a gate naming it inside a string (this file does).
        $source = PhpSource::withoutCommentsOrStrings($raw);

        if (! str_contains($source, 'T_DOC_COMMENT')) {
            continue;
        }

        // A stripper rebuilds a STRING while skipping comment tokens: the tell is a comment-token
        // test beside string reassembly — any `.=` (`$out .= $t[1]`, `$out .= is_array($t) ? …`),
        // `implode(`/`join(`/`array_reduce(`/`sprintf(`, `substr_replace(`, `str_repeat(' '` — or
        // a `->reject(…)->map(…)->implode('')` chain, within the same few lines. A walker tests
        // the same token ids to STEP PAST them and never reassembles there. (The first cut's tell
        // was `.= $var;` and missed `.= is_array(...)`, one of the twenty-four it replaced.)
        $lines = explode("\n", $source);
        $isStripper = false;

        foreach ($lines as $i => $line) {
            if (! str_contains($line, 'T_DOC_COMMENT')) {
                continue;
            }

            $window = implode("\n", array_slice($lines, max(0, $i - 6), 14));

            if (preg_match('/\.=|\bimplode\(|\bjoin\(|\barray_reduce\(|\bsprintf\(|substr_replace\(|str_repeat\(\s*\'\s\'|->implode\(/', $window)) {
                $isStripper = true;

                break;
            }
        }

        if ($isStripper) {
            $offenders[] = str_replace(base_path().'/', '', $file).' rebuilds comment-free source itself — use PhpSource::withoutComments()';
        } else {
            $walkers++;
        }
    }

    // Premise: the walkers still exist and were examined — seven on the day this was written.
    expect($walkers)->toBeGreaterThanOrEqual(5, 'The sweep found almost no token-walkers; it is reading the wrong tree.');

    expect($offenders)->toBe([], implode("\n  ", $offenders));
});

it('keeps offsets and line numbers, so a position measured on the blanked text points into the file', function () {
    $source = "<?php\n// one\n\$a = 1; /* two\nlines */ \$b = 'x // not a comment';\n/** doc */\nfunction f() {}\n";

    $blanked = PhpSource::withoutComments($source);

    expect(strlen($blanked))->toBe(strlen($source))
        ->and(substr_count($blanked, "\n"))->toBe(substr_count($source, "\n"))
        ->and($blanked)->not->toContain('one')->not->toContain('two')->not->toContain('doc')
        ->and($blanked)->toContain("'x // not a comment'")
        ->and(strpos($blanked, 'function f'))->toBe(strpos($source, 'function f'));
});

it('blanks string contents but keeps their quotes, so code is read and prose is not', function () {
    $source = "<?php\n\$m = 'Cache::lock() is mentioned'; Cache::lock('k');\n";

    $bare = PhpSource::withoutCommentsOrStrings($source);

    expect(strlen($bare))->toBe(strlen($source))
        ->and(substr_count($bare, 'Cache::lock('))->toBe(1)
        ->and($bare)->toContain("\$m = '");
});
