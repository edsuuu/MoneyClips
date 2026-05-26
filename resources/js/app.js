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
                maxBufferLength: 30,
                backBufferLength: 90,
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
