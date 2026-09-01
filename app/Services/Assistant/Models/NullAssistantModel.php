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

    public function isConfigured(): bool
    {
        return true;
    }
}
