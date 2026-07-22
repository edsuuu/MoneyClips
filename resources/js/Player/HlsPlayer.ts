type HlsConstructor = typeof import('hls.js').default;

export type HlsInstance = InstanceType<HlsConstructor>;

export class HlsPlayer {
    private static modulePromise: Promise<HlsConstructor> | null = null;

    public static async library(): Promise<HlsConstructor> {
        HlsPlayer.modulePromise ??= import('hls.js').then((module) => module.default);

        return HlsPlayer.modulePromise;
    }

    public static async attach(element: HTMLVideoElement): Promise<HlsInstance | null> {
        const hlsSrc = element.dataset.hlsSrc;
        const fallbackSrc = element.dataset.fallbackSrc;

        if (!hlsSrc) {
            if (fallbackSrc && !element.getAttribute('src')) {
                element.src = fallbackSrc;
            }

            return null;
        }

        try {
            const Hls = await HlsPlayer.library();

            // hls.js (MSE) primeiro: Chrome/Firefox/Edge devolvem "maybe" no
            // canPlayType de HLS mas NÃO tocam nativo — só o Safari toca. Checar
            // canPlayType antes usaria src nativo e travaria fora do Safari.
            if (Hls.isSupported()) {
                const hls = new Hls({
                    maxBufferLength: 20,
                    maxMaxBufferLength: 30,
                    maxBufferSize: 24 * 1000 * 1000,
                    backBufferLength: 30,
                    startFragPrefetch: false,
                });

                hls.loadSource(hlsSrc);
                hls.attachMedia(element);

                return hls;
            }
        } catch (error) {
            console.warn('Falha ao carregar hls.js, tentando player nativo.', error);
        }

        if (element.canPlayType('application/vnd.apple.mpegurl')) {
            element.src = hlsSrc;

            return null;
        }

        if (fallbackSrc) {
            element.src = fallbackSrc;
        }

        return null;
    }
}
