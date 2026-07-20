/**
 * Avisos no Discord. Usado nos catches para reportar erro interno de rota.
 * Sem DISCORD_WEBHOOK_URL, vira no-op.
 */

import { settings } from '@/Config/Env';
import { Logger } from '@/Config/Logger';

export class DiscordService extends Logger {
    public async sendMessage(content: string): Promise<void> {
        if (settings.discordWebhookUrl === '') {
            return;
        }

        try {
            const response = await fetch(settings.discordWebhookUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ content }),
            });

            if (!response.ok) {
                this.warn(`Discord respondeu ${String(response.status)}.`);
            }
        } catch (error) {
            this.warn(`Falha ao enviar ao Discord: ${this.toMessage(error)}`);
        }
    }

    public async sendError(context: string, error: unknown): Promise<void> {
        await this.sendMessage(`❌ Erro em ${context} (video): ${this.toMessage(error)}`);
    }

    private toMessage(error: unknown): string {
        return error instanceof Error ? error.message : String(error);
    }
}

export const discord = new DiscordService();
