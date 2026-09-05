<?php

/**
 * **A MONEY-DOCUMENT LINE STORES A KEY, AND EVERY KEY IS REAL IN BOTH LANGUAGES.** — UX-30
 *
 * The gate for {@see LineNarrative}, and the same three teeth
 * `JournalNarrativeIsAKeyNotProseTest` and `LeaseEventNarrativeIsAKeyNotProseTest` carry, because
 * all three enforce one rule: **a row stores DATA, never PROSE.**
 *
 *  1. Every registered key resolves in EN and AR with no leftover `:placeholder`. A narrative
 *     missing its Arabic renders English to an Arabic reader silently — `Lang::has()` falls back
 *     unless told not to — and a leftover `:balance` on a tax invoice reads as a broken template.
 *  2. Every registered key has a WRITER. `LeaseEventNarrative` shipped `rent_escalated`
 *     catalogued in both languages while the sweep beside it stored English: a sentence nobody
 *     writes is one nobody reads, and it looks like coverage.
 *  3. No service raising a money-document line stores prose without a key. The exemptions are
 *     named with reasons, and a stale one fails.
 */

use App\Support\LineNarrative;
use Illuminate\Support\Facades\Lang;

/**
 * Services that write a line WITHOUT a narrative key, each with the reason it is right.
 *
 * The test is `description` composed by the service itself. A line whose text is a value somebody
 * TYPED — an operator's own invoice line, an imported opening balance, a purchase-request line —
 * is not a template and must never grow one: the whole point of the floor is that a person's own
 * words survive.
 */
const LINE_PROSE_EXEMPT = [
    // The operator types this one, on the invoice form. There is no template to key.
    'app/Filament/Admin/Resources/Invoices/Schemas/InvoiceForm.php' => 'operator-typed, and the'
        .' repeater prefills a charge NAME, which is also the operator\'s own words',
    // A migrating operator's own file. Inventing a key for a sentence their previous system wrote
    // would put words in their mouth on a document that is already evidence.
    'app/Services/Accounting/ImportOpeningBalancesService.php' => 'the line text comes from the'
        .' imported row — it is the operator\'s own description of their own opening balance',
    // A move-out settlement statement line, worded by whoever settles it.
    'app/Services/SettleMoveOutService.php' => 'the description is passed in by the caller from the'
        .' final-account form, which is a person describing this particular settlement',
    // Not a document line at all: this is the `items` array of a PAYMENT-GATEWAY request. Paymob
    // renders it in their own checkout, and it is matched here only because it has the same shape.
    'app/Services/Paymob/PaymobClient.php' => 'a Paymob checkout payload, not an invoice line —'
        .' the string is read by the gateway, never rendered on a document a tenant files',
];

/**
 * Every file that could raise a money-document line — services AND the Filament screens the
 * exemptions name. The first version globbed `app/Services` only, so the exemption registered for
 * `InvoiceForm` protected a file the sweep could never reach: it read as coverage and covered
 * nothing.
 *
 * @return list<string>
 */
function lineWritingFiles(): array
{
    return array_merge(
        glob(base_path('app/Services/*.php')),
        glob(base_path('app/Services/*/*.php')),
        glob(base_path('app/Filament/Admin/Resources/*/Schemas/*.php')),
        glob(base_path('app/Models/*.php')),
    );
}

/**
 * Each `->describeAs(...)` call's argument list, as source text.
 *
 * Tokenised, never brace-counted over raw characters: a `[` or `(` inside a string makes a
 * character counter run to end of file and fail OPEN, which is the exact defect the review of
 * `MoneyDocumentDoors` caught in a sibling gate.
 *
 * @return list<string>
 */
function describeAsCalls(string $source): array
{
    $tokens = array_values(token_get_all($source));
    $calls = [];

    for ($i = 0; $i < count($tokens); $i++) {
        if (! is_array($tokens[$i]) || $tokens[$i][1] !== 'describeAs') {
            continue;
        }
        if (($tokens[$i + 1] ?? null) !== '(') {
            continue;
        }

        $depth = 0;
        $call = [];

        for ($j = $i + 1; $j < count($tokens); $j++) {
            $token = $tokens[$j];

            if ($token === '(' || $token === '[') {
                $depth++;
            } elseif ($token === ')' || $token === ']') {
                $depth--;
                if ($depth === 0) {
                    break;
                }
            }

            $call[] = is_array($token) ? $token[1] : $token;
        }

        $calls[] = implode('', $call);
    }

    return $calls;
}

/** PHP source with comments removed, so a key named in a docblock is not read as a writer. */
function withoutComments(string $source): string
{
    return implode('', array_map(
        fn ($token) => is_array($token) ? (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? ' ' : $token[1]) : $token,
        token_get_all($source),
    ));
}

it('resolves every registered narrative in both languages', function () {
    $broken = [];

    foreach (LineNarrative::KEYS as $key => $spec) {
        foreach (['en', 'ar'] as $locale) {
            // `fallback: false`, or an Arabic key that does not exist answers with the English one
            // and this gate passes on the exact failure it exists to catch.
            if (! Lang::has($spec['lang'], $locale, fallback: false)) {
                $broken[] = "{$key}: no [{$locale}] wording at {$spec['lang']}";

                continue;
            }

            $placeholders = array_merge(
                $spec['text'] ?? [],
                $spec['month'] ?? [],
                $spec['date'] ?? [],
                array_keys($spec['trans'] ?? []),
                array_keys($spec['catalogue'] ?? []),
            );

            $rendered = LineNarrative::resolve(
                $key,
                array_fill_keys($placeholders, '2026-09-01'),
                null,
                $locale,
            );

            if (preg_match('/:[a-z_]+/', $rendered, $leftover)) {
                $broken[] = "{$key} [{$locale}]: renders a leftover {$leftover[0]}";
            }

            // An English sentence sitting in the Arabic key is the realistic failure when keys are
            // added in one pass and reviewed in English, and `Lang::has()` cannot see it. But a
            // template can legitimately have no words of its OWN — `:name - :period` is the same
            // string in every language — so the check applies only where the English stem contains
            // letters once its placeholders are removed.
            $stem = preg_replace('/:[a-z_]+/', '', (string) trans($spec['lang'], [], 'en'));

            if ($locale === 'ar' && preg_match('/\p{L}/u', (string) $stem)
                && ! preg_match('/\p{Arabic}/u', $rendered)) {
                $broken[] = "{$key} [ar]: no Arabic script — is the English string in the AR file?";
            }
        }
    }

    expect(LineNarrative::KEYS)->not->toBeEmpty();
    expect($broken)->toBe([], "A document line would print a broken sentence:\n  ".implode("\n  ", $broken));
});

it('has a writer for every narrative it catalogues', function () {
    // Derived from the tree, because a key catalogued in both languages that nothing stores is a
    // sentence nobody reads — and it looks exactly like coverage.
    //
    // Comments are STRIPPED first. `str_contains($source, "'key'")` counted a key named in a
    // docblock or a `// TODO` as a writer — the prose false-positive shape this project has now
    // recorded three times, and the review caught it here as well.
    $written = [];

    foreach (lineWritingFiles() as $path) {
        $source = withoutComments(file_get_contents($path));

        foreach (array_keys(LineNarrative::KEYS) as $key) {
            if (str_contains($source, "'{$key}'")) {
                $written[$key] = true;
            }
        }
    }

    $orphans = array_values(array_diff(array_keys(LineNarrative::KEYS), array_keys($written)));

    expect($written)->not->toBeEmpty('The sweep found no writer for ANY key — it is reading the wrong tree.');
    expect($orphans)->toBe([], 'Catalogued in both languages and stored by nothing: '.implode(', ', $orphans));
});

it('lets no line-raising service store prose with no key', function () {
    // A service that builds an invoice or credit-note line and hands `IssueInvoiceService` a
    // `description` must say WHICH template it used, or the sentence freezes in the language that
    // run happened to be in — the whole defect.
    $offenders = [];
    $checked = 0;

    foreach (lineWritingFiles() as $path) {
        $relative = str_replace(base_path().'/', '', $path);
        $source = file_get_contents($path);

        // A line-raiser puts a `description` into an ITEMS array — or calls `describeAs()`, the
        // one seam that creates a CREDIT-NOTE line, which takes its text as a POSITIONAL argument
        // and so has neither shape. The first version of this gate matched the array only, and
        // measured on the two offenders (`CreditUnearnedBillingService`, `CreditNoteService`) it
        // found zero of either marker: **the files that most obviously broke the rule were not
        // even examined**, and `$checked` passed on the invoice services alone. Found by review.
        $raisesInvoiceLine = preg_match('/items:\s*\[|\$items\[\]|items\'\s*=>\s*\[/', $source)
            && str_contains($source, "'description' =>");
        $raisesCreditNoteLine = str_contains($source, '->describeAs(');

        // A model that DECLARES its narrative columns (`narrativeColumns()`) is wiring, not a
        // writer — its `'reason_notes' => [...]` is a column map, and reading it as prose reported
        // `CreditNote` itself as the offender.
        $writesANote = str_contains($source, "'reason_notes' =>")
            && ! str_contains($source, 'function narrativeColumns(');

        if (! $raisesInvoiceLine && ! $raisesCreditNoteLine && ! $writesANote) {
            continue;
        }

        $checked++;

        if (isset(LINE_PROSE_EXEMPT[$relative])) {
            continue;
        }

        // Per CALL, not per FILE. `CamReconciliationService` raises three lines — two invoice, one
        // credit note — and a file-wide `str_contains` let its two converted sites vouch for the
        // third, which was still resolving `__()` at write time.
        $invoiceCalls = substr_count($source, "'description' =>");
        $keyed = substr_count($source, "'description_key' =>");

        // The credit note's own `reason_notes` — the paragraph a tenant reads ABOVE the lines,
        // and the one this document is mostly about. It was left frozen when the lines were
        // converted, which is how a single credit note came to carry an English explanation over
        // Arabic line text.
        $notesWritten = substr_count($source, "'reason_notes' =>");
        $notesKeyed = substr_count($source, "'reason_notes_key' =>");

        if ($notesWritten > $notesKeyed) {
            $offenders[] = "{$relative}: writes reason_notes {$notesWritten}×, names a key {$notesKeyed}×";
        }

        if ($raisesInvoiceLine && $keyed < $invoiceCalls) {
            $offenders[] = "{$relative}: {$invoiceCalls} invoice line(s), {$keyed} keyed";
        }

        // Per CALL for the credit-note seam too, by slicing each `->describeAs(` argument list on
        // TOKENS. Counting a variable name (`$narrativeKey`) instead missed the call site that
        // passes its key as a literal — a detector that only sees one spelling of the right answer
        // reports the other as broken.
        foreach (describeAsCalls($source) as $call) {
            $namesAKey = false;

            foreach (array_keys(LineNarrative::KEYS) as $key) {
                if (str_contains($call, "'{$key}'")) {
                    $namesAKey = true;
                    break;
                }
            }

            // …or hands one through, which is what a branch on the line's shape looks like.
            if (! $namesAKey && ! str_contains($call, '$narrativeKey')) {
                $offenders[] = "{$relative}: a describeAs() call names no narrative key";
            }
        }
    }

    expect($checked)->toBeGreaterThan(5, 'The sweep found almost no line-raising service — it is '
        .'matching on a shape these services no longer have.');

    expect($offenders)->toBe([], 'A service raises a document line with prose and no key, so the '
        ."sentence freezes in whatever language that run was in:\n  ".implode("\n  ", $offenders));
});

it('keeps no stale exemption', function () {
    $stale = [];

    foreach (LINE_PROSE_EXEMPT as $path => $reason) {
        if (! file_exists(base_path($path))) {
            $stale[] = "{$path}: gone";

            continue;
        }

        if (str_contains(file_get_contents(base_path($path)), "'description_key' =>")) {
            $stale[] = "{$path}: now stores a key — drop the exemption";
        }

        if (strlen($reason) < 40) {
            $stale[] = "{$path}: the reason is too thin to review";
        }
    }

    expect($stale)->toBe([], implode("\n  ", $stale));
});

it('consumes every placeholder its writers supply', function () {
    // The tooth whose absence made the worst finding of the review invisible: `billing.cycle`
    // carried a `pct` its template had no `:pct` in, so a part-quarter line stored the pro-ration
    // and silently dropped it — and because the PDF renders the narrative, the ENGLISH invoice
    // lost a clause it used to print. Every gate was green throughout, because all of them look at
    // the template or at the writer, and never at the two together.
    $unconsumed = [];

    foreach (LineNarrative::KEYS as $key => $spec) {
        $declared = array_merge(
            $spec['text'] ?? [],
            $spec['month'] ?? [],
            $spec['date'] ?? [],
            array_keys($spec['trans'] ?? []),
            array_keys($spec['catalogue'] ?? []),
        );

        $template = (string) trans($spec['lang'], [], 'en');

        foreach ($declared as $placeholder) {
            if (! str_contains($template, ':'.$placeholder)) {
                $unconsumed[] = "{$key}: declares :{$placeholder}, which the template never prints";
            }
        }

        // …and the other direction: a template printing a placeholder the registry does not
        // declare renders a literal `:name` on a tax invoice.
        preg_match_all('/:([a-z_]+)/', $template, $matches);

        foreach (array_unique($matches[1]) as $printed) {
            if (! in_array($printed, $declared, true)) {
                $unconsumed[] = "{$key}: template prints :{$printed}, which the registry does not declare";
            }
        }
    }

    expect($unconsumed)->toBe([], "A narrative and its template disagree about what the line says:\n  "
        .implode("\n  ", $unconsumed));
});
