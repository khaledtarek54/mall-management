<?php

namespace App\Livewire;

use App\Contracts\AssistantModel;
use App\Models\AssistantQuestion;
use App\Services\Assistant\AnswerQuestionService;
use App\Support\Modules;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
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
    /** A long thread is scrolled, not re-read; this is a guard on the query, not a policy. */
    public const MAX_TURNS_SHOWN = 40;

    public bool $open = false;

    public string $question = '';

    /** @var array<int, array{role: string, text: string, sources: array<int, array{title: string, url: string|null}>}> */
    public array $messages = [];

    public string $conversationId = '';

    /**
     * Where "open" and "which thread" live between page loads.
     *
     * The component is mounted fresh by a render hook on EVERY page, so a public property is gone
     * the moment the operator clicks a link — the chat closed itself on every navigation and lost
     * the thread on every refresh. The session is the smallest thing that survives both, needs no
     * JavaScript, and is already per-user and per-device.
     */
    private const SESSION_OPEN = 'assistant.chat.open';

    private const SESSION_CONVERSATION = 'assistant.chat.conversation';


    /**
     * Pick the thread back up, wherever the operator has navigated to.
     */
    public function mount(): void
    {
        $this->open = (bool) session(self::SESSION_OPEN, false);

        $this->conversationId = (string) session(self::SESSION_CONVERSATION, '');

        if ($this->conversationId === '') {
            $this->conversationId = (string) Str::uuid();
            session([self::SESSION_CONVERSATION => $this->conversationId]);
        }

        $this->messages = $this->thread();
    }

    /**
     * The conversation so far, rebuilt from the rows that recorded it.
     *
     * Read back from `assistant_questions` rather than kept in the session: the session would
     * silently drop the history on a new device, and it is the same list the miss list is built
     * from — two copies of one conversation is how they come to disagree.
     *
     * @return array<int, array{role: string, text: string, sources: array<int, array{title: string, url: string|null}>}>
     */
    private function thread(): array
    {
        $messages = [];

        $turns = AssistantQuestion::query()
            ->where('conversation_id', $this->conversationId)
            ->where('user_id', Auth::id())
            ->orderBy('id')
            ->limit(self::MAX_TURNS_SHOWN)
            ->get();

        foreach ($turns as $turn) {
            $messages[] = ['id' => null, 'helpful' => null, 'role' => 'user', 'text' => $turn->question, 'sources' => []];
            $messages[] = [
                'id' => $turn->id,
                'helpful' => $turn->was_helpful,
                'role' => 'assistant',
                'text' => $turn->model_answer ?? __('admin.assistant.chat.no_model_answer'),
                // Sources are not replayed: they were links to what was found AT THE TIME, and a
                // record may since have been renamed or a screen retired. The words stand; a stale
                // link pretending to be current does not.
                'sources' => [],
            ];
        }

        return $messages;
    }

    public function toggle(): void
    {
        $this->open = ! $this->open;

        // Remembered, so the panel is still open on the next screen. "The assistant is with you"
        // is the whole point of a floating chat; one that shuts itself on every click is a form.
        session([self::SESSION_OPEN => $this->open]);
    }

    public function ask(AnswerQuestionService $service): void
    {
        $question = trim($this->question);

        if ($question === '') {
            return;
        }

        $this->messages[] = ['id' => null, 'helpful' => null, 'role' => 'user', 'text' => $question, 'sources' => []];
        $this->question = '';

        $answer = $service->answer($question, conversationId: $this->conversationId);

        // The sources become CITATIONS rather than the answer — small, secondary, and still there.
        // A chat that hides where its answer came from is one nobody can check, and on a screen
        // about somebody's rent that is the whole difference.
        $sources = array_map(
            fn (array $r): array => ['title' => (string) $r['title'], 'url' => $r['url'] ?? null],
            array_slice($answer['results'], 0, 3),
        );

        $this->messages[] = [
            'id' => $answer['id'] ?? null,
            'helpful' => null,
            'role' => 'assistant',
            // Falls back to a plain sentence rather than silence when the model is off, out of
            // quota or unreachable. The citations below it are still useful, so the turn is not
            // wasted — and a chat that answers nothing at all reads as broken rather than as
            // degraded.
            'text' => $answer['answer'] ?? __('admin.assistant.chat.no_model_answer'),
            'sources' => $sources,
        ];
    }

    /**
     * Mark an answer helpful or not.
     *
     * **This is the signal that replaces the miss list.** Measured on 45 real questions, ZERO
     * matched nothing — with 189 corpus entries and 1,050 documentation sections something always
     * matches, so "did it find anything" can no longer tell a good answer from a confident wrong
     * one. Whether the reader found it useful is the only thing that can.
     *
     * Scoped to the reader's own row: a rating is a judgement on the answer somebody was given, and
     * a crafted payload must not let one person rate another's.
     */
    public function rate(int $id, bool $helpful): void
    {
        AssistantQuestion::query()
            ->whereKey($id)
            ->where('user_id', Auth::id())
            ->update(['was_helpful' => $helpful]);

        foreach ($this->messages as $i => $message) {
            if (($message['id'] ?? null) === $id) {
                $this->messages[$i]['helpful'] = $helpful;
            }
        }
    }

    /**
     * Start a NEW conversation. Nothing is deleted.
     *
     * The rows stay: they are the miss list, and the questions somebody asked are exactly what the
     * A phase exists to collect. "Clear" empties the reader's screen, not the record — deleting
     * their history to tidy a panel would throw away the only evidence of what the guides are
     * missing.
     */
    public function clear(): void
    {
        $this->conversationId = (string) Str::uuid();
        session([self::SESSION_CONVERSATION => $this->conversationId]);

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
