{{--
| The floating assistant.
|
| SIDE IS NOT SET HERE, and that is the point: `inset-inline-end` is a CSS logical property, so the
| panel's own `dir` puts this bottom-RIGHT in English and bottom-LEFT in Arabic with no branch, no
| flag and no second stylesheet. There is not one `left` or `right` in this file — the same rule the
| handbook theme follows, and the reason it needs no RTL plugin.
--}}
<div
    class="fixed bottom-6 z-50 print:hidden"
    style="inset-inline-end: 1.5rem;"
    x-data="{
        scroll() { $nextTick(() => { const t = $refs.thread; if (t) t.scrollTop = t.scrollHeight }) }
    }"
    x-init="scroll()"
    @if ($open) wire:key="assistant-open" @endif
>
    {{-- The panel. Sized so it never covers the record a question is about. --}}
    @if ($open)
        <div class="mb-3 flex w-[22rem] max-w-[calc(100vw-3rem)] flex-col overflow-hidden rounded-xl bg-white shadow-2xl ring-1 ring-gray-950/10 dark:bg-gray-900 dark:ring-white/10">

            <div class="flex items-center justify-between gap-2 bg-gray-950 px-4 py-3 text-white dark:bg-gray-800">
                <div class="min-w-0">
                    <p class="truncate text-sm font-semibold">{{ __('admin.assistant.chat.title') }}</p>
                    <p class="truncate text-xs text-gray-300">
                        {{ $this->modelIsOn()
                            ? __('admin.assistant.chat.subtitle')
                            : __('admin.assistant.chat.subtitle_no_model') }}
                    </p>
                </div>

                <div class="flex shrink-0 items-center gap-1">
                    @if (filled($messages))
                        <button type="button" wire:click="clear" class="rounded p-1 text-gray-300 hover:text-white" title="{{ __('admin.assistant.chat.clear') }}">
                            <x-filament::icon icon="heroicon-o-trash" class="h-4 w-4" />
                        </button>
                    @endif
                    <button type="button" wire:click="toggle" class="rounded p-1 text-gray-300 hover:text-white" title="{{ __('admin.assistant.chat.close') }}">
                        <x-filament::icon icon="heroicon-o-x-mark" class="h-4 w-4" />
                    </button>
                </div>
            </div>

            <div x-ref="thread" class="max-h-96 min-h-[8rem] space-y-3 overflow-y-auto px-4 py-3">
                @forelse ($messages as $message)
                    @if ($message['role'] === 'user')
                        {{-- `ms-auto` — margin-inline-start — so the reader's own turn sits on the
                             trailing edge in both directions without a branch. --}}
                        <div class="ms-auto max-w-[85%] rounded-lg rounded-ee-sm bg-primary-600 px-3 py-2 text-sm text-white">
                            {{ $message['text'] }}
                        </div>
                    @else
                        <div class="me-auto max-w-[92%] space-y-2">
                            <div class="rounded-lg rounded-es-sm bg-gray-100 px-3 py-2 text-sm text-gray-950 dark:bg-gray-800 dark:text-white">
                                {!! nl2br(e($message['text'])) !!}
                            </div>

                            {{-- The rating. Two buttons and nothing else: a comment box asks for
                                 effort nobody spends mid-task, and a five-star scale asks a
                                 question ("how good, exactly?") that no two people answer the same
                                 way. Useful / not useful is the one judgement a reader can make
                                 without stopping work. --}}
                            @if ($message['id'] ?? null)
                                <div class="flex items-center gap-1 px-1">
                                    <button type="button" wire:click="rate({{ $message['id'] }}, true)"
                                        @class([
                                            'rounded p-1 transition',
                                            'text-success-600' => ($message['helpful'] ?? null) === true,
                                            'text-gray-400 hover:text-gray-600' => ($message['helpful'] ?? null) !== true,
                                        ])
                                        title="{{ __('admin.assistant.chat.helpful') }}">
                                        <x-filament::icon icon="heroicon-o-hand-thumb-up" class="h-3.5 w-3.5" />
                                    </button>
                                    <button type="button" wire:click="rate({{ $message['id'] }}, false)"
                                        @class([
                                            'rounded p-1 transition',
                                            'text-danger-600' => ($message['helpful'] ?? null) === false,
                                            'text-gray-400 hover:text-gray-600' => ($message['helpful'] ?? null) !== false,
                                        ])
                                        title="{{ __('admin.assistant.chat.not_helpful') }}">
                                        <x-filament::icon icon="heroicon-o-hand-thumb-down" class="h-3.5 w-3.5" />
                                    </button>
                                </div>
                            @endif

                            @if (filled($message['sources']))
                                <div class="flex flex-wrap gap-1.5 px-1">
                                    @foreach ($message['sources'] as $source)
                                        @if ($source['url'])
                                            <a href="{{ $source['url'] }}" class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600 hover:bg-gray-200 dark:bg-gray-800 dark:text-gray-300">
                                                {{ $source['title'] }}
                                            </a>
                                        @else
                                            <span class="rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                                                {{ $source['title'] }}
                                            </span>
                                        @endif
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif
                @empty
                    <p class="py-4 text-center text-sm text-gray-500 dark:text-gray-400">
                        {{ __('admin.assistant.chat.empty') }}
                    </p>
                @endforelse

                <div wire:loading wire:target="ask" class="me-auto max-w-[60%] rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                    {{ __('admin.assistant.chat.thinking') }}
                </div>
            </div>

            <form wire:submit="ask" x-on:submit="scroll()" class="flex items-center gap-2 border-t border-gray-200 px-3 py-2 dark:border-white/10">
                <input
                    type="text"
                    wire:model="question"
                    maxlength="300"
                    placeholder="{{ __('admin.assistant.question_placeholder') }}"
                    class="min-w-0 flex-1 border-0 bg-transparent text-sm text-gray-950 placeholder-gray-400 focus:ring-0 dark:text-white"
                    autocomplete="off"
                />
                <button type="submit" wire:loading.attr="disabled" wire:target="ask" class="shrink-0 rounded-lg bg-primary-600 p-2 text-white disabled:opacity-50">
                    {{-- The icon points along the writing direction: an arrow that points right in
                         an Arabic panel reads as "back". --}}
                    <x-filament::icon icon="heroicon-o-paper-airplane" class="h-4 w-4 rtl:-scale-x-100" />
                </button>
            </form>
        </div>
    @endif

    {{-- The bubble. --}}
    <button
        type="button"
        wire:click="toggle"
        class="flex h-14 w-14 items-center justify-center rounded-full bg-primary-600 text-white shadow-lg ring-1 ring-gray-950/5 transition hover:bg-primary-500"
        title="{{ __('admin.assistant.chat.title') }}"
        aria-label="{{ __('admin.assistant.chat.title') }}"
        style="margin-inline-start: auto;"
    >
        <x-filament::icon :icon="$open ? 'heroicon-o-x-mark' : 'heroicon-o-chat-bubble-left-right'" class="h-6 w-6" />
    </button>
</div>
