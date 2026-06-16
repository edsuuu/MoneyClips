/**
 * Avisos no Discord — funções puras de envio (sem classe). Usado nos catches
 * para reportar qualquer erro; quando há gravação do Playwright, o vídeo vai
 * junto como anexo. Sem DISCORD_WEBHOOK_URL, vira no-op.
 */

import { readFile } from 'node:fs/promises';
import { basename } from 'node:path';

import { settings } from '@/config/env/Env';
import { logger } from '@/config/logger/Logger';

// Marca erros já reportados ao Discord para não duplicar quando a exceção sobe
// de uma camada que já avisou (ex.: sessão gravada) até a fila.
const reported = new WeakSet<object>();

export function markDiscordReported(error: unknown): void {
    if (typeof error === 'object' && error !== null) {
        reported.add(error);
    }
}

export function wasDiscordReported(error: unknown): boolean {
    return typeof error === 'object' && error !== null && reported.has(error);
}

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

/**
 * Reporta um erro ao Discord. Se `videoPath` existir, sobe o vídeo da sessão
 * como anexo (com fallback para texto se o arquivo for grande demais). Marca o
 * erro como reportado para a fila não duplicar o aviso.
 */
export async function sendDiscordError(
    context: string,
    error: unknown,
    videoPath?: string | null,
): Promise<void> {
    markDiscordReported(error);
    const content = `❌ Erro em ${context} (conta ${settings.tiktokAccountName}): ${toMessage(error)}`;

    if (!settings.discordWebhookUrl) {
        return;
    }
    if (!videoPath) {
        await sendDiscordMessage(content);
        return;
    }

    try {
        const buffer = await readFile(videoPath);
        const form = new FormData();
        form.append('payload_json', JSON.stringify({ content }));
        form.append('files[0]', new Blob([buffer], { type: 'video/webm' }), basename(videoPath));

        const resp = await fetch(settings.discordWebhookUrl, { method: 'POST', body: form });
        if (!resp.ok) {
            // Vídeo provavelmente acima do limite do Discord — manda só o texto.
            logger.warn(`Discord recusou o vídeo (${resp.status}); enviando só o texto.`);
            await sendDiscordMessage(content);
        }
    } catch (sendError) {
        logger.warn(`Falha ao enviar vídeo ao Discord: ${toMessage(sendError)}`);
        await sendDiscordMessage(content);
    }
}
