<?php

namespace App\Services\Assistant\Models;

use Anthropic\Client;
use App\Contracts\AssistantModel;
use Illuminate\Support\Facades\Log;

/**
 * Claude, used as a WORDING LAYER and nothing else.
 *
 * ## What it is and is not given
 *
 * It receives a question and the passages retrieval already found. It gets no tools, no database
 * handle, no ability to act, and no say in WHICH passages it sees — those were chosen by
 * `AnswerQuestionService` from the screen guides, the report catalogue and the handbook, all of
 * them already filtered by what this reader may open. So the model cannot reach a record its reader
 * could not, cannot run a query, and cannot move anything. The worst outcome available to it is a
 * wrong sentence printed above the correct source.
 *
 * That is also why this is affordable. The expensive design is the one where the model reads a
 * catalogue of 26 reports and 113 screens and decides; this one reads three paragraphs.
 *
 * ## The passages are DATA, and the prompt says so
 *
 * They contain operator-typed text — a tenant's trading name, a lease note, a work-order comment —
 * any of which a party outside this company could have influenced. They are fenced and labelled,
 * and the system prompt states in terms that instructions inside them are content to be reported,
 * never followed. That is a mitigation and not a guarantee, which is exactly why the layer below
 * has no capability to abuse: defence in depth, with the real defence being the absence of tools.
 *
 * ## Money
 *
 * It is told, in the system prompt, never to compute or restate a figure that is not verbatim in a
 * passage. `Invoice::recomputeTotals()` is the single source of truth for what is settled, and a
 * model doing arithmetic beside it would be a second answer to the same question — the one failure
 * this whole design was shaped to avoid.
 *
 * ## Why no prompt caching here
 *
 * Haiku 4.5's minimum cacheable prefix is 4,096 tokens and this request is a fraction of that, so a
 * `cache_control` marker would silently do nothing. At this volume the answer cache in
 * `AnswerQuestionService` is the lever that pays; caching would matter only if the system prompt
 * grew several times over, which would itself be a reason to re-read this decision.
 */
class ClaudeAssistantModel implements AssistantModel
{
    /** @var array{input: int, output: int} */
    private array $usage = ['input' => 0, 'output' => 0];

    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model,
        private readonly int $maxTokens,
    ) {}

    public function isConfigured(): bool
    {
        return filled($this->apiKey);
    }

    public function word(string $question, array $passages, string $locale): ?string
    {
        $this->usage = ['input' => 0, 'output' => 0];

        if (! $this->isConfigured() || $passages === []) {
            return null;
        }

        try {
            $client = new Client(apiKey: $this->apiKey);

            $message = $client->messages->create(
                model: $this->model,
                maxTokens: $this->maxTokens,
                system: AssistantPrompt::instructions($locale),
                messages: [['role' => 'user', 'content' => AssistantPrompt::prompt($question, $passages)]],
            );

            $this->usage = [
                'input' => (int) ($message->usage->inputTokens ?? 0),
                'output' => (int) ($message->usage->outputTokens ?? 0),
            ];

            $text = '';

            foreach ($message->content as $block) {
                // Guarded rather than reading content[0]->text: a non-text block first would be a
                // TypeError, and this must degrade to "no worded answer" rather than to a 500 on a
                // screen whose retrieval results are perfectly good.
                if (($block->type ?? null) === 'text') {
                    $text .= $block->text;
                }
            }

            return trim($text) !== '' ? trim($text) : null;
        } catch (\Throwable $e) {
            // A provider outage, a rate limit, an expired key: the assistant keeps working. The
            // passages are already on screen and they were the answer in phase A. Logged, because
            // an assistant that silently stopped wording answers looks identical to one nobody is
            // using.
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
