<x-filament-widgets::widget>
    <x-filament::section>
        @if ($allDone)
            <x-slot name="heading">{{ __('admin.setup.title_done') }}</x-slot>
            <x-slot name="description">{{ __('admin.setup.description_done') }}</x-slot>

            {{-- Compact tick row when setup is complete (also covers the seeded demo). --}}
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:0.5rem;">
                @foreach ($steps as $step)
                    <div style="display:flex; align-items:center; gap:0.5rem; padding:0.55rem 0.75rem; border-radius:0.5rem; background:#d1fae5; border:1px solid #a7f3d0; color:#065f46; font-size:0.85rem;">
                        <x-filament::icon icon="heroicon-o-check-circle" style="width:1.1rem; height:1.1rem; color:#059669; flex-shrink:0;" />
                        <span style="font-weight:500;">{{ $step['label'] }}</span>
                    </div>
                @endforeach
            </div>
        @else
            <x-slot name="heading">{{ __('admin.setup.title') }}</x-slot>
            <x-slot name="description">
                {{ __('admin.setup.description', ['done' => $doneCount, 'total' => $totalCount]) }}
            </x-slot>

            {{-- Progress bar --}}
            <div style="margin-bottom:1rem;">
                <div style="height:0.5rem; width:100%; background:#e5e7eb; border-radius:9999px; overflow:hidden;">
                    <div style="height:100%; width:{{ $progressPct }}%; background:#0F766E; transition:width 0.4s ease;"></div>
                </div>
                <div style="margin-top:0.4rem; font-size:0.75rem; color:#6b7280;">
                    {{ $progressPct }}% — {{ $doneCount }} {{ __('admin.setup.of') }} {{ $totalCount }}
                </div>
            </div>

            {{-- Next-step prominent CTA --}}
            @if ($nextStep)
                <a href="{{ $nextStep['url'] }}"
                   style="display:flex; gap:1rem; align-items:center; padding:1.1rem; border-radius:0.625rem; background:linear-gradient(135deg, #0F766E 0%, #115E59 100%); color:white; text-decoration:none; margin-bottom:1rem; box-shadow:0 1px 2px rgba(0,0,0,0.08);">
                    <div style="flex-shrink:0; background:rgba(255,255,255,0.15); border-radius:0.5rem; padding:0.55rem;">
                        <x-filament::icon :icon="$nextStep['icon']" style="width:1.4rem; height:1.4rem;" />
                    </div>
                    <div style="flex:1; min-width:0;">
                        <div style="font-size:0.7rem; text-transform:uppercase; letter-spacing:0.05em; opacity:0.85;">
                            {{ __('admin.setup.next_step') }}
                        </div>
                        <div style="font-size:1.05rem; font-weight:600; margin-top:0.15rem;">{{ $nextStep['cta'] }}</div>
                        <div style="font-size:0.8rem; opacity:0.85; margin-top:0.2rem;">{{ $nextStep['description'] }}</div>
                    </div>
                    <div style="flex-shrink:0; font-size:1.5rem;">→</div>
                </a>
            @endif

            {{-- All-step checklist --}}
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(180px, 1fr)); gap:0.5rem;">
                @foreach ($steps as $step)
                    @php
                        $bg = $step['done'] ? '#d1fae5' : '#f9fafb';
                        $border = $step['done'] ? '#a7f3d0' : '#e5e7eb';
                        $text = $step['done'] ? '#065f46' : '#6b7280';
                        $iconColor = $step['done'] ? '#059669' : '#9ca3af';
                        $iconName = $step['done'] ? 'heroicon-o-check-circle' : 'heroicon-o-minus-circle';
                    @endphp
                    <div style="display:flex; align-items:center; gap:0.5rem; padding:0.55rem 0.75rem; border-radius:0.5rem; background:{{ $bg }}; border:1px solid {{ $border }}; color:{{ $text }}; font-size:0.85rem;">
                        <x-filament::icon :icon="$iconName" style="width:1.1rem; height:1.1rem; color:{{ $iconColor }}; flex-shrink:0;" />
                        <span style="font-weight:500;">{{ $step['label'] }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
