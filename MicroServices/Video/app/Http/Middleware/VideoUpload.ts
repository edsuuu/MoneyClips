import type { RequestHandler } from 'express';
import multer from 'multer';
import { randomUUID } from 'node:crypto';
import { mkdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { extname, join } from 'node:path';

export class VideoUpload {
    private static readonly UPLOAD_DIR = join(tmpdir(), 'video-uploads');
    private static readonly MAX_UPLOAD_BYTES = 1024 ** 3;

    public readonly handle: RequestHandler;

    public constructor(field: string) {
        mkdirSync(VideoUpload.UPLOAD_DIR, { recursive: true });

        this.handle = multer({
            storage: multer.diskStorage({
                destination: (_req, _file, cb) => cb(null, VideoUpload.UPLOAD_DIR),
                filename: (_req, file, cb) =>
                    cb(null, `${randomUUID()}${extname(file.originalname) || '.mp4'}`),
            }),
            limits: { fileSize: VideoUpload.MAX_UPLOAD_BYTES },
        }).single(field);
    }
}

/** POST /reencode manda o binário no campo `video`; POST /videos, em `file`. */
export const videoUpload = new VideoUpload('video');
export const captionUpload = new VideoUpload('file');
