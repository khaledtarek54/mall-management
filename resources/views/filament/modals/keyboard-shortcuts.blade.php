{{-- Rendered from App\Filament\Actions\KeyboardShortcutsAction::rows(). Deliberately a plain
     definition list rather than a table: three rows, and a table's header would say nothing the
     two columns do not already say. --}}
<dl class="fi-ta-text divide-y divide-gray-200 dark:divide-white/10">
    @foreach ($shortcuts as $shortcut)
        <div class="flex items-center justify-between gap-4 py-3">
            <dt class="text-sm text-gray-950 dark:text-white">
                {{ $shortcut['label'] }}
            </dt>
            <dd>
                <kbd class="rounded-md bg-gray-50 px-2 py-1 font-mono text-xs text-gray-700 ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-gray-300 dark:ring-white/20">
                    {{ $shortcut['keys'] }}
                </kbd>
            </dd>
        </div>
    @endforeach
</dl>
