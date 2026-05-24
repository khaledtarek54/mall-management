@php
    $operators = \App\Models\Operator::query()->where('is_active', true)->orderBy('name')->get();
    $currentId = \App\Support\CurrentOperator::id();
    $current = $currentId ? $operators->firstWhere('id', $currentId) : null;

    if ($operators->count() < 2) {
        return;
    }
@endphp
<style>
    .atriom-op-switch-btn {
        display: inline-flex; align-items: center; gap: 6px;
        padding: 5px 11px; border-radius: 8px;
        border: 1px solid rgba(0,0,0,0.12);
        background: rgba(0,0,0,0.03);
        color: #18181B;
        font-size: 11px; font-weight: 600; letter-spacing: 0.3px;
        cursor: pointer; line-height: 1;
        transition: background 0.15s ease, border-color 0.15s ease;
    }
    .atriom-op-switch-btn:hover { background: rgba(0,0,0,0.06); border-color: rgba(0,0,0,0.18); }
    .dark .atriom-op-switch-btn {
        border-color: rgba(255,255,255,0.14);
        background: rgba(255,255,255,0.04);
        color: #FAFAFA;
    }
    .dark .atriom-op-switch-btn:hover { background: rgba(255,255,255,0.08); border-color: rgba(255,255,255,0.22); }

    .atriom-op-switch-menu {
        position: absolute; top: calc(100% + 6px); inset-inline-end: 0;
        min-width: 220px; padding: 4px; border-radius: 8px;
        background: #FFFFFF;
        border: 1px solid rgba(0,0,0,0.08);
        box-shadow: 0 12px 32px rgba(0,0,0,0.10);
        z-index: 50;
    }
    .dark .atriom-op-switch-menu {
        background: #18181B;
        border-color: rgba(255,255,255,0.10);
        box-shadow: 0 12px 32px rgba(0,0,0,0.5);
    }
    .atriom-op-switch-item {
        display: flex; align-items: center; gap: 8px;
        padding: 8px 10px; border-radius: 6px;
        text-decoration: none; color: #18181B;
        font-size: 12px; font-weight: 500;
    }
    .atriom-op-switch-item:hover { background: rgba(0,0,0,0.04); }
    .atriom-op-switch-item--active { color: #0F766E; font-weight: 600; background: rgba(15,118,110,0.06); }
    .dark .atriom-op-switch-item { color: #FAFAFA; }
    .dark .atriom-op-switch-item:hover { background: rgba(255,255,255,0.06); }
    .dark .atriom-op-switch-item--active { color: #14B8A6; background: rgba(20,184,166,0.10); }
</style>
<div x-data="{ open: false }" style="position:relative;display:inline-flex;align-items:center;margin-inline-start:0.75rem;">
    <button type="button"
            class="atriom-op-switch-btn"
            @click="open = !open"
            @click.outside="open = false"
            aria-haspopup="true"
            :aria-expanded="open">
        <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:{{ $current?->primary_color ?? '#71717A' }};box-shadow:0 0 6px {{ $current?->primary_color ?? '#71717A' }}40;"></span>
        <span>{{ $current?->name ?? __('admin.operators.all') }}</span>
        <svg width="10" height="10" viewBox="0 0 12 12" fill="currentColor" style="opacity:0.6;">
            <path d="M2 4l4 4 4-4" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </button>
    <div x-show="open"
         x-cloak
         x-transition.opacity.duration.100ms
         class="atriom-op-switch-menu">
        <a href="{{ route('operator.switch', 'all') }}"
           class="atriom-op-switch-item {{ $current === null ? 'atriom-op-switch-item--active' : '' }}">
            <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#71717A;"></span>
            {{ __('admin.operators.all') }}
        </a>
        @foreach($operators as $op)
            <a href="{{ route('operator.switch', $op->slug) }}"
               class="atriom-op-switch-item {{ $current?->id === $op->id ? 'atriom-op-switch-item--active' : '' }}">
                <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:{{ $op->primary_color }};box-shadow:0 0 6px {{ $op->primary_color }}40;"></span>
                {{ $op->name }}
            </a>
        @endforeach
    </div>
</div>
