let hlsModulePromise = null;

async function loadHlsConstructor() {
    if (window.Hls) {
        return window.Hls;
    }

    // Chunk separado: os ~400KB do hls.js só entram nas telas que têm player.
    if (!hlsModulePromise) {
        hlsModulePromise = import('hls.js').then((module) => module.default);
    }

    return hlsModulePromise;
}

export async function initAdaptiveVideoPlayer(element) {
    const hlsSrc = element.dataset.hlsSrc;
    const fallbackSrc = element.dataset.fallbackSrc;

    if (!hlsSrc) {
        if (fallbackSrc && !element.getAttribute('src')) {
            element.src = fallbackSrc;
        }

        return;
    }

    // Safari toca HLS nativo — carregar o hls.js ali só desperdiça banda.
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
}

window.initAdaptiveVideoPlayer = initAdaptiveVideoPlayer;
