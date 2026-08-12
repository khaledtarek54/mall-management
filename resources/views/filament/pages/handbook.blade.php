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
--}}
<x-filament-panels::page>
    <div
        x-data="atriomHandbook(@js($this->getFrameUrl()))"
        x-init="init()"
        class="atriom-handbook"
    >
        {{-- Shown until the frame reports ready, so the panel never flashes an empty white box. --}}
        <div
            x-show="!ready"
            x-transition.opacity
            class="atriom-handbook__loading"
        >
            <x-filament::loading-indicator class="h-6 w-6" />
            <span>{{ __('admin.handbook.loading') }}</span>
        </div>

        <iframe
            x-ref="frame"
            :src="src"
            @load="onLoad()"
            x-show="ready"
            x-transition.opacity.duration.200ms
            class="atriom-handbook__frame"
            title="{{ __('admin.handbook.page_title') }}"
            {{-- The handbook is our own first-party build; it needs scripts for the interactive
                 components and same-origin so the parent can theme it. Nothing else is granted. --}}
            sandbox="allow-same-origin allow-scripts allow-popups allow-forms"
            referrerpolicy="same-origin"
            loading="lazy"
        ></iframe>
    </div>

    @push('scripts')
        <script>
            function atriomHandbook(src) {
                return {
                    src,
                    ready: false,
                    observer: null,

                    init() {
                        // Mirror the panel's light/dark onto the frame for as long as this page is
                        // mounted. Filament writes the class on <html>, and so does VitePress, so
                        // the sync is a one-line copy rather than a translation.
                        this.observer = new MutationObserver(() => this.syncTheme());
                        this.observer.observe(document.documentElement, {
                            attributes: true,
                            attributeFilter: ['class'],
                        });

                        // Livewire tears the page down on navigation; leaving the observer running
                        // would keep a dead frame reference alive for the rest of the session.
                        this.$el.addEventListener('alpine:destroyed', () => this.observer?.disconnect());
                    },

                    onLoad() {
                        this.ready = true;
                        this.syncTheme();
                        this.syncAccent();
                    },

                    /** @returns {Document|null} */
                    frameDoc() {
                        try {
                            return this.$refs.frame?.contentDocument ?? null;
                        } catch (e) {
                            // Only reachable if the frame ever stops being same-origin. Failing
                            // quietly is right: the handbook still works, it just stops matching.
                            return null;
                        }
                    },

                    syncTheme() {
                        const doc = this.frameDoc();
                        if (!doc) return;

                        doc.documentElement.classList.toggle(
                            'dark',
                            document.documentElement.classList.contains('dark'),
                        );
                    },

                    syncAccent() {
                        const doc = this.frameDoc();
                        if (!doc) return;

                        // The panel re-skins --primary-* per property (AdminPanelProvider), so read
                        // the live computed values rather than a hard-coded hex.
                        const panel = getComputedStyle(document.documentElement);
                        const root = doc.documentElement;

                        [
                            ['--primary-500', '--atriom-accent'],
                            ['--primary-600', '--atriom-accent-deep'],
                            ['--primary-400', '--atriom-accent-soft'],
                        ].forEach(([from, to]) => {
                            const value = panel.getPropertyValue(from).trim();
                            if (value) root.style.setProperty(to, `rgb(${value})`);
                        });
                    },
                };
            }
        </script>
    @endpush

    @push('styles')
        <style>
            /*
            | Fill the content area exactly once.
            |
            | 100dvh minus the panel chrome above it, so the handbook's own sidebar scrolls inside
            | the frame and the panel page itself never grows a second scrollbar. dvh rather than vh
            | because mobile browsers shrink the viewport as the URL bar retracts, and vh would
            | leave a strip of dead space under the frame at rest.
            */
            .atriom-handbook {
                position: relative;
                block-size: calc(100dvh - 13rem);
                min-block-size: 32rem;
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
            }

            /* On a phone the panel chrome takes more of the screen; give the frame the rest. */
            @media (max-width: 1024px) {
                .atriom-handbook {
                    block-size: calc(100dvh - 10rem);
                }
            }
        </style>
    @endpush
</x-filament-panels::page>
