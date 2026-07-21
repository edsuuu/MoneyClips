import { ClientLogger } from './ClientLogger';

export class MultipartUploader {
    static SIGN_WINDOW = 20;

    static CONCURRENCY = 4;

    static MAX_PART_ATTEMPTS = 3;

    static STORAGE_PREFIX = 'moneyclips.upload.';

    constructor({ onProgress, onStatus }) {
        this.onProgress = onProgress;
        this.onStatus = onStatus;
        this.uploadedBytes = new Map();
    }

    async upload(file) {
        this.file = file;
        this.storageKey = `${MultipartUploader.STORAGE_PREFIX}${file.name}:${file.size}:${file.lastModified}`;

        let stored = null;
        try {
            const raw = window.localStorage.getItem(this.storageKey);
            stored = raw ? JSON.parse(raw) : null;
        } catch {
            stored = null;
        }

        let session = stored;

        if (!session) {
            session = await this.request('/uploads', {
                file_size: file.size,
                mime_type: file.type,
            });
            session.completed = {};
            this.persist(session);
        }

        this.session = session;
        this.partSize = session.part_size;
        this.completed = session.completed ?? {};

        if (stored) {
            try {
                const { parts } = await this.request(`/uploads/${session.video_uuid}/parts`);
                for (const part of parts) {
                    this.completed[part.part_number] = part.etag;
                }
            } catch {
                this.forget();

                return this.upload(file);
            }
        }

        this.uploadedBytes = new Map(
            Object.keys(this.completed).map((number) => [Number(number), this.partBytes(Number(number))]),
        );

        const pending = [];
        for (let number = 1; number <= session.part_count; number += 1) {
            if (!this.completed[number]) {
                pending.push(number);
            }
        }

        this.onStatus('uploading');
        this.reportProgress();

        for (let offset = 0; offset < pending.length; offset += MultipartUploader.SIGN_WINDOW) {
            const window = pending.slice(offset, offset + MultipartUploader.SIGN_WINDOW);
            const { urls } = await this.request(`/uploads/${session.video_uuid}/parts`, {
                part_numbers: window,
            });

            let cursor = 0;
            await Promise.all(
                Array.from({ length: Math.min(MultipartUploader.CONCURRENCY, window.length) }, async () => {
                    while (cursor < window.length) {
                        const number = window[cursor];
                        cursor += 1;

                        await this.sendPart(number, urls[number]);
                    }
                }),
            );
        }

        this.onStatus('finishing');
        this.onProgress(100);

        const result = await this.request(`/uploads/${session.video_uuid}/complete`, {
            parts: Object.entries(this.completed).map(([number, etag]) => ({
                part_number: Number(number),
                etag,
            })),
        });

        this.forget();
        this.onStatus('done');

        return result;
    }

    async sendPart(number, url) {
        const start = (number - 1) * this.partSize;
        const blob = this.file.slice(start, Math.min(start + this.partSize, this.file.size));

        let lastError = null;

        for (let attempt = 1; attempt <= MultipartUploader.MAX_PART_ATTEMPTS; attempt += 1) {
            try {
                const etag = await new Promise((resolve, reject) => {
                    const xhr = new XMLHttpRequest();
                    xhr.open('PUT', url, true);

                    xhr.upload.onprogress = (event) => {
                        if (event.lengthComputable) {
                            this.uploadedBytes.set(number, event.loaded);
                            this.reportProgress();
                        }
                    };

                    xhr.onload = () => {
                        if (xhr.status < 200 || xhr.status >= 300) {
                            reject(new Error(`O storage recusou a parte (${xhr.status}).`));

                            return;
                        }

                        const header = xhr.getResponseHeader('ETag');

                        return header
                            ? resolve(header)
                            : reject(new Error('O MinIO não devolveu o ETag da parte (CORS).'));
                    };

                    xhr.onerror = () => reject(new Error('Falha de rede ao enviar a parte.'));
                    xhr.onabort = () => reject(new Error('Envio da parte cancelado.'));

                    xhr.send(blob);
                });

                this.completed[number] = etag;
                this.uploadedBytes.set(number, blob.size);
                this.session.completed = this.completed;
                this.persist(this.session);

                return;
            } catch (error) {
                lastError = error;
                this.uploadedBytes.delete(number);
                ClientLogger.send('warning', `Parte ${number} falhou (tentativa ${attempt}): ${error.message}`, {
                    video_uuid: this.session.video_uuid,
                    part_number: number,
                });
                await new Promise((resolve) => setTimeout(resolve, 500 * attempt));
            }
        }

        throw lastError;
    }

    async request(url, body = null) {
        const requestId = ClientLogger.requestId();

        const response = await fetch(url, {
            method: body === null ? 'GET' : 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Request-Id': requestId,
                ...(body === null
                    ? {}
                    : {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
                    }),
            },
            ...(body === null ? {} : { body: JSON.stringify(body) }),
        });

        const payload = await response.json().catch(() => ({}));

        if (!response.ok) {
            const message = payload.message ?? `Falha na requisição (${response.status}).`;

            ClientLogger.send('error', `${url} respondeu ${response.status}: ${message}`, {
                status: response.status,
            }, requestId);

            throw new Error(message);
        }

        return payload;
    }

    partBytes(number) {
        return Math.min(number * this.partSize, this.file.size) - (number - 1) * this.partSize;
    }

    reportProgress() {
        let total = 0;

        for (const bytes of this.uploadedBytes.values()) {
            total += bytes;
        }

        this.onProgress(Math.min(99, Math.round((total / this.file.size) * 100)));
    }

    persist(session) {
        try {
            window.localStorage.setItem(this.storageKey, JSON.stringify(session));
        } catch {
        }
    }

    forget() {
        try {
            window.localStorage.removeItem(this.storageKey);
        } catch {
        }
    }
}
