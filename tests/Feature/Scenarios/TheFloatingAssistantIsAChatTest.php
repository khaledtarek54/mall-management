<?php

use App\Contracts\AssistantModel;
use App\Livewire\AssistantChat;
use App\Models\AssistantQuestion;
use App\Services\Assistant\Models\NullAssistantModel;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

/**
 * The floating assistant — the chat bubble on every admin page.
 *
 * The full screen at `/admin/ask` shows what retrieval FOUND. This shows what the model SAID, with
 * the sources shrunk to citations beneath it. The properties worth pinning are that it stays a
 * conversation, that it never loses the citations, and that it degrades to something readable when
 * there is no model.
 */
beforeEach(fn () => $this->seed(RolesPermissionsSeeder::class));

function chatModel(?string $answer): AssistantModel
{
    return new class($answer) implements AssistantModel
    {
        public function __construct(private ?string $answer) {}

        public function word(string $question, array $passages, string $locale): ?string
        {
            return $this->answer;
        }

        public function lastUsage(): array
        {
            return ['input' => 10, 'output' => 5];
        }

        public function isConfigured(): bool
        {
            return true;
        }
    };
}

it('answers in the thread and keeps the sources as citations', function () {
    $asset = makeAsset();
    $this->actingAs(makeUser('super_admin'));
    app()->instance(AssistantModel::class, chatModel('Raise it from the Credit Notes screen.'));

    asTenant($asset, function () {
        $chat = Livewire::test(AssistantChat::class)
            ->call('toggle')
            ->set('question', 'how do I issue a credit note')
            ->call('ask');

        $messages = $chat->get('messages');

        // A turn is the reader's question and the model's reply, in that order.
        expect($messages)->toHaveCount(2)
            ->and($messages[0]['role'])->toBe('user')
            ->and($messages[1]['role'])->toBe('assistant')
            ->and($messages[1]['text'])->toBe('Raise it from the Credit Notes screen.');

        // The citations are what make the answer checkable. A chat that hides where its answer
        // came from is one nobody can verify, and on a screen about somebody's rent that is the
        // whole difference between an assistant and a liability.
        expect($messages[1]['sources'])->not->toBeEmpty()
            ->and(array_column($messages[1]['sources'], 'title'))->toContain('Credit Notes');

        // The box empties, so the next question can be typed straight away.
        expect($chat->get('question'))->toBe('');
    });
});

it('says something readable when there is no model, rather than nothing', function () {
    $asset = makeAsset();
    $this->actingAs(makeUser('super_admin'));
    app()->instance(AssistantModel::class, new NullAssistantModel);

    asTenant($asset, function () {
        $messages = Livewire::test(AssistantChat::class)
            ->set('question', 'vat return')
            ->call('ask')
            ->get('messages');

        // A chat that answers nothing at all reads as broken rather than as degraded — and the
        // citations below it are still the answer, so the turn is not wasted.
        expect($messages[1]['text'])->toBe(__('admin.assistant.chat.no_model_answer'))
            ->and($messages[1]['sources'])->not->toBeEmpty();
    });
});

it('records a chat question on the miss list like any other', function () {
    $asset = makeAsset();
    $this->actingAs(makeUser('super_admin'));
    app()->instance(AssistantModel::class, chatModel('An answer.'));

    asTenant($asset, function () {
        Livewire::test(AssistantChat::class)->set('question', 'vat return')->call('ask');

        // One question box or two, the miss list is one list. A chat that logged nowhere would
        // hide exactly the questions the A phase exists to collect.
        expect(AssistantQuestion::count())->toBe(1)
            ->and(AssistantQuestion::first()->model_answer)->toBe('An answer.');
    });
});

it('ignores an empty question instead of asking about nothing', function () {
    $asset = makeAsset();
    $this->actingAs(makeUser('super_admin'));

    asTenant($asset, function () {
        expect(Livewire::test(AssistantChat::class)->set('question', '   ')->call('ask')->get('messages'))
            ->toBe([]);
    });
});

it('rides on every admin page, not only its own screen', function () {
    $asset = makeAsset();
    $this->actingAs(makeUser('super_admin', [$asset->id]));

    // Registered on the panel's BODY_END hook, so the bubble is there wherever the operator is
    // working. Asserted through a REAL page request: a render hook that stopped firing would leave
    // every unit test green and the button gone from the whole panel.
    $this->get(App\Filament\Admin\Pages\Dashboard::getUrl(tenant: $asset))
        ->assertOk()
        ->assertSee('assistant-chat');
});

it('clears the conversation on request', function () {
    $asset = makeAsset();
    $this->actingAs(makeUser('super_admin'));
    app()->instance(AssistantModel::class, chatModel('An answer.'));

    asTenant($asset, function () {
        $chat = Livewire::test(AssistantChat::class)->set('question', 'vat return')->call('ask');

        expect($chat->get('messages'))->not->toBeEmpty();
        expect($chat->call('clear')->get('messages'))->toBe([]);
    });
});
