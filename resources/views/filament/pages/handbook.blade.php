{{--
| The handbook, framed inside the panel.
|
| Three things make it read as part of the app rather than a website pasted into one, and all three
| are only possible because the frame is SAME-ORIGIN: the parent can reach into the child document
| directly, so there is no postMessage protocol to keep in sync on both sides.
|
|   1. Appearance follows the panel. Filament toggles `class="dark"` on <html>; so does VitePress.
|      A MutationObserver mirrors it, so switching theme in the topbar changes the handbook in the
|      same frame of animation rather than on the next page load.
|   2. The palette follows the panel — its whole grey ramp and its per-property `--primary-*`, so
|      the frame is the same material as the page around it and re-skins with the property.
|   3. It fills the space left below the header exactly once, MEASURED rather than guessed, so the
|      panel page never scrolls and the frame's own pinned toolbar never travels with it.
|
| ── Three bugs this file shipped with. Each cost a round trip; none should return ────────────────
|
| **1 · The frame must never be hidden while it loads.** The first version used `loading="lazy"`
| together with `x-show="ready"`. `x-show` sets `display: none`, and a lazily-loaded iframe that is
| display:none is by definition never near the viewport — so the browser never starts the load, the
| load event never fires, `ready` never flips, and the page sits on its spinner forever. The frame is
| always rendered and always visible; the loader is an OVERLAY that fades out.
|
| **2 · The behaviour cannot live in a pushed stack.** The panel layout renders `@stack('scripts')`
| before Livewire renders this component into it, so anything pushed from a page view lands on a
| stack that has already been output — silently dropped, and the Alpine component is undefined.
|
| **3 · …and it cannot live in the `x-data` ATTRIBUTE either.** It did, and a code comment inside it
| contained the phrase "the colouring is very bad" — with real double quotes. HTML has no idea it is
| looking at JavaScript: the parser closed the attribute at the first `"` and rendered the remaining
| ~4kB of the component as visible text on the page. So the component is registered through
| `Alpine.data()` in a plain inline <script> (which DOES run — this panel is not in SPA mode), and
| the attribute is a bare identifier that no amount of prose can break.
--}}
<x-filament-panels::page>
    <div class="atriom-handbook" x-data="atriomHandbook">
        <iframe
            x-ref="frame"
            src="{{ $this->getFrameUrl() }}"
            @load="reveal()"
            class="atriom-handbook__frame"
            title="{{ __('admin.handbook.page_title') }}"
            {{-- First-party build: it needs scripts for the interactive components, and same-origin
                 so the parent can theme it. Nothing else is granted. --}}
            sandbox="allow-same-origin allow-scripts allow-popups allow-forms allow-downloads"
            referrerpolicy="same-origin"
        ></iframe>

        {{-- An overlay, NOT a replacement — see bug 1 above. --}}
        <div class="atriom-handbook__loading" x-show="! ready" x-transition.opacity.duration.300ms x-cloak>
            <x-filament::loading-indicator class="h-6 w-6" />
            <span>{{ __('admin.handbook.loading') }}</span>
        </div>
    </div>

    <script>
        // Registered on alpine:init rather than as a bare global, so the definition cannot race
        // Alpine's own boot regardless of where in the document this script lands.
        document.addEventListener('alpine:init', () => {
            Alpine.data('atriomHandbook', () => ({
                ready: false,
                observer: null,
                onResize: null,

                init() {
                    // Mirror the panel's light/dark onto the frame for as long as this page is
                    // mounted. Filament writes the class on <html>, and so does VitePress, so the
                    // sync is a copy rather than a translation.
                    this.observer = new MutationObserver(() => this.syncTheme());
                    this.observer.observe(document.documentElement, {
                        attributes: true,
                        attributeFilter: ['class'],
                    });

                    // Size the frame to the space actually left below the panel header, MEASURED
                    // rather than guessed at with a calc() constant.
                    //
                    // This is what stops the handbook's own toolbar appearing to scroll away: that
                    // toolbar is fixed to the FRAME's viewport, not the browser's. If the frame is
                    // even slightly taller than the space available, the panel page scrolls and the
                    // whole frame travels up with it. A constant is wrong the moment a heading wraps
                    // or a property banner appears.
                    this.fit();
                    this.onResize = () => this.fit();
                    window.addEventListener('resize', this.onResize);

                    // Belt and braces: a cached frame can fire load before Alpine binds the
                    // listener. Reveal anyway rather than sit on a spinner — a handbook that failed
                    // to load should look broken, not slow.
                    setTimeout(() => this.reveal(), 4000);
                },

                destroy() {
                    this.observer?.disconnect();
                    window.removeEventListener('resize', this.onResize);
                },

                fit() {
                    const top = this.$el.getBoundingClientRect().top;
                    // A small gutter so the frame does not butt against the bottom of the window.
                    const height = Math.max(360, window.innerHeight - top - 24);

                    this.$el.style.height = height + 'px';
                },

                reveal() {
                    if (this.ready) return;

                    this.ready = true;
                    this.syncTheme();
                    this.syncPalette();
                },

                frameDoc() {
                    try {
                        return this.$refs.frame?.contentDocument ?? null;
                    } catch (e) {
                        // Only reachable if the frame ever stops being same-origin. Failing quietly
                        // is right: the handbook still works, it just stops matching the panel.
                        return null;
                    }
                },

                syncTheme() {
                    const doc = this.frameDoc();
                    if (! doc) return;

                    doc.documentElement.classList.toggle(
                        'dark',
                        document.documentElement.classList.contains('dark'),
                    );
                },

                syncPalette() {
                    const doc = this.frameDoc();
                    if (! doc) return;

                    // Filament's palette variables hold COMPLETE colour values (oklch(...)) and are
                    // consumed as var(--gray-50), not rgb(var(--gray-50)). Copy them verbatim.
                    //
                    // The first version wrapped each in rgb(...). A custom property accepts any
                    // token sequence, so nothing errored: the variable WAS set, var() therefore
                    // never fell back to the safe default, and every surface in the frame resolved
                    // to an invalid colour. Silent, and total.
                    const panel = getComputedStyle(document.documentElement);
                    const root = doc.documentElement;

                    const copy = (from, to) => {
                        const value = panel.getPropertyValue(from).trim();
                        if (value) root.style.setProperty(to, value);
                    };

                    // Re-skinned per property, so the handbook follows a mall's brand colour.
                    copy('--primary-400', '--atriom-accent-soft');
                    copy('--primary-500', '--atriom-accent');
                    copy('--primary-600', '--atriom-accent-deep');

                    // Surfaces follow the panel. Semantics do NOT come from here — embed.css derives
                    // their tints from whatever the surface turns out to be, so amber stays amber.
                    [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950].forEach((shade) => {
                        copy('--gray-' + shade, '--atriom-gray-' + shade);
                    });
                },
            }));
        });
    </script>

    <style>
        /*
        | The height below is a FIRST-PAINT FALLBACK only — init() measures the real space and sets
        | an exact pixel height. dvh rather than vh because mobile browsers shrink the viewport as
        | the URL bar retracts, and vh would leave a strip of dead space under the frame at rest.
        */
        .atriom-handbook {
            position: relative;
            block-size: calc(100dvh - 13rem);
            min-block-size: 22.5rem;
            border-radius: 0.75rem;
            overflow: hidden;
            border: 1px solid var(--gray-200);
            background: var(--gray-50);
        }

        .dark .atriom-handbook {
            border-color: color-mix(in oklab, var(--gray-700) 60%, transparent);
            background: var(--gray-900);
        }

        .atriom-handbook__frame {
            inline-size: 100%;
            block-size: 100%;
            border: 0;
            display: block;
        }

        .atriom-handbook__loading {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.625rem;
            font-size: 0.875rem;
            color: var(--gray-500);
            background: var(--gray-50);
        }

        .dark .atriom-handbook__loading {
            background: var(--gray-900);
        }

        /* Without this the overlay flashes before Alpine binds — x-show only takes effect once the
           component initialises, and until then the element renders as normal. */
        [x-cloak] {
            display: none !important;
        }
    </style>
</x-filament-panels::page>
