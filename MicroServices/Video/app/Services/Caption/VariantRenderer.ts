/**
 * Renderiza as variantes pedidas de um job. Cada uma monta seu próprio .ass
 * porque a geometria da legenda muda com o enquadramento — reaproveitar um
 * .ass entre variantes deixa a fonte no tamanho errado e o texto fora do lugar.
 */

import { join } from 'node:path';

import { settings } from '@/Config/Env';
import { Logger } from '@/Config/Logger';
import { CaptionOptionsData } from '@/Services/Caption/CaptionOptionsData';
import { Reframer } from '@/Services/Caption/Reframer';
import { StaticLayerRenderer } from '@/Services/Caption/StaticLayerRenderer';
import { SubtitleBuilder, type Transcript } from '@/Services/Caption/SubtitleBuilder';
import { SubtitleBurner } from '@/Services/Caption/SubtitleBurner';
import { TemplateComposer } from '@/Services/Caption/TemplateComposer';
import { VARIANT_FILENAMES, VideoStore } from '@/Services/Caption/VideoStore';

const BELOW_FONT_SCALE = 1.9;
const BELOW_GAP = 120;
const VERTICAL_FONT_SCALE = 1.5;
const VERTICAL_INSET = 90;
const TEMPLATE_FONT_SCALE = 2.6;

export interface RenderContext {
    uuid: string;
    source: string;
    sourceWidth: number;
    sourceHeight: number;
    durationSeconds: number;
    transcript: Transcript | null;
    options: CaptionOptionsData;
}

export class VariantRenderer extends Logger {
    public constructor(
        private readonly store: VideoStore = new VideoStore(),
        private readonly subtitles: SubtitleBuilder = new SubtitleBuilder(),
        private readonly burner: SubtitleBurner = new SubtitleBurner(),
        private readonly reframer: Reframer = new Reframer(),
        private readonly composer: TemplateComposer = new TemplateComposer(),
        private readonly layers: StaticLayerRenderer = new StaticLayerRenderer(),
    ) {
        super();
    }

    public async renderAll(context: RenderContext): Promise<Record<string, number>> {
        const timings: Record<string, number> = {};

        for (const variant of context.options.variants) {
            const startedAt = Date.now();
            await this.render(variant, context);
            timings[variant] = (Date.now() - startedAt) / 1000;
            this.info(
                `[Caption] [${context.uuid}] variante ${variant}: ${timings[variant].toFixed(1)}s`,
            );
        }

        return timings;
    }

    private async render(variant: string, context: RenderContext): Promise<void> {
        const filename = VARIANT_FILENAMES[variant];

        if (filename === undefined) {
            this.warn(`[Caption] Variante desconhecida ignorada: ${variant}`);

            return;
        }

        const dir = this.store.videoDir(context.uuid);
        const out = join(dir, filename);

        if (variant === 'original') {
            await this.renderOriginal(context, dir, out);

            return;
        }

        if (variant === 'vertical') {
            await this.renderVertical(context, dir, out);

            return;
        }

        await this.renderTemplate(
            context,
            dir,
            out,
            variant === 'template_black' ? 'black' : 'white',
        );
    }

    private async renderOriginal(context: RenderContext, dir: string, out: string): Promise<void> {
        let ass: string | null = null;

        if (context.transcript !== null) {
            ass = join(dir, 'subs_original.ass');
            await this.subtitles.build(context.transcript, join(dir, 'subs_original.srt'), ass, {
                width: context.sourceWidth,
                height: context.sourceHeight,
                offset: context.options.subtitleOffset,
            });
        }

        await this.burner.burn(context.source, ass, out);
    }

    private async renderVertical(context: RenderContext, dir: string, out: string): Promise<void> {
        let ass: string | null = null;

        if (context.transcript !== null) {
            ass = join(dir, 'subs_916.ass');

            // Onde termina a faixa do vídeo dentro do 1080x1920 — a legenda
            // fica logo abaixo dela, não sobre a imagem.
            const scale = Math.min(1080 / context.sourceWidth, 1920 / context.sourceHeight);
            const scaledHeight = Math.round(context.sourceHeight * scale);
            const bandBottom = Math.floor((1920 + scaledHeight) / 2);
            const marginV = Math.max(120, 1920 - bandBottom + VERTICAL_INSET);

            await this.subtitles.build(context.transcript, join(dir, 'subs_916.srt'), ass, {
                width: 1080,
                height: 1920,
                fontScale: VERTICAL_FONT_SCALE,
                marginVOverride: marginV,
                offset: context.options.subtitleOffset,
            });
        }

        await this.reframer.reframeAndBurn(context.source, ass, out);
    }

    private async renderTemplate(
        context: RenderContext,
        dir: string,
        out: string,
        background: string,
    ): Promise<void> {
        const region = this.composer.computeRegion(context.sourceWidth, context.sourceHeight);

        const logo = await this.layers.ensureLogo(
            settings.channelLogo,
            (context.options.channelName.trim().charAt(0) || 'U').toUpperCase(),
        );

        const layerPng = join(dir, `layer_${background}.png`);
        await this.layers.renderLayer(
            background,
            layerPng,
            logo,
            context.options.channelName,
            context.options.channelHandle,
        );

        let ass: string | null = null;

        if (context.transcript !== null) {
            ass = join(dir, `subs_tpl_${background}.ass`);
            const srt = join(dir, `subs_tpl_${background}.srt`);

            if (context.options.captionPosition === 'below') {
                await this.subtitles.build(context.transcript, srt, ass, {
                    width: 1080,
                    height: 1920,
                    fontScale: BELOW_FONT_SCALE,
                    alignment: 8,
                    marginVOverride: region.y + region.h + BELOW_GAP,
                    offset: context.options.subtitleOffset,
                });
            } else {
                await this.subtitles.build(context.transcript, srt, ass, {
                    width: region.w,
                    height: region.h,
                    fontScale: TEMPLATE_FONT_SCALE,
                    offset: context.options.subtitleOffset,
                });
            }
        }

        await this.composer.compose(
            context.source,
            ass,
            layerPng,
            region,
            out,
            context.options.captionPosition,
            context.options.watermarkText,
            context.durationSeconds,
        );
    }
}
