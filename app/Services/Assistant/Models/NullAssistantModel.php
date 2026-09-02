<?php

namespace App\Services\Assistant\Models;

use App\Contracts\AssistantModel;

/**
 * No model. The shipped default, and a supported state rather than a missing one.
 *
 * With this bound, the assistant is exactly what phase A built: it finds the screen, the report,
 * the record or the handbook section, and shows it. Nothing is sent anywhere, nothing is billed,
 * and every test of phase A passes unchanged — which is the property that makes phase B safe to
 * merge before anybody has decided to pay for it.
 */
class NullAssistantModel implements AssistantModel
{
    public function word(string $question, array $passages, string $locale): ?string
    {
        return null;
    }

    public function lastUsage(): array
    {
        return ['input' => 0, 'output' => 0];
    }

    /**
     * FALSE — there is no model here to configure, and every caller means it that way.
     *
     * It returned true, on the reading that this implementation "can run" (it can: it returns
     * null). That is technically defensible and was wrong at every call site, because what they all
     * actually ask is *"is something going to write an answer?"*. It shipped as a defect the moment
     * the chat used the same predicate to decide whether to gather extra grounding: with no model
     * at all, `isConfigured()` said yes and the documentation tier ran on every question,
     * overriding the fallback rule the guides depend on.
     *
     * Reading it as "will this word an answer" makes all four call sites correct without a single
     * `instanceof`.
     */
    public function isConfigured(): bool
    {
        return false;
    }
}
