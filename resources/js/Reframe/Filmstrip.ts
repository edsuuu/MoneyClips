/**
 * Gera as miniaturas da timeline a partir do próprio vídeo, no navegador:
 * um <video> clone (não interfere na reprodução do editor) faz seeks
 * sequenciais e cada frame vira um JPEG 9:16 via canvas.
 */
export class Filmstrip {
    public static readonly THUMB_WIDTH = 36;

    public static readonly THUMB_HEIGHT = 64;

    public static async generate(
        src: string,
        count: number,
        onThumb: (index: number, url: string) => void,
        canProceed: () => boolean = () => true,
    ): Promise<void> {
        const video = document.createElement('video');
        video.crossOrigin = 'anonymous';
        video.muted = true;
        video.preload = 'auto';
        video.src = src;

        await new Promise<void>((resolve, reject) => {
            video.addEventListener('loadedmetadata', () => resolve(), { once: true });
            video.addEventListener(
                'error',
                () => reject(new Error('Filmstrip: vídeo não carregou.')),
                { once: true },
            );
        });

        const canvas = document.createElement('canvas');
        canvas.width = Filmstrip.THUMB_WIDTH * 2;
        canvas.height = Filmstrip.THUMB_HEIGHT * 2;
        const context = canvas.getContext('2d');
        if (!context) return;

        for (let index = 0; index < count; index += 1) {
            // Um só decoder de vídeo ativo por vez: enquanto o player toca, o
            // clone espera. Senão os dois disputam o decoder e o player trava.
            while (!canProceed()) {
                await new Promise<void>((resolve) => setTimeout(resolve, 200));
            }

            await new Promise<void>((resolve) => {
                video.addEventListener('seeked', () => resolve(), { once: true });
                video.currentTime = ((index + 0.5) / count) * video.duration;
            });

            const scale = Math.max(
                canvas.width / video.videoWidth,
                canvas.height / video.videoHeight,
            );
            const drawWidth = video.videoWidth * scale;
            const drawHeight = video.videoHeight * scale;
            context.drawImage(
                video,
                (canvas.width - drawWidth) / 2,
                (canvas.height - drawHeight) / 2,
                drawWidth,
                drawHeight,
            );

            onThumb(index, canvas.toDataURL('image/jpeg', 0.6));
        }

        video.removeAttribute('src');
        video.load();
    }
}
