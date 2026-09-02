<?php

namespace App\Services\Assistant\Models;

use App\Contracts\AssistantModel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Any provider that speaks the OpenAI chat-completions shape — which is most of them.
 *
 * **One driver, several providers, and that is the point.** Google's Gemini, Groq, OpenRouter,
 * Together and a local Ollama all expose `/chat/completions` with a bearer token, so the difference
 * between them is a base URL and a model name. Writing a class per provider would be five copies of
 * one HTTP call, and the copy nobody exercised would be the one that broke.
 *
 * **It exists because Anthropic has no free tier.** The `anthropic` driver is the better answer for
 * production — it is the model this system was designed and prompted against — but a demo needs a
 * key somebody can get without a credit card, and Gemini's free tier is that. Both drivers satisfy
 * the same contract, so switching is one env line and no code.
 *
 * ## It is the same wording layer, under the same rules
 *
 * No tools, no database handle, no say in what it sees. It receives the passages retrieval already
 * found — filtered by what the reader may open — and returns prose. Every rule in
 * {@see ClaudeAssistantModel} applies here unchanged, including the two that matter: the passages
 * are DATA rather than instructions, and it may not compute a figure that is not verbatim in one.
 *
 * ## Raw HTTP rather than an SDK, deliberately
 *
 * The endpoint is a single POST with a JSON body. Adding a vendor SDK for it would pull a
 * dependency for one request, and would tie a driver whose whole purpose is provider-neutrality to
 * one provider's client.
 */
class OpenAiCompatibleAssistantModel implements AssistantModel
{
    /** @var array{input: int, output: int} */
    private array $usage = ['input' => 0, 'output' => 0];

    public function __construct(
        private readonly ?string $apiKey,
        private readonly ?string $baseUrl,
        private readonly string $model,
        private readonly int $maxTokens,
        private readonly int $timeout,
    ) {}

    public function isConfigured(): bool
    {
        return filled($this->apiKey) && filled($this->baseUrl);
    }

    public function word(string $question, array $passages, string $locale): ?string
    {
        $this->usage = ['input' => 0, 'output' => 0];

        if (! $this->isConfigured() || $passages === []) {
            return null;
        }

        try {
            $response = Http::withToken((string) $this->apiKey)
                ->timeout($this->timeout)
                ->acceptJson()
                ->post(rtrim((string) $this->baseUrl, '/').'/chat/completions', [
                    'model' => $this->model,
                    'max_tokens' => $this->maxTokens,
                    'messages' => [
                        ['role' => 'system', 'content' => AssistantPrompt::instructions($locale)],
                        ['role' => 'user', 'content' => AssistantPrompt::prompt($question, $passages)],
                    ],
                ]);

            if ($response->failed()) {
                // A free tier's daily quota runs out mid-demo, and that must read as "no worded
                // answer" rather than as a broken screen. Logged with the status so the difference
                // between "quota" (429) and "key wrong" (401) is visible without a debugger.
                Log::warning('The assistant model refused the request; answering from retrieval alone.', [
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, 300),
                ]);

                return null;
            }

            $body = $response->json();

            $this->usage = [
                'input' => (int) data_get($body, 'usage.prompt_tokens', 0),
                'output' => (int) data_get($body, 'usage.completion_tokens', 0),
            ];

            $text = trim((string) data_get($body, 'choices.0.message.content', ''));

            return $text !== '' ? $text : null;
        } catch (\Throwable $e) {
            Log::warning('The assistant could not reach the model; answering from retrieval alone.', [
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function lastUsage(): array
    {
        return $this->usage;
    }
}
