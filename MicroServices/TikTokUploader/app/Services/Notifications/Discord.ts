import { readFile } from 'node:fs/promises';
import { basename } from 'node:path';

import { settings } from '@/Config/Env';
import { logger } from '@/Config/Logger';

interface DiscordEmbed {
    title: string;
    description: string;
    color: number;
    timestamp: string;
    fields?: { name: string; value: string }[];
}

const ERROR_COLOR = 0xed4245;
const INFO_COLOR = 0x5865f2;
const SUCCESS_COLOR = 0x57f287;

const MAX_DESCRIPTION = 4000;
const MAX_STACK = 1000;

// Códigos ANSI de cor (ex.: \x1b[2m) que o Playwright injeta nas mensagens de erro.
const ANSI = new RegExp(String.fromCharCode(27) + '\\[[0-9;]*m', 'g');

export class DiscordNotifier {
    private readonly reported = new WeakSet<object>();

    public constructor(
        private readonly webhookUrl: string = settings.discordWebhookUrl,
        private readonly userId: string = settings.discordUserNotifier,
    ) {}

    public async notifyError(
        context: string,
        error: unknown,
        videoPath?: string | null,
    ): Promise<void> {
        if (this.alreadyReported(error)) {
            return;
        }

        await this.send(this.errorEmbed(context, error), videoPath ?? null, this.mention());
    }

    private mention(): string {
        return this.userId ? `<@${this.userId}>` : '';
    }

    public async notify(title: string, description: string): Promise<void> {
        await this.sendPlain(title, description, INFO_COLOR);
    }

    public async notifySuccess(title: string, description: string): Promise<void> {
        await this.sendPlain(title, description, SUCCESS_COLOR);
    }

    private async sendPlain(title: string, description: string, color: number): Promise<void> {
        const embed: DiscordEmbed = {
            title,
            description: this.trim(description, MAX_DESCRIPTION),
            color,
            timestamp: new Date().toISOString(),
        };

        await this.send(embed, null, '');
    }

    private alreadyReported(error: unknown): boolean {
        if (typeof error !== 'object' || error === null) {
            return false;
        }

        if (this.reported.has(error)) {
            return true;
        }

        this.reported.add(error);

        return false;
    }

    private errorEmbed(context: string, error: unknown): DiscordEmbed {
        const raw = error instanceof Error ? error.message : String(error);
        // Playwright embute o "Call log" inteiro na mensagem — fica só a 1ª linha.
        const message = this.clean(raw).split('\nCall log:')[0] ?? raw;
        const stack = error instanceof Error && error.stack ? this.clean(error.stack) : null;

        return {
            title: `❌ Erro em ${context}`,
            description: this.trim(message, MAX_DESCRIPTION),
            color: ERROR_COLOR,
            timestamp: new Date().toISOString(),
            ...(stack ? { fields: [{ name: 'Stack', value: this.codeBlock(stack) }] } : {}),
        };
    }

    private clean(text: string): string {
        // Remove códigos ANSI de cor que o Playwright injeta no texto do erro.
        return text.replace(ANSI, '');
    }

    private async send(
        embed: DiscordEmbed,
        videoPath: string | null,
        content: string,
    ): Promise<void> {
        if (!this.webhookUrl) {
            return;
        }

        try {
            if (videoPath && (await this.sendWithVideo(embed, videoPath, content))) {
                return;
            }

            await this.sendJson(embed, content);
        } catch (error) {
            logger.warn(
                `Falha ao enviar ao Discord: ${error instanceof Error ? error.message : String(error)}`,
            );
        }
    }

    private payload(embed: DiscordEmbed, content: string): string {
        if (content) {
            return JSON.stringify({
                content,
                embeds: [embed],
                allowed_mentions: { users: [this.userId] },
            });
        }

        return JSON.stringify({ embeds: [embed] });
    }

    private async sendWithVideo(
        embed: DiscordEmbed,
        videoPath: string,
        content: string,
    ): Promise<boolean> {
        const buffer = await readFile(videoPath);

        const form = new FormData();
        form.append('payload_json', this.payload(embed, content));
        form.append('files[0]', new Blob([buffer], { type: 'video/webm' }), basename(videoPath));

        const resp = await fetch(this.webhookUrl, { method: 'POST', body: form });

        if (!resp.ok) {
            logger.warn(`Discord recusou o vídeo (${resp.status}); enviando só o embed.`);

            return false;
        }

        return true;
    }

    private async sendJson(embed: DiscordEmbed, content: string): Promise<void> {
        const resp = await fetch(this.webhookUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: this.payload(embed, content),
        });

        if (!resp.ok) {
            logger.warn(`Discord respondeu ${resp.status}.`);
        }
    }

    private trim(text: string, max: number): string {
        return text.length > max ? `${text.slice(0, max - 1)}…` : text;
    }

    private codeBlock(text: string): string {
        const body = text.length > MAX_STACK ? text.slice(0, MAX_STACK) : text;

        return `\`\`\`\n${body}\n\`\`\``;
    }
}

export const discord = new DiscordNotifier();
