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
|   2. The accent follows the property. The panel re-skins `--primary-*` per property; those values
|      are copied into the child on load, so the handbook picks up the mall's brand colour the same
|      way every other surface does.
|   3. It fills the content area exactly once — the frame does not scroll the page, and the page
|      does not scroll the frame. A double scrollbar is the single clearest tell that something is
|      embedded.
|
| ── Two bugs this file shipped with, both worth stating so they are not reintroduced ─────────────
|
| **The iframe must never be hidden while it loads.** The first version used `loading="lazy"` and
| `x-show="ready"`. `x-show` sets `display: none`, and a lazily-loaded iframe that is display:none is
| by definition never near the viewport — so the browser never starts the load, `@load` never fires,
| `ready` never flips, and the panel sits on its spinner forever. The frame is now always rendered
| and always visible; the loader is an OVERLAY on top of it that fades out.
|
| **`@push('scripts')` does not work from here.** The panel layout renders `@stack('scripts')` before
| Livewire renders this component into it, so anything pushed from a page view is pushed onto a stack
| that has already been output — silently dropped. So the Alpine component is an inline `x-data`
| object literal with no external function to load, and the styles are a plain <style> element.
--}}
<x-filament-panels::page>
    <div
        class="atriom-handbook"
        x-data="{
            ready: false,
            observer: null,
            onResize: null,

            init() {
                // Mirror the panel's light/dark onto the frame for as long as this page is mounted.
                // Filament writes the class on <html>, and so does VitePress, so the sync is a copy
                // rather than a translation.
                this.observer = new MutationObserver(() => this.syncTheme());
                this.observer.observe(document.documentElement, {
                    attributes: true,
                    attributeFilter: ['class'],
                });

                // Size the frame to the space actually left below the panel header, MEASURED rather
                // than guessed at with a `calc(100dvh - 13rem)` constant.
                //
                // This is what stops the handbook's own toolbar appearing to scroll away: the
                // toolbar is `position: fixed` INSIDE the frame, so it is pinned to the frame's
                // viewport, not the browser's. If the frame is even slightly taller than the space
                // available, the PANEL page scrolls — and the whole frame, pinned toolbar included,
                // travels up with it. A guessed constant is wrong the moment a heading wraps or a
                // property banner appears.
                this.fit();
                this.onResize = () => this.fit();
                window.addEventListener('resize', this.onResize);

                // Belt and braces: if `load` never fires (a cached frame can fire it before Alpine
                // binds the listener), reveal anyway rather than sit on a spinner. The handbook
                // failing to load should look like a broken page, not like a slow one.
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

                this.$el.style.height = `${height}px`;
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
                    // Only reachable if the frame ever stops being same-origin. Failing quietly is
                    // right: the handbook still works, it just stops matching the panel.
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

                // The palette has a light/dark half of its own, so it is re-sent on every flip
                // rather than only once on load.
                if (this.ready) this.syncPalette();
            },

            syncPalette() {
                const doc = this.frameDoc();
                if (! doc) return;

                // Filament publishes its palette as space-separated RGB triplets (`249 250 251`)
                // for use as `rgb(var(--gray-50))`, and re-skins `--primary-*` PER PROPERTY. Read
                // the live computed values rather than hard-coding: that is what makes the handbook
                // follow a mall's brand colour the same way the rest of the panel does.
                const panel = getComputedStyle(document.documentElement);
                const root = doc.documentElement;

                const copy = (from, to) => {
                    const value = panel.getPropertyValue(from).trim();
                    if (value) root.style.setProperty(to, `rgb(${value})`);
                };

                copy('--primary-400', '--atriom-accent-soft');
                copy('--primary-500', '--atriom-accent');
                copy('--primary-600', '--atriom-accent-deep');

                // Surfaces follow the panel so the frame is the same material as the page around
                // it. Semantics (amber = waiting, red = a problem) are deliberately NOT sent —
                // re-tinting those to a brand would make a 'paid' pill and an 'overdue' pill look
                // alike, which is the one thing the colour there is carrying.
                [50, 100, 200, 300, 400, 500, 600, 700, 800, 900, 950].forEach((shade) => {
                    copy(`--gray-${shade}`, `--atriom-gray-${shade}`);
                });

                // Filament has no --gray-0; the handbook uses it for raised cards.
                root.style.setProperty(
                    '--atriom-gray-0',
                    document.documentElement.classList.contains('dark') ? 'rgb(24 24 27)' : '#fff',
                );
            },
        }"
    >
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

        {{-- An overlay, NOT a replacement — see the note above about lazy + hidden frames. --}}
        <div
            class="atriom-handbook__loading"
            x-show="! ready"
            x-transition.opacity.duration.300ms
            x-cloak
        >
            <x-filament::loading-indicator class="h-6 w-6" />
            <span>{{ __('admin.handbook.loading') }}</span>
        </div>
    </div>

    <style>
        /*
        | Fill the content area exactly once.
        |
        | 100dvh minus the panel chrome above it, so the handbook's own sidebar scrolls inside the
        | frame and the panel page never grows a second scrollbar. dvh rather than vh because mobile
        | browsers shrink the viewport as the URL bar retracts, and vh would leave a strip of dead
        | space under the frame at rest.
        */
        .atriom-handbook {
            position: relative;
            /* A first-paint fallback only — init() measures the real space and sets an exact
               pixel height, so the panel page never scrolls and the frame's own pinned toolbar
               never travels with it. */
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

        /* On a phone the panel chrome takes more of the screen; give the frame the rest. */
        @media (max-width: 1024px) {
            .atriom-handbook {
                block-size: calc(100dvh - 10rem);
            }
        }
    </style>
</x-filament-panels::page>
