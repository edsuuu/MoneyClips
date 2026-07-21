interface HlsConstructor {
    new (config: Record<string, unknown>): {
        loadSource: (url: string) => void;
        attachMedia: (element: HTMLMediaElement) => void;
    };
    isSupported: () => boolean;
}

export class HlsPlayer {
    private static modulePromise: Promise<HlsConstructor> | null = null;

    public static async library(): Promise<HlsConstructor> {
        HlsPlayer.modulePromise ??= import('hls.js').then(
            (module) => module.default as unknown as HlsConstructor,
        );

        return HlsPlayer.modulePromise;
    }

    public static async attach(element: HTMLVideoElement): Promise<void> {
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
            const Hls = await HlsPlayer.library();

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

                return;
            }
        } catch (error) {
            console.warn('Falha ao carregar hls.js, usando fallback MP4.', error);
        }

        if (fallbackSrc) {
            element.src = fallbackSrc;
        }
    }
}
