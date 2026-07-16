import multer from 'multer';
import { randomUUID } from 'node:crypto';
import { mkdirSync, readdirSync, rmSync, statSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { extname, join } from 'node:path';

const UPLOAD_DIR = join(tmpdir(), 'tiktok-uploads');

// Jobs perdidos num restart deixam o binário órfão aqui (a fila é em
// memória) — varredura no boot evita o tmp crescer pra sempre.
const STALE_UPLOAD_MS = 24 * 60 * 60 * 1000;

mkdirSync(UPLOAD_DIR, { recursive: true });

for (const name of readdirSync(UPLOAD_DIR)) {
    const path = join(UPLOAD_DIR, name);

    try {
        if (Date.now() - statSync(path).mtimeMs > STALE_UPLOAD_MS) {
            rmSync(path, { force: true });
        }
    } catch {
        // arquivo sumiu no meio da varredura — ignora
    }
}

const storage = multer.diskStorage({
    destination: (_req, _file, cb) => cb(null, UPLOAD_DIR),
    filename: (_req, file, cb) =>
        cb(null, `${randomUUID()}${extname(file.originalname) || '.mp4'}`),
});

export const videoUpload = multer({ storage }).single('video');
