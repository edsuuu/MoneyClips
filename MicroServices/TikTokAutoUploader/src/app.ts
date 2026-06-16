/**
 * Camada de aplicação: as regras do serviço, independentes de HTTP. Recebe a
 * entrada já desserializada, valida, monta o vídeo e enfileira; também expõe a
 * checagem de sessão e o estado para o health. O transporte (src/server) só
 * traduz HTTP <-> estes métodos.
 */

import { randomUUID } from 'node:crypto';
import { z } from 'zod';

import { checkSession, normalizeCookies, saveCookies } from '@/auth/Cookies';
import { TikTokAuth } from '@/auth/TikTokAuth';
import { settings } from '@/config/env/Env';
import { logger } from '@/config/logger/Logger';
import type { SessionView } from '@/types/ApiType';
import type { Cookie, VideoMetadata } from '@/types/DomainType';

import { UploadQueue } from './UploadQueue';
import { UploadWorkflow } from './UploadWorkflow';

const CreatePostSchema = z.object({
    video_id: z.string().min(1),
    // Chave exata do objeto no storage. Sem ela, usa o layout plano shorts/{id}.mp4.
    video_key: z.string().min(1).optional(),
    webhook_url: z.string().url(),
    title: z.string().min(1),
    hashtags: z.array(z.string()).optional(),
});

/** POST /login — login explícito do TikTok, orquestrado pelo Laravel. */
const LoginSchema = z.object({
    // Apaga os cookies atuais antes de logar (re-login limpo).
    force: z.boolean().optional(),
    // Sobrescreve o modo do navegador (default: HEADLESS do .env).
    headless: z.boolean().optional(),
});

/** Cookie do Playwright — espelha o tipo Cookie em DomainType. */
const CookieSchema = z.object({
    name: z.string(),
    value: z.string(),
    domain: z.string(),
    path: z.string(),
    expires: z.number().optional(),
    httpOnly: z.boolean().optional(),
    secure: z.boolean().optional(),
    sameSite: z.enum(['Strict', 'Lax', 'None']).optional(),
});

/** POST /session — injeta uma sessão gerada fora do serviço (cookies exportados). */
const InjectSessionSchema = z.object({
    cookies: z.array(CookieSchema).min(1),
});

/** Payload da API rejeitado pela validação — o servidor traduz para HTTP 422. */
export class ValidationError extends Error {
    public constructor(public readonly details: unknown) {
        super('Payload inválido.');
        this.name = 'ValidationError';
    }
}

export interface Health {
    status: 'ok';
    queue_size: number;
    dry_run: boolean;
}

export class App {
    private readonly queue: UploadQueue;

    public constructor(queue: UploadQueue = new UploadQueue(new UploadWorkflow())) {
        this.queue = queue;
    }

    public health(): Health {
        return { status: 'ok', queue_size: this.queue.size, dry_run: settings.dryRun };
    }

    public session(): Promise<SessionView> {
        return checkSession(settings.tiktokAccountName);
    }

    /**
     * Login explícito da conta padrão (TIKTOK_ACCOUNT_NAME). Abre o navegador,
     * loga por email/senha (resolvendo captcha) e grava os cookies. Devolve a
     * sessão resultante. Lança LoginFailedError se o login não concluir.
     */
    public async login(input: unknown): Promise<SessionView> {
        const parsed = LoginSchema.safeParse(input);
        if (!parsed.success) {
            throw new ValidationError(parsed.error.flatten());
        }

        const account = settings.tiktokAccountName;
        logger.info(`POST /login — iniciando login para '${account}'.`);
        await new TikTokAuth().login(account, parsed.data);
        return checkSession(account);
    }

    /**
     * Injeta uma sessão obtida fora do serviço (cookies exportados de um login
     * local). Persiste para a conta padrão e devolve a sessão resultante — assim
     * o login pode ser feito/gerido pelo Laravel e empurrado para cá.
     */
    public async injectSession(input: unknown): Promise<SessionView> {
        const parsed = InjectSessionSchema.safeParse(input);
        if (!parsed.success) {
            throw new ValidationError(parsed.error.flatten());
        }

        const account = settings.tiktokAccountName;
        await saveCookies(account, normalizeCookies(parsed.data.cookies as Cookie[]));
        logger.info(`POST /session — cookies injetados para '${account}'.`);
        return checkSession(account);
    }

    /** Valida o payload, enfileira o post e devolve o id do job. */
    public createPost(input: unknown): { job_id: string; status: 'queued' } {
        const parsed = CreatePostSchema.safeParse(input);
        if (!parsed.success) {
            throw new ValidationError(parsed.error.flatten());
        }

        const metadata: VideoMetadata = {
            title: parsed.data.title,
            hashtags: normalizeHashtags(parsed.data.hashtags ?? []),
            soundName: null,
            soundVolume: 'mix',
        };

        const jobId = randomUUID();
        this.queue.enqueue({
            jobId,
            videoId: parsed.data.video_id,
            videoKey: parsed.data.video_key ?? null,
            webhookUrl: parsed.data.webhook_url,
            metadata,
        });
        return { job_id: jobId, status: 'queued' };
    }
}

/** Garante que cada hashtag começa com '#' e descarta vazias. */
function normalizeHashtags(raw: string[]): string[] {
    return raw
        .map((tag) => tag.trim())
        .filter((tag) => tag !== '')
        .map((tag) => (tag.startsWith('#') ? tag : `#${tag}`));
}
