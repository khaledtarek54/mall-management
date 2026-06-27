@php $url = $invoice->paymentLinkUrl(); $fid = 'pl-url-'.$invoice->id; @endphp
<div style="display:flex;flex-direction:column;gap:.85rem;">
    <p style="font-size:.875rem;color:#6b7280;margin:0;">{{ __('admin.actions.payment_link_hint') }}</p>

    <div style="display:flex;gap:.5rem;">
        <input id="{{ $fid }}" readonly onclick="this.select()" value="{{ $url }}"
            style="flex:1;min-width:0;padding:.6rem .75rem;border:1px solid #d1d5db;border-radius:.5rem;font-size:.8125rem;" />
        <button type="button"
            onclick="(function(b){var i=document.getElementById('{{ $fid }}');var done=function(){var o=b.innerText;b.innerText='{{ __('admin.actions.copied') }}';b.style.background='#16a34a';setTimeout(function(){b.innerText=o;b.style.background='#0f766e';},1500);};if(navigator.clipboard){navigator.clipboard.writeText(i.value).then(done,function(){i.select();document.execCommand('copy');done();});}else{i.select();document.execCommand('copy');done();}})(this)"
            style="padding:.6rem .9rem;border:0;border-radius:.5rem;background:#0f766e;color:#fff;font-weight:600;cursor:pointer;white-space:nowrap;">{{ __('admin.actions.copy') }}</button>
    </div>

    <div style="display:flex;flex-direction:column;align-items:center;gap:.4rem;padding-top:.25rem;">
        <div style="background:#fff;padding:.5rem;border:1px solid #e5e7eb;border-radius:.75rem;line-height:0;">{!! $invoice->paymentLinkQrSvg(170) !!}</div>
        <span style="font-size:.75rem;color:#9ca3af;">{{ __('admin.actions.scan_to_pay') }}</span>
    </div>
</div>
