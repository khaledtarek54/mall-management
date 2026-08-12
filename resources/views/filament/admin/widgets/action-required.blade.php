{{--
    The dashboard's work list.

    Styling notes, because this file used to be ~30 inline `style="…"` attributes of literal hex:
      · Colours come from Filament's palette (`--color-danger-50` &c., registered with Tailwind by
        filament/support), so a `dark:` variant exists for every one. The hard-coded `#fee2e2`
        pastels this replaced had none — in dark mode the panel around them went dark and the
        cards stayed bright, which is the state the operator would have met on day one.
      · No literal arrow glyph. `→` is a character, so it kept pointing right in Arabic; the
        chevron below is mirrored by the `rtl:` variant, and the whole panel is bilingual.
      · Tailwind picks these classes up because the theme declares
        `@source '../../../resources/views/filament/**/*'` (resources/css/filament/theme.css).
--}}
@php
    $tone = [
        'danger' => [
            'card' => 'bg-danger-50 border-danger-200 hover:border-danger-300 dark:bg-danger-400/10 dark:border-danger-400/30 dark:hover:border-danger-400/50',
            'icon' => 'text-danger-600 dark:text-danger-400',
            'title' => 'text-danger-800 dark:text-danger-200',
            'body' => 'text-danger-700/80 dark:text-danger-200/70',
        ],
        'warning' => [
            'card' => 'bg-warning-50 border-warning-200 hover:border-warning-300 dark:bg-warning-400/10 dark:border-warning-400/30 dark:hover:border-warning-400/50',
            'icon' => 'text-warning-600 dark:text-warning-400',
            'title' => 'text-warning-800 dark:text-warning-200',
            'body' => 'text-warning-700/80 dark:text-warning-200/70',
        ],
        'info' => [
            'card' => 'bg-info-50 border-info-200 hover:border-info-300 dark:bg-info-400/10 dark:border-info-400/30 dark:hover:border-info-400/50',
            'icon' => 'text-info-600 dark:text-info-400',
            'title' => 'text-info-800 dark:text-info-200',
            'body' => 'text-info-700/80 dark:text-info-200/70',
        ],
        'success' => [
            'card' => 'bg-success-50 border-success-200 hover:border-success-300 dark:bg-success-400/10 dark:border-success-400/30 dark:hover:border-success-400/50',
            'icon' => 'text-success-600 dark:text-success-400',
            'title' => 'text-success-800 dark:text-success-200',
            'body' => 'text-success-700/80 dark:text-success-200/70',
        ],
    ];
@endphp

<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">{{ __('admin.widgets.action_required.title') }}</x-slot>
        <x-slot name="description">{{ __('admin.widgets.action_required.description') }}</x-slot>

        @if (empty($items))
            <div class="flex items-center gap-2 rounded-lg bg-success-50 px-3 py-3 text-sm text-success-700 dark:bg-success-400/10 dark:text-success-300">
                <x-filament::icon icon="heroicon-o-check-circle" class="h-5 w-5 shrink-0 text-success-600 dark:text-success-400" />
                <span>{{ __('admin.widgets.action_required.all_clear') }}</span>
            </div>
        @else
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                @foreach ($items as $item)
                    @php $t = $tone[$item['color']] ?? $tone['info']; @endphp

                    <a
                        href="{{ $item['url'] }}"
                        @class([
                            'group flex items-start gap-3 rounded-lg border p-3 no-underline transition',
                            'focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600',
                            $t['card'],
                        ])
                    >
                        <x-filament::icon :icon="$item['icon']" @class(['mt-0.5 h-6 w-6 shrink-0', $t['icon']]) />

                        <div class="min-w-0 flex-1">
                            <div @class(['text-sm font-semibold', $t['title']])>{{ $item['title'] }}</div>
                            <div @class(['mt-0.5 text-xs', $t['body']])>{{ $item['body'] }}</div>
                        </div>

                        {{-- Mirrored under RTL: a literal → would point away from the text in Arabic. --}}
                        <x-filament::icon
                            icon="heroicon-m-chevron-right"
                            @class([
                                'mt-1 h-4 w-4 shrink-0 opacity-40 transition group-hover:opacity-100 rtl:rotate-180',
                                $t['icon'],
                            ])
                        />
                        <span class="sr-only">{{ __('admin.widgets.action_required.view') }}</span>
                    </a>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
