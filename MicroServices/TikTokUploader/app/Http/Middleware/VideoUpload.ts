import multer from 'multer';
import { randomUUID } from 'node:crypto';
import { mkdirSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { extname, join } from 'node:path';

const UPLOAD_DIR = join(tmpdir(), 'tiktok-uploads');

mkdirSync(UPLOAD_DIR, { recursive: true });

const storage = multer.diskStorage({
    destination: (_req, _file, cb) => cb(null, UPLOAD_DIR),
    filename: (_req, file, cb) =>
        cb(null, `${randomUUID()}${extname(file.originalname) || '.mp4'}`),
});

export const videoUpload = multer({ storage }).single('video');
