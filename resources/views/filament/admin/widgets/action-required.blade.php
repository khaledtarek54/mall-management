@php
    $colorMap = [
        'danger' => ['bg' => '#fee2e2', 'border' => '#fecaca', 'text' => '#991b1b', 'icon' => '#dc2626'],
        'warning' => ['bg' => '#fef3c7', 'border' => '#fde68a', 'text' => '#92400e', 'icon' => '#d97706'],
        'info' => ['bg' => '#dbeafe', 'border' => '#bfdbfe', 'text' => '#1e40af', 'icon' => '#2563eb'],
        'success' => ['bg' => '#d1fae5', 'border' => '#a7f3d0', 'text' => '#065f46', 'icon' => '#059669'],
    ];
@endphp

<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">{{ __('admin.widgets.action_required.title') }}</x-slot>
        <x-slot name="description">{{ __('admin.widgets.action_required.description') }}</x-slot>

        @if (empty($items))
            <div style="display:flex; align-items:center; gap:0.5rem; padding:0.75rem; color:#065f46;">
                <x-filament::icon icon="heroicon-o-check-circle" style="width:1.25rem; height:1.25rem; color:#059669;" />
                <span>{{ __('admin.widgets.action_required.all_clear') }}</span>
            </div>
        @else
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:0.75rem;">
                @foreach ($items as $item)
                    @php $c = $colorMap[$item['color']] ?? $colorMap['info']; @endphp
                    <a href="{{ $item['url'] }}"
                       style="display:flex; gap:0.75rem; align-items:flex-start; padding:0.85rem; border-radius:0.5rem; background:{{ $c['bg'] }}; border:1px solid {{ $c['border'] }}; color:{{ $c['text'] }}; text-decoration:none;">
                        <div style="flex-shrink:0;">
                            <x-filament::icon :icon="$item['icon']" style="width:1.4rem; height:1.4rem; color:{{ $c['icon'] }};" />
                        </div>
                        <div style="flex:1; min-width:0;">
                            <div style="font-weight:600; font-size:0.95rem;">{{ $item['title'] }}</div>
                            <div style="font-size:0.8rem; opacity:0.85;">{{ $item['body'] }}</div>
                        </div>
                        <div style="flex-shrink:0; align-self:center; font-size:0.8rem; opacity:0.7;">
                            {{ __('admin.widgets.action_required.view') }} →
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
