<?php

namespace App\Contracts;

/**
 * Something that can put an answer into words, given the passages retrieval already found.
 *
 * **The seam exists so the provider is a config line rather than a rewrite** — and so that `none`
 * is a real, supported, first-class state rather than "the feature is broken". Phase A works
 * completely without any implementation of this, and that is the shipped default.
 *
 * The contract is deliberately narrow: it takes a question and PASSAGES and returns prose. It is
 * given no tools, no database handle and no way to act. Whatever it returns is shown to a person
 * beneath the passages it was given, so the worst a compromised or confused model can do is write a
 * wrong sentence under the right source — never move money, never reach a record its reader could
 * not open.
 */
interface AssistantModel
{
    /**
     * Answer in `$locale`, using only `$passages`. Null means "no answer" and is not an error —
     * the caller shows the passages alone, which is exactly what phase A did.
     *
     * @param  array<int, array{title: string, body: string}>  $passages
     */
    public function word(string $question, array $passages, string $locale): ?string;

    /** Tokens billed by the last call, for the ceiling. */
    public function lastUsage(): array;

    /** Whether this implementation can actually run — a key present, a driver configured. */
    public function isConfigured(): bool;
}
