@php
    $operators = \App\Models\Operator::query()->where('is_active', true)->orderBy('name')->get();
    $currentId = \App\Support\CurrentOperator::id();
    $current = $currentId ? $operators->firstWhere('id', $currentId) : null;

    if ($operators->count() < 2) {
        return;
    }
@endphp
<div x-data="{ open: false }"
     style="position:relative;display:inline-flex;align-items:center;margin-inline-start:0.75rem;">
    <button type="button"
            @click="open = !open"
            @click.outside="open = false"
            style="display:inline-flex;align-items:center;gap:6px;padding:5px 11px;border-radius:8px;border:1px solid rgba(201,169,97,0.35);background:rgba(26,26,26,0.45);color:#F5F1EA;font-size:11px;font-weight:600;letter-spacing:0.3px;cursor:pointer;line-height:1;"
            aria-haspopup="true"
            :aria-expanded="open">
        <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:{{ $current?->primary_color ?? '#8C8478' }};"></span>
        <span>{{ $current?->name ?? __('admin.operators.all') }}</span>
        <svg width="10" height="10" viewBox="0 0 12 12" fill="currentColor" style="opacity:0.6;">
            <path d="M2 4l4 4 4-4" stroke="currentColor" stroke-width="1.5" fill="none" stroke-linecap="round" stroke-linejoin="round"/>
        </svg>
    </button>
    <div x-show="open"
         x-cloak
         x-transition.opacity.duration.100ms
         style="position:absolute;top:calc(100% + 6px);inset-inline-end:0;min-width:200px;padding:4px;border-radius:8px;background:#1A1A1A;border:1px solid rgba(201,169,97,0.25);box-shadow:0 8px 24px rgba(0,0,0,0.4);z-index:50;">
        <a href="{{ route('operator.switch', 'all') }}"
           style="display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:6px;text-decoration:none;color:{{ $current === null ? '#C9A961' : '#F5F1EA' }};font-size:12px;font-weight:{{ $current === null ? '600' : '500' }};">
            <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#8C8478;"></span>
            {{ __('admin.operators.all') }}
        </a>
        @foreach($operators as $op)
            <a href="{{ route('operator.switch', $op->slug) }}"
               style="display:flex;align-items:center;gap:8px;padding:8px 10px;border-radius:6px;text-decoration:none;color:{{ $current?->id === $op->id ? '#C9A961' : '#F5F1EA' }};font-size:12px;font-weight:{{ $current?->id === $op->id ? '600' : '500' }};">
                <span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:{{ $op->primary_color }};"></span>
                {{ $op->name }}
            </a>
        @endforeach
    </div>
</div>
