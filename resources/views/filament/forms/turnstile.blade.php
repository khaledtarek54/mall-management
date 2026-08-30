{{--
    Cloudflare Turnstile on the admin sign-in form.

    `wire:ignore` is load-bearing: Livewire re-renders this component on every keystroke in the
    email field, and without it the widget is torn out and rebuilt mid-challenge, which reads to
    the person signing in as a captcha that keeps resetting itself.

    The token is written into Livewire state with `defer` (the third argument `false`), so setting
    it does not fire a round trip of its own — the sign-in submit carries it.

    A token is SINGLE USE. Any failed submit therefore has to reset the widget, or the second
    attempt sends a token Cloudflare has already retired and the person is refused twice for one
    mistake; `Login` dispatches `turnstile-reset` on every failure path for exactly that.
--}}
@php
    $statePath = $getStatePath();
@endphp

<div
    wire:ignore
    x-data="{
        widgetId: null,
        boot() {
            const render = () => {
                if (! window.turnstile) return;
                this.widgetId = window.turnstile.render(this.$refs.widget, {
                    sitekey: @js(\App\Support\Turnstile::siteKey()),
                    callback: (token) => @this.set(@js($statePath), token, false),
                    'expired-callback': () => @this.set(@js($statePath), '', false),
                    'error-callback': () => @this.set(@js($statePath), '', false),
                });
            };

            if (window.turnstile) { render(); return; }

            const existing = document.getElementById('cf-turnstile-script');
            if (existing) { existing.addEventListener('load', render); return; }

            const script = document.createElement('script');
            script.id = 'cf-turnstile-script';
            script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
            script.async = true;
            script.defer = true;
            script.addEventListener('load', render);
            document.head.appendChild(script);
        },
    }"
    x-init="boot()"
    @turnstile-reset.window="window.turnstile && widgetId !== null && window.turnstile.reset(widgetId)"
>
    <div x-ref="widget"></div>
</div>
