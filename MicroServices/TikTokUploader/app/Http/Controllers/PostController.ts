import type { Request, Response } from 'express';
import { rm } from 'node:fs/promises';

import { ValidationError } from '@/Exceptions/ValidationError';
import { PostQueueService } from '@/Services/PostQueueService';
import type { Cookie, VideoMetadata } from '@/Types/DomainType';

import type { CreatePostRequest } from '../Requests/CreatePostRequest';

export class PostController {
    public constructor(private readonly queue: PostQueueService) {}

    /**
     * ASSÍNCRONO: valida, enfileira e responde 202 {job_id} na hora. O upload
     * roda em background (PostQueueService) e o desfecho vai pro Laravel na
     * webhook_url: {job_id, status: completed|dry-run|restricted|failed,
     * session_status, refreshed_cookies?}.
     */
    public async createPost(req: Request, res: Response): Promise<void> {
        const body = (req.body ?? {}) as CreatePostRequest;
        const videoPath = req.file?.path ?? '';

        const title = typeof body.title === 'string' ? body.title.trim() : '';
        const cookies = parseCookies(body.cookies);
        const webhookUrl = typeof body.webhook_url === 'string' ? body.webhook_url.trim() : '';

        const errors: Record<string, string> = {};

        if (!videoPath) {
            errors['video'] =
                'Arquivo de vídeo obrigatório. Envie como multipart/form-data no campo "video" — este endpoint não aceita application/json.';
        }

        if (cookies.length === 0) {
            errors['cookies'] = 'Cookies obrigatórios: array JSON de cookies no campo "cookies".';
        }

        if (!/^https?:\/\//.test(webhookUrl)) {
            errors['webhook_url'] =
                'webhook_url obrigatória (http/https): o desfecho do post é assíncrono e chega por webhook.';
        }

        if (Object.keys(errors).length > 0) {
            // Request recusado não processa nada — não deixa o vídeo órfão no tmp.
            await rm(videoPath, { force: true }).catch(() => undefined);

            throw new ValidationError(errors);
        }

        const metadata: VideoMetadata = { title, hashtags: parseHashtags(body.hashtags) };
        const accountId =
            typeof body.account_id === 'string' && body.account_id.trim() !== ''
                ? body.account_id.trim()
                : undefined;

        const jobId = this.queue.enqueue({
            videoPath,
            metadata,
            cookies,
            webhookUrl,
            ...(accountId !== undefined ? { accountId } : {}),
        });

        res.status(202).json({ job_id: jobId, status: 'queued', title });
    }
}

// Aceita array (body JSON) ou string JSON (campo de multipart).
function asArray(raw: unknown): unknown[] {
    if (Array.isArray(raw)) {
        return raw;
    }

    if (typeof raw !== 'string' || raw.trim() === '') {
        return [];
    }

    try {
        const parsed = JSON.parse(raw) as unknown;

        return Array.isArray(parsed) ? parsed : [];
    } catch {
        return [];
    }
}

function parseCookies(raw: unknown): Cookie[] {
    return asArray(raw) as Cookie[];
}

function parseHashtags(raw: unknown): string[] {
    // String simples "a,b,c" vira lista por vírgula; array ou JSON "[...]" passa pelo asArray.
    const list =
        typeof raw === 'string' && !raw.trim().startsWith('[')
            ? raw.split(',')
            : asArray(raw).filter((tag): tag is string => typeof tag === 'string');

    return list
        .map((tag) => tag.trim())
        .filter((tag) => tag !== '')
        .map((tag) => (tag.startsWith('#') ? tag : `#${tag}`));
}
