<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Actions\GuideAction;
use App\Services\Assistant\AnswerQuestionService;
use App\Support\Modules;
use App\Support\ScreenGuides;
use BackedEnum;
use Filament\Forms\Components\Textarea;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * "Ask Atriom" — one box, and an answer drawn from what the system already documents.
 *
 * ## Why this is not a chatbot, and why that is the point
 *
 * The two questions an operator has are *"how do I do X"* and *"what do the numbers say"*. This
 * system already answers both — 112 screens carry a four-field guide in two languages, and 26
 * reports carry curated keywords — and had no way to ASK. A search box over material that is
 * already written, already bilingual and already gated is the whole of A0, and it costs nothing to
 * run. See docs/integrations/AI-ASSISTANT.md for what a language model would add on top, and what
 * it would cost.
 *
 * ## It cannot point at a screen you may not open
 *
 * Every candidate is filtered through that screen's own `canAccess()` inside the service. So the
 * answer differs by role, correctly, and the box can never produce a link that 403s — which reads
 * as a broken system rather than as a boundary.
 *
 * ## Every question is recorded, especially the ones with no answer
 *
 * The unanswered list is the deliverable of this phase. It is what says whether the guides have a
 * hole, and whether a language model is worth paying for.
 */
class Assistant extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?string $slug = 'ask';

    protected string $view = 'filament.pages.assistant';

    /** @var array<string, mixed> */
    public array $data = [];

    /** @var array<int, array{kind: string, key: string, screen: class-string, title: string, score: int}> */
    public array $results = [];

    public bool $asked = false;

    /** What the model made of it, when one is configured. Null is the shipped default. */
    public ?string $answer = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    /**
     * No permission gates this, and none should — the same call the Handbook makes, for a stronger
     * reason.
     *
     * Every result is already filtered through the target screen's own `canAccess()`, so a right
     * named `assistant.view` would grant exactly what the reader already holds and refuse exactly
     * what they are already refused. That is the shape this codebase retired 43 `{module}.delete`
     * keys for: a permission that reads as a right and decides nothing, rendering as a checkbox on
     * the roles matrix that changes no access.
     *
     * What an operator may genuinely want is to switch the whole screen OFF, and that is the module
     * flag — which is also why this is a `Modules::KEYS` entry rather than a permission.
     */
    public static function canAccess(): bool
    {
        return Modules::enabled('assistant') && Auth::check();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Textarea::make('question')
                    ->label(__('admin.assistant.question_label'))
                    ->placeholder(__('admin.assistant.question_placeholder'))
                    ->helperText(__('admin.assistant.question_help'))
                    ->rows(2)
                    // Capped well under the column's 500 so a long paste is refused on the form,
                    // where the operator can see why, rather than truncated silently on the way in.
                    ->maxLength(300)
                    ->required()
                    ->autofocus(),
            ])
            ->statePath('data');
    }

    public function ask(AnswerQuestionService $service): void
    {
        $question = (string) ($this->form->getState()['question'] ?? '');

        $answer = $service->answer($question);

        $this->results = $answer['results'];
        $this->answer = $answer['answer'] ?? null;
        $this->asked = true;
    }

    /**
     * The four-field guide for one result, resolved in the READER's language.
     *
     * Resolved here from the KEY rather than carried on the result, which is what makes the
     * cross-locale fallback safe: a question typed in English can match the English corpus and
     * still be answered entirely in Arabic.
     *
     * @return array{purpose: string, steps: array<int, string>, affects: array<int, string>, rules: array<int, string>}|null
     */
    public function guideFor(string $kind, string $key): ?array
    {
        if ($kind !== 'screen') {
            return null;
        }

        return [
            'purpose' => ScreenGuides::purpose($key),
            'steps' => ScreenGuides::steps($key),
            'affects' => ScreenGuides::affects($key),
            'rules' => ScreenGuides::rules($key),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::class),
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.assistant.nav_label');
    }

    public function getTitle(): string
    {
        return __('admin.assistant.page_title');
    }

    public function getSubheading(): ?string
    {
        return __('admin.assistant.subheading');
    }
}
