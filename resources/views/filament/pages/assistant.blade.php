{{--
| "Ask Atriom" — the question box and its answers.
|
| Bilingual by construction rather than by a direction flag: every string is a translation key, and
| the guide bodies are resolved from the result's KEY in the reader's own locale (see
| `Assistant::guideFor()`), so a question typed in English against the English corpus still renders
| its answer entirely in Arabic. The panel's own `dir` attribute handles the rest — there is not a
| single `left`/`right` in this file, only logical properties, which is the same rule the handbook
| theme follows.
--}}
<x-filament-panels::page>
    <form wire:submit="ask">
        {{ $this->form }}

        <div class="mt-4">
            <x-filament::button type="submit" icon="heroicon-o-magnifying-glass">
                {{ __('admin.assistant.ask') }}
            </x-filament::button>
        </div>
    </form>

    @if ($asked)
        @php
            // Which result gets its guide opened in full. Records rank above screens and have no
            // guide, so "the first result" would expand nothing and leave the screen that answers
            // the question collapsed underneath.
            $firstScreenIndex = collect($results)->search(fn (array $r): bool => $r['kind'] === 'screen');
        @endphp

        <div class="mt-6 space-y-4">
            @forelse ($results as $result)
                @php
                    $guide = $this->guideFor($result['kind'], $result['key']);
                    $url = $result['url'] ?? null;
                @endphp

                <x-filament::section
                    :collapsible="$loop->index !== $firstScreenIndex"
                    :collapsed="$loop->index !== $firstScreenIndex"
                >
                    <x-slot name="heading">
                        {{ $result['title'] }}
                    </x-slot>

                    <x-slot name="description">
                        {{-- Spelled out rather than composed. A key built by interpolation resolves
                             to its PREFIX for TranslationKeyConformanceTest, so every leaf under it
                             is unchecked in both locales — the failure mode this codebase already
                             records for `admin.billing_preview.reason.*`. --}}
                        {{ match ($result['kind']) {
                            'report' => __('admin.assistant.kind_report'),
                            'record' => __('admin.assistant.kind_record'),
                            default => __('admin.assistant.kind_screen'),
                        } }}@if ($result['kind'] === 'record') · {{ $result['key'] }}@endif
                    </x-slot>

                    @if ($guide)
                        <p class="text-sm text-gray-600 dark:text-gray-300">
                            {{ $guide['purpose'] }}
                        </p>

                        {{-- Only the first result is opened in full. The rest are a heading and a
                             sentence, because a page of four complete guides answers nothing: the
                             reader has to re-read all of them to find which one was the answer. --}}
                        @if ($loop->index === $firstScreenIndex)
                            @foreach (['steps' => 'admin.assistant.steps', 'affects' => 'admin.assistant.affects', 'rules' => 'admin.assistant.rules'] as $field => $label)
                                @if (filled($guide[$field]))
                                    <div class="mt-4">
                                        <h4 class="text-sm font-semibold text-gray-950 dark:text-white">
                                            {{ __($label) }}
                                        </h4>
                                        <ul class="mt-1 list-disc space-y-1 text-sm text-gray-600 ps-5 dark:text-gray-300">
                                            @foreach ($guide[$field] as $line)
                                                <li>{{ $line }}</li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif
                            @endforeach
                        @endif
                    @endif

                    @if ($url)
                        <div class="mt-4">
                            <x-filament::link :href="$url">
                                {{ $result['kind'] === 'record'
                                    ? __('admin.assistant.open_record')
                                    : __('admin.assistant.open_screen', ['screen' => $result['title']]) }}
                            </x-filament::link>
                        </div>
                    @endif
                </x-filament::section>
            @empty
                {{-- The miss. It must say what to do next, not just report failure — and it must
                     say that the question WAS recorded, because "nobody will ever see this" is the
                     reason people stop reporting gaps. --}}
                <x-filament::section>
                    <x-slot name="heading">
                        {{ __('admin.assistant.no_answer_heading') }}
                    </x-slot>

                    <p class="text-sm text-gray-600 dark:text-gray-300">
                        {{ __('admin.assistant.no_answer_body') }}
                    </p>
                </x-filament::section>
            @endforelse
        </div>
    @endif
</x-filament-panels::page>
