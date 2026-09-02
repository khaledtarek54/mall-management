<?php

namespace App\Livewire;

use App\Contracts\AssistantModel;
use App\Services\Assistant\AnswerQuestionService;
use App\Support\Modules;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * The floating assistant — a chat bubble on every admin page.
 *
 * ## The model writes every answer here
 *
 * The full screen at `/admin/ask` shows what retrieval FOUND: screens, reports, records, handbook
 * sections, ranked. This surface shows what the model SAID, and the sources shrink to citations
 * beneath it. Same service, same passages, different contract with the reader — one is a place to
 * look something up, the other is a conversation.
 *
 * **Retrieval is still what grounds it, and that is not negotiable.** The model is handed the
 * passages and told to answer from them alone. Without that it would answer questions about
 * Egyptian VAT, late-fee clauses and this system's own rules from general knowledge — confidently,
 * fluently and wrongly, on a screen where somebody is deciding what to bill. The passages are the
 * difference between an assistant and a liability.
 *
 * ## Side
 *
 * Bottom-right in English, bottom-left in Arabic — achieved with `inset-inline-end`, a CSS logical
 * property, so the panel's own `dir` decides it. There is not a single `left` or `right` in the
 * view, which is the same rule the handbook theme follows and the reason it needs no RTL plugin.
 */
class AssistantChat extends Component
{
    public bool $open = false;

    public string $question = '';

    /** @var array<int, array{role: string, text: string, sources: array<int, array{title: string, url: string|null}>}> */
    public array $messages = [];

    public bool $thinking = false;

    /**
     * Whether to render at all.
     *
     * Same gate as the full screen: the module switch and being signed in. Nothing here can show a
     * reader anything they could not already open, so there is no separate right to hold.
     */
    public function shouldRender(): bool
    {
        return Modules::enabled('assistant') && Auth::check();
    }

    public function toggle(): void
    {
        $this->open = ! $this->open;
    }

    public function ask(AnswerQuestionService $service): void
    {
        $question = trim($this->question);

        if ($question === '') {
            return;
        }

        $this->messages[] = ['role' => 'user', 'text' => $question, 'sources' => []];
        $this->question = '';

        $answer = $service->answer($question);

        // The sources become CITATIONS rather than the answer — small, secondary, and still there.
        // A chat that hides where its answer came from is one nobody can check, and on a screen
        // about somebody's rent that is the whole difference.
        $sources = array_map(
            fn (array $r): array => ['title' => (string) $r['title'], 'url' => $r['url'] ?? null],
            array_slice($answer['results'], 0, 3),
        );

        $this->messages[] = [
            'role' => 'assistant',
            // Falls back to a plain sentence rather than silence when the model is off, out of
            // quota or unreachable. The citations below it are still useful, so the turn is not
            // wasted — and a chat that answers nothing at all reads as broken rather than as
            // degraded.
            'text' => $answer['answer'] ?? __('admin.assistant.chat.no_model_answer'),
            'sources' => $sources,
        ];
    }

    public function clear(): void
    {
        $this->messages = [];
    }

    public function modelIsOn(): bool
    {
        return app(AssistantModel::class)->isConfigured();
    }

    public function render()
    {
        return view('livewire.assistant-chat');
    }
}
