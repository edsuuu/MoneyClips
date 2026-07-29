/**
 * Storage local dos jobs de legenda: `<CAPTION_STORAGE_DIR>/<uuid>/` com o
 * source, o áudio, o transcript e as variantes renderizadas. Nada disso vai
 * pro S3 — o Laravel envia o binário e baixa o output (regra da casa).
 */

import { existsSync } from 'node:fs';
import { mkdir, readdir, readFile, writeFile } from 'node:fs/promises';
import { isAbsolute, join, resolve } from 'node:path';

import { settings } from '@/Config/Env';

export const VARIANT_FILENAMES: Record<string, string> = {
    original: 'original.mp4',
    vertical: 'vertical_916.mp4',
    template_white: 'template_white.mp4',
    template_black: 'template_black.mp4',
};

const SOURCE_EXTENSIONS = ['.mp4', '.mov', '.mkv', '.webm', '.avi', '.m4v'];

export interface JobStatus {
    uuid: string;
    status: 'processing' | 'done' | 'failed';
    step: string;
    updated_at: string;
    error?: string | null;
    files?: Record<string, boolean>;
    variant_seconds?: Record<string, number>;
    webhook_url?: string;
    options?: Record<string, unknown>;
}

export class VideoStore {
    private readonly root: string;

    public constructor(root: string = settings.captionStorageDir) {
        this.root = isAbsolute(root) ? root : resolve(process.cwd(), root);
    }

    public videoDir(uuid: string): string {
        return join(this.root, uuid);
    }

    public async ensureDir(uuid: string): Promise<string> {
        const dir = this.videoDir(uuid);
        await mkdir(dir, { recursive: true });

        return dir;
    }

    public sourcePath(uuid: string, extension: string): string {
        return join(this.videoDir(uuid), `source${extension}`);
    }

    public async findSource(uuid: string): Promise<string | null> {
        const dir = this.videoDir(uuid);

        if (!existsSync(dir)) {
            return null;
        }

        const entries = await readdir(dir);
        const match = entries.find((entry) =>
            SOURCE_EXTENSIONS.some((ext) => entry === `source${ext}`),
        );

        return match === undefined ? null : join(dir, match);
    }

    public audioPath(uuid: string): string {
        return join(this.videoDir(uuid), 'audio.wav');
    }

    public transcriptPath(uuid: string): string {
        return join(this.videoDir(uuid), 'transcript.json');
    }

    public outputPath(uuid: string, variant: string): string | null {
        const filename = VARIANT_FILENAMES[variant];

        return filename === undefined ? null : join(this.videoDir(uuid), filename);
    }

    public statusPath(uuid: string): string {
        return join(this.videoDir(uuid), 'status.json');
    }

    public async readStatus(uuid: string): Promise<JobStatus | null> {
        if (!existsSync(this.statusPath(uuid))) {
            return null;
        }

        try {
            return JSON.parse(await readFile(this.statusPath(uuid), 'utf8')) as JobStatus;
        } catch (error) {
            console.warn(`[WARN] status.json ilegível para ${uuid}`, error);

            return null;
        }
    }

    /**
     * Merge com o status atual — o `webhook_url` é gravado no POST /videos e
     * lido lá na frente pra notificar o desfecho; sobrescrever o arquivo inteiro
     * a cada passo apagaria ele e o webhook nunca sairia.
     */
    public async writeStatus(uuid: string, patch: Partial<JobStatus>): Promise<void> {
        const current = (await this.readStatus(uuid)) ?? {};
        await this.ensureDir(uuid);
        await writeFile(
            this.statusPath(uuid),
            JSON.stringify({ ...current, ...patch }, null, 2),
            'utf8',
        );
    }

    public async listVideos(): Promise<string[]> {
        if (!existsSync(this.root)) {
            return [];
        }

        const entries = await readdir(this.root, { withFileTypes: true });

        return entries.filter((entry) => entry.isDirectory()).map((entry) => entry.name);
    }

    public files(uuid: string): Record<string, boolean> {
        const dir = this.videoDir(uuid);
        const files: Record<string, boolean> = {
            'audio.wav': existsSync(this.audioPath(uuid)),
            'transcript.json': existsSync(this.transcriptPath(uuid)),
        };

        for (const filename of Object.values(VARIANT_FILENAMES)) {
            files[filename] = existsSync(join(dir, filename));
        }

        return files;
    }
}
