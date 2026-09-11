<?php

namespace App\Support;

/**
 * **PHP source with its comments blanked — the ONE tokenizer every source-reading gate shares.**
 *
 * This codebase has ~100 conformance gates and a dozen registries that READ SOURCE, and nearly all
 * of them had to learn the same lesson separately: **a docblock naming the pattern is not the
 * pattern.** `MoneyDocumentDoors` matched `Invoice::create([` in its own explanatory comment;
 * `PostMonthAction`'s usage example named the factory after a permission; a `//` comment with an
 * apostrophe swallowed the rest of a block; `DepositTransactionForm` names the method a gate
 * looks for in a sentence one line above the call. Each time, the fix was "strip comments first"
 * — and each time it was written again. Measured 2026-09-12: **twenty-four** copies of a
 * comments-out-of-source function across twenty-two files in `app/Support` and `tests/`, under
 * ten different names (`withoutComments`, `stripPhpComments`, `sourceWithoutComments`,
 * `ruleDoorSource`, `openLinkSource`, `nullableFkSources`, `statusGateSources`, …), because the
 * test-helper uniqueness gate refuses a duplicate NAME and the reflex it produces is to rename a
 * copy rather than reuse the original. Eleven REMOVED comment tokens (line numbers and offsets
 * shift), three replaced each with one space, ten BLANKED them to their own length; a gate
 * written against one and run against another reports positions off by every comment above
 * them, and a fixed-width lookahead tuned to one semantic breaks under the other — the review of
 * this change found two such windows (120 and 600 characters) in gates that had been green only
 * because their helper collapsed comments.
 *
 * ## Offsets and lines are preserved
 *
 * Every comment token is replaced by spaces of its own length with its newlines kept, so a byte
 * offset or a line number measured on the blanked text points at the same place in the file. That
 * is what lets a gate report `file:line` — and what lets a converter splice a replacement into the
 * ORIGINAL at an offset it found on the blanked copy (`BadgeColors`' migration did exactly that).
 * Built by walking the tokens rather than `strpos`-ing each comment back into the text: the
 * copies did the latter, and a comment whose text also appears inside an earlier string literal
 * blanks the wrong bytes.
 *
 * `withoutCommentsOrStrings()` is for a gate that must read CODE and not prose in either form: the
 * concurrency registry carried a phantom entry for years because `Health` mentions `Cache::lock()`
 * in an operator-facing message. String contents are blanked between their quotes, so an
 * interpolated `"{$x}"` and a heredoc lose their text and keep their shape.
 *
 * Not for a token WALK. A reader that needs nesting (`ModalFieldReach`, `UnresolvedClassReference`'s
 * gate, `TestHelperUniqueness`'s) indexes the token array and steps past comment tokens in place;
 * that is a different shape, and the gate that keeps this the only string-stripper leaves it
 * alone. A walker that also wants comment-free TEXT tokenizes `withoutComments()` first, as
 * `RowActionPolicy::segments()` does, rather than skipping comment tokens itself.
 */
final class PhpSource
{
    /** The source with every line, hash and block comment blanked to spaces — offsets and line numbers intact. */
    public static function withoutComments(string $source): string
    {
        return self::rebuild($source, [T_COMMENT, T_DOC_COMMENT]);
    }

    /** The same, with the CONTENTS of every string literal blanked as well — code, never prose. */
    public static function withoutCommentsOrStrings(string $source): string
    {
        return self::rebuild($source, [T_COMMENT, T_DOC_COMMENT], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML]);
    }

    /** `withoutComments()` over a file on disk. */
    public static function fileWithoutComments(string $path): string
    {
        return self::withoutComments((string) file_get_contents($path));
    }

    /**
     * @param  array<int, int>  $blank  token ids replaced wholesale by spaces (newlines kept)
     * @param  array<int, int>  $blankInside  token ids whose text is blanked between its first and last character
     */
    private static function rebuild(string $source, array $blank, array $blankInside = []): string
    {
        $out = '';

        foreach (token_get_all($source) as $token) {
            if (! is_array($token)) {
                $out .= $token;

                continue;
            }

            [$id, $text] = $token;

            if (in_array($id, $blank, true)) {
                $out .= self::spaces($text);

                continue;
            }

            if (in_array($id, $blankInside, true)) {
                // A quoted literal keeps its quotes (and a `b`/`B` binary prefix — the quote is
                // then the SECOND character); a heredoc body / inline HTML has none to keep.
                $lead = $id === T_CONSTANT_ENCAPSED_STRING ? (in_array($text[0] ?? '', ['b', 'B'], true) ? 2 : 1) : 0;
                $quoted = $lead > 0 && strlen($text) >= $lead + 1;
                $out .= $quoted
                    ? substr($text, 0, $lead).self::spaces(substr($text, $lead, -1)).$text[-1]
                    : self::spaces($text);

                continue;
            }

            $out .= $text;
        }

        return $out;
    }

    /** Every character but a newline becomes a space, so the text keeps its length and its lines. */
    private static function spaces(string $text): string
    {
        return preg_replace('/[^\n]/', ' ', $text) ?? '';
    }
}
