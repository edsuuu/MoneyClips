import type { VideoMeta } from '@/Services/Video/Probe';

export interface Rendition {
    name: string;
    height: number;
    videoBitrateKbps: number;
    audioBitrateKbps: number;
}

/**
 * Monta o ladder ABR a partir da fonte. Regra central: nunca fazer upscale —
 * encodar 1080p a partir de uma fonte 720p gasta CPU para piorar a imagem.
 */
export class LadderBuilder {
    private static readonly LADDER: readonly Rendition[] = [
        { name: '360p', height: 360, videoBitrateKbps: 800, audioBitrateKbps: 96 },
        { name: '720p', height: 720, videoBitrateKbps: 2800, audioBitrateKbps: 128 },
        { name: '1080p', height: 1080, videoBitrateKbps: 5000, audioBitrateKbps: 192 },
    ];

    public build(meta: VideoMeta): Rendition[] {
        const fitting = LadderBuilder.LADDER.filter((step) => step.height <= meta.height);

        const base = LadderBuilder.LADDER[0] as Rendition;
        const steps = fitting.length > 0 ? fitting : [{ ...base, height: meta.height }];

        const ceiling = meta.videoBitrateKbps > 0 ? meta.videoBitrateKbps : Number.MAX_SAFE_INTEGER;

        return steps.map((step) => ({
            ...step,
            videoBitrateKbps: Math.min(step.videoBitrateKbps, ceiling),
        }));
    }

    public canRemux(meta: VideoMeta, ladder: Rendition[]): boolean {
        if (ladder.length !== 1) {
            return false;
        }

        const videoOk = meta.videoCodec === 'h264';
        const audioOk = !meta.hasAudio || meta.audioCodec === 'aac';

        return videoOk && audioOk && ladder[0]?.height === meta.height;
    }
}
