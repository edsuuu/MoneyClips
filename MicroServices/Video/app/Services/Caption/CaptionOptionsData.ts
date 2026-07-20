import { settings } from '@/Config/Env';

export const ALL_VARIANTS = ['original', 'vertical', 'template_white', 'template_black'];

export class CaptionOptionsData {
    public readonly variants: string[];
    public readonly captionPosition: 'below' | 'inside';
    public readonly channelName: string;
    public readonly channelHandle: string;
    public readonly subtitleOffset: number | null;
    public readonly withCaptions: boolean;
    public readonly watermarkText: string;

    public constructor(raw: Partial<CaptionOptionsData> = {}) {
        const requested = raw.variants?.filter((variant) => ALL_VARIANTS.includes(variant)) ?? [];

        this.variants = requested.length > 0 ? requested : CaptionOptionsData.defaultVariants();
        this.captionPosition = raw.captionPosition === 'inside' ? 'inside' : 'below';
        this.channelName =
            raw.channelName !== undefined && raw.channelName !== ''
                ? raw.channelName
                : settings.channelName;
        this.channelHandle =
            raw.channelHandle !== undefined && raw.channelHandle !== ''
                ? raw.channelHandle
                : settings.channelHandle;
        this.subtitleOffset = raw.subtitleOffset ?? null;
        this.withCaptions = raw.withCaptions ?? true;
        this.watermarkText = raw.watermarkText ?? settings.watermarkText;
    }

    private static defaultVariants(): string[] {
        return settings.outputVariants
            .split(',')
            .map((variant) => variant.trim())
            .filter((variant) => variant !== '');
    }
}
