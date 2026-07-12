/**
 * Avisos no Discord — função pura de envio (sem classe). Usada nos catches para
 * reportar qualquer erro do reencode. Sem DISCORD_WEBHOOK_URL, vira no-op.
 */

import { settings } from '@/config/env/Env';
import { logger } from '@/config/logger/Logger';

function toMessage(error: unknown): string {
    return error instanceof Error ? error.message : String(error);
}

/** Envia uma mensagem de texto simples. */
export async function sendDiscordMessage(content: string): Promise<void> {
    if (!settings.discordWebhookUrl) {
        return;
    }
    try {
        const resp = await fetch(settings.discordWebhookUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ content }),
        });
        if (!resp.ok) {
            logger.warn(`Discord respondeu ${resp.status}.`);
        }
    } catch (error) {
        logger.warn(`Falha ao enviar ao Discord: ${toMessage(error)}`);
    }
}

/** Reporta um erro ao Discord como texto. */
export async function sendDiscordError(context: string, error: unknown): Promise<void> {
    await sendDiscordMessage(`❌ Erro em ${context} (reencode): ${toMessage(error)}`);
}
