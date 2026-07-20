import { settings } from '@/Config/Env';

/** Argumentos de encode das variantes do template (qualidade máxima: CQ/CRF 16). */
export class EncoderArgs {
    private static readonly NVENC = [
        '-c:v',
        'h264_nvenc',
        '-preset',
        'p7',
        '-tune',
        'hq',
        '-rc',
        'vbr',
        '-cq',
        '16',
        '-b:v',
        '0',
        '-bf',
        '3',
        '-pix_fmt',
        'yuv420p',
    ];

    private static readonly LIBX264 = [
        '-c:v',
        'libx264',
        '-preset',
        'slower',
        '-crf',
        '16',
        '-pix_fmt',
        'yuv420p',
    ];

    public static preferred(): string[] {
        return settings.encoder === 'gpu' ? [...EncoderArgs.NVENC] : [...EncoderArgs.LIBX264];
    }

    public static cpu(): string[] {
        return [...EncoderArgs.LIBX264];
    }

    public static prefersGpu(): boolean {
        return settings.encoder === 'gpu';
    }
}
