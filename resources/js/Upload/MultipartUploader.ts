import { ClientLogger } from '../Support/ClientLogger';
import { JsonClient } from '../Support/JsonClient';
import { PartSender } from './PartSender';
import { ResumeStore } from './ResumeStore';
import { UploadProgress } from './UploadProgress';
import type { CompletedUpload, SignedParts, StoredParts, UploaderCallbacks, UploadSession } from './UploadTypes';

export class MultipartUploader {
    static readonly SIGN_WINDOW = 20;

    static readonly CONCURRENCY = 4;

    private file!: File;

    private store!: ResumeStore;

    private session!: UploadSession;

    private progress!: UploadProgress;

    constructor(private readonly callbacks: UploaderCallbacks) {}

    async upload(file: File): Promise<CompletedUpload> {
        this.file = file;
        this.store = new ResumeStore(file);

        const stored = this.store.read();

        this.session = stored ?? {
            ...(await JsonClient.post<UploadSession>('/uploads', {
                file_size: file.size,
                mime_type: file.type,
            })),
            completed: {},
        };

        if (stored === null) {
            this.store.write(this.session);
        } else if (!(await this.syncWithServer())) {
            this.store.forget();

            return this.upload(file);
        }

        this.progress = new UploadProgress(file.size, this.session.part_size, this.callbacks.onProgress);
        this.progress.seed(Object.keys(this.session.completed).map(Number));

        const pending: number[] = [];
        for (let number = 1; number <= this.session.part_count; number += 1) {
            if (!this.session.completed[number]) {
                pending.push(number);
            }
        }

        this.callbacks.onStatus('uploading');
        this.progress.emit();

        for (let offset = 0; offset < pending.length; offset += MultipartUploader.SIGN_WINDOW) {
            await this.uploadWindow(pending.slice(offset, offset + MultipartUploader.SIGN_WINDOW));
        }

        this.callbacks.onStatus('finishing');
        this.callbacks.onProgress(100);

        const result = await JsonClient.post<CompletedUpload>(`/uploads/${this.session.video_uuid}/complete`, {
            parts: Object.entries(this.session.completed).map(([number, etag]) => ({
                part_number: Number(number),
                etag,
            })),
        });

        this.store.forget();
        this.callbacks.onStatus('done');

        return result;
    }

    private async syncWithServer(): Promise<boolean> {
        try {
            const { parts } = await JsonClient.get<StoredParts>(`/uploads/${this.session.video_uuid}/parts`);

            for (const part of parts) {
                this.session.completed[part.part_number] = part.etag;
            }

            return true;
        } catch {
            return false;
        }
    }

    private async uploadWindow(window: number[]): Promise<void> {
        const { urls } = await JsonClient.post<SignedParts>(`/uploads/${this.session.video_uuid}/parts`, {
            part_numbers: window,
        });

        let cursor = 0;

        await Promise.all(
            Array.from({ length: Math.min(MultipartUploader.CONCURRENCY, window.length) }, async () => {
                while (cursor < window.length) {
                    const number = window[cursor];
                    cursor += 1;

                    if (number !== undefined) {
                        await this.sendPart(number, urls[number] ?? '');
                    }
                }
            }),
        );
    }

    private async sendPart(number: number, url: string): Promise<void> {
        const start = (number - 1) * this.session.part_size;
        const blob = this.file.slice(start, Math.min(start + this.session.part_size, this.file.size));

        let lastError: unknown = null;

        for (let attempt = 1; attempt <= PartSender.MAX_ATTEMPTS; attempt += 1) {
            try {
                const etag = await PartSender.put(url, blob, (loaded) => this.progress.track(number, loaded));

                this.session.completed[number] = etag;
                this.progress.settle(number, blob.size);
                this.store.write(this.session);

                return;
            } catch (error) {
                lastError = error;
                this.progress.drop(number);
                ClientLogger.send('warning', `Parte ${number} falhou (tentativa ${attempt}): ${(error as Error).message}`, {
                    video_uuid: this.session.video_uuid,
                    part_number: number,
                });
                await new Promise((resolve) => setTimeout(resolve, 500 * attempt));
            }
        }

        throw lastError;
    }
}
