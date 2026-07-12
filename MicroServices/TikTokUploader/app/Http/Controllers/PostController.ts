import type { Request, Response } from 'express';
import { rm } from 'node:fs/promises';

import { TikTokContentRestrictionError } from '@/Exceptions/TikTokContentRestrictionError';
import { ValidationError } from '@/Exceptions/ValidationError';
import { discord } from '@/Services/Notifications/Discord';
import { TikTokUploader } from '@/Services/TikTok/TikTokUploader';
import type { Cookie, VideoMetadata } from '@/Types/DomainType';

import type { CreatePostRequest } from '../Requests/CreatePostRequest';

export class PostController {
    public constructor(private readonly uploader: TikTokUploader) {}

    public async createPost(req: Request, res: Response): Promise<void> {
        const body = (req.body ?? {}) as CreatePostRequest;
        const videoPath = req.file?.path ?? '';

        const title = typeof body.title === 'string' ? body.title.trim() : '';
        const cookies = parseCookies(body.cookies);

        const errors: Record<string, string> = {};

        if (!videoPath) {
            errors['video'] =
                'Arquivo de vídeo obrigatório. Envie como multipart/form-data no campo "video" — este endpoint não aceita application/json.';
        }

        if (cookies.length === 0) {
            errors['cookies'] = 'Cookies obrigatórios: array JSON de cookies no campo "cookies".';
        }

        if (Object.keys(errors).length > 0) {
            throw new ValidationError(errors);
        }

        const metadata: VideoMetadata = { title, hashtags: parseHashtags(body.hashtags) };

        try {
            const result = await this.uploader.upload({ videoPath, metadata, cookies });

            void discord.notifySuccess(
                '✅ Post no TikTok',
                `\`${title || '(sem título)'}\` — status: ${result}`,
            );

            res.status(200).json({ status: result, title });
        } catch (error) {
            if (error instanceof TikTokContentRestrictionError) {
                res.status(200).json({ status: 'restricted', title, detail: error.message });

                return;
            }

            throw error;
        } finally {
            await rm(videoPath, { force: true }).catch(() => undefined);
        }
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
