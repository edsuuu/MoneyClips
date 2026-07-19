import { reframeEditor } from './reframe-editor';

// Registrado antes do Alpine embutido do Livewire subir (app.js carrega no
// <head>; @livewireScripts só no fim do <body>).
document.addEventListener('alpine:init', () => {
    window.Alpine.data('reframeEditor', reframeEditor);

    // O <head> já aplicou a classe pré-paint; aqui só espelhamos o estado.
    window.Alpine.store('theme', {
        dark: document.documentElement.classList.contains('dark'),
        toggle() {
            const root = document.documentElement;

            root.classList.add('theme-switching');
            this.dark = !this.dark;
            localStorage.theme = this.dark ? 'dark' : 'light';
            root.classList.toggle('dark', this.dark);

            // Reflow síncrono: aplica as cores novas ainda com transition:none.
            // (rAF não serve — não dispara em aba de fundo e a classe ficaria presa.)
            void root.offsetHeight;
            root.classList.remove('theme-switching');
        },
    });
});

let hlsModulePromise = null;

async function loadHlsConstructor() {
    if (window.Hls) {
        return window.Hls;
    }

    if (!hlsModulePromise) {
        hlsModulePromise = import('https://cdn.jsdelivr.net/npm/hls.js@1.5.17/+esm')
            .then((module) => module.default);
    }

    return hlsModulePromise;
}

window.initAdaptiveVideoPlayer = async (element) => {
    const hlsSrc = element.dataset.hlsSrc;
    const fallbackSrc = element.dataset.fallbackSrc;

    if (!hlsSrc) {
        if (fallbackSrc && !element.getAttribute('src')) {
            element.src = fallbackSrc;
        }

        return;
    }

    if (element.canPlayType('application/vnd.apple.mpegurl')) {
        element.src = hlsSrc;
        return;
    }

    try {
        const Hls = await loadHlsConstructor();
        if (Hls?.isSupported()) {
            const hls = new Hls({
                maxBufferLength: 20,
                maxMaxBufferLength: 30,
                maxBufferSize: 24 * 1000 * 1000,
                backBufferLength: 30,
                startFragPrefetch: false,
            });

            hls.loadSource(hlsSrc);
            hls.attachMedia(element);
            element._hls = hls;
            return;
        }
    } catch (error) {
        console.warn('Falha ao carregar hls.js, usando fallback MP4.', error);
    }

    if (fallbackSrc) {
        element.src = fallbackSrc;
    }
};
