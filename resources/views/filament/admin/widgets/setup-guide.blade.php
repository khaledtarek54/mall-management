{{--
    First-run checklist. Same styling rationale as action-required.blade.php: Filament palette
    classes with `dark:` variants instead of literal hex (the old `#d1fae5` / `#0F766E` gradient
    was invisible-to-garish in dark mode), and no literal arrow glyph so RTL mirrors correctly.
--}}
<x-filament-widgets::widget>
    <x-filament::section>
        @if ($allDone)
            <x-slot name="heading">{{ __('admin.setup.title_done') }}</x-slot>
            <x-slot name="description">{{ __('admin.setup.description_done') }}</x-slot>

            {{-- Compact tick row when setup is complete (also covers the seeded demo). --}}
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($steps as $step)
                    <div class="flex items-center gap-2 rounded-lg border border-success-200 bg-success-50 px-3 py-2 text-xs font-medium text-success-700 dark:border-success-400/30 dark:bg-success-400/10 dark:text-success-300">
                        <x-filament::icon icon="heroicon-o-check-circle" class="h-4 w-4 shrink-0 text-success-600 dark:text-success-400" />
                        <span class="truncate">{{ $step['label'] }}</span>
                    </div>
                @endforeach
            </div>
        @else
            <x-slot name="heading">{{ __('admin.setup.title') }}</x-slot>
            <x-slot name="description">
                {{ __('admin.setup.description', ['done' => $doneCount, 'total' => $totalCount]) }}
            </x-slot>

            {{-- Progress bar --}}
            <div class="mb-4">
                <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                    <div
                        class="h-full rounded-full bg-primary-600 transition-[width] duration-500 dark:bg-primary-500"
                        style="width: {{ $progressPct }}%"
                        role="progressbar"
                        aria-valuenow="{{ $doneCount }}"
                        aria-valuemin="0"
                        aria-valuemax="{{ $totalCount }}"
                    ></div>
                </div>
                <div class="mt-1.5 text-xs text-gray-500 dark:text-gray-400">
                    {{ $progressPct }}% — {{ $doneCount }} {{ __('admin.setup.of') }} {{ $totalCount }}
                </div>
            </div>

            {{-- Next-step prominent CTA --}}
            @if ($nextStep)
                <a
                    href="{{ $nextStep['url'] }}"
                    class="group mb-4 flex items-center gap-4 rounded-xl bg-primary-600 p-4 text-white no-underline shadow-sm transition hover:bg-primary-500 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600 dark:bg-primary-500 dark:hover:bg-primary-400"
                >
                    <div class="shrink-0 rounded-lg bg-white/15 p-2">
                        <x-filament::icon :icon="$nextStep['icon']" class="h-6 w-6" />
                    </div>

                    <div class="min-w-0 flex-1">
                        <div class="text-[0.7rem] uppercase tracking-wide opacity-85">
                            {{ __('admin.setup.next_step') }}
                        </div>
                        <div class="mt-0.5 text-base font-semibold">{{ $nextStep['cta'] }}</div>
                        <div class="mt-0.5 text-xs opacity-85">{{ $nextStep['description'] }}</div>
                    </div>

                    {{-- Mirrored under RTL. --}}
                    <x-filament::icon
                        icon="heroicon-m-arrow-right"
                        class="h-5 w-5 shrink-0 transition group-hover:translate-x-0.5 rtl:rotate-180 rtl:group-hover:-translate-x-0.5"
                    />
                </a>
            @endif

            {{-- All-step checklist --}}
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                @foreach ($steps as $step)
                    <div @class([
                        'flex items-center gap-2 rounded-lg border px-3 py-2 text-xs font-medium',
                        'border-success-200 bg-success-50 text-success-700 dark:border-success-400/30 dark:bg-success-400/10 dark:text-success-300' => $step['done'],
                        'border-gray-200 bg-gray-50 text-gray-500 dark:border-white/10 dark:bg-white/5 dark:text-gray-400' => ! $step['done'],
                    ])>
                        <x-filament::icon
                            :icon="$step['done'] ? 'heroicon-o-check-circle' : 'heroicon-o-minus-circle'"
                            @class([
                                'h-4 w-4 shrink-0',
                                'text-success-600 dark:text-success-400' => $step['done'],
                                'text-gray-400 dark:text-gray-500' => ! $step['done'],
                            ])
                        />
                        <span class="truncate">{{ $step['label'] }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
