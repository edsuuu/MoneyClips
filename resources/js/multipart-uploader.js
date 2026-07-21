const SIGN_WINDOW = 20;
const CONCURRENCY = 4;
const MAX_PART_ATTEMPTS = 3;
const STORAGE_PREFIX = 'moneyclips.upload.';

function resumeKey(file) {
    return `${STORAGE_PREFIX}${file.name}:${file.size}:${file.lastModified}`;
}

function readResume(file) {
    try {
        const raw = window.localStorage.getItem(resumeKey(file));
        return raw ? JSON.parse(raw) : null;
    } catch {
        return null;
    }
}

function writeResume(file, session) {
    try {
        window.localStorage.setItem(resumeKey(file), JSON.stringify(session));
    } catch {
        // localStorage cheio ou indisponível: o upload segue, só perde a retomada.
    }
}

function clearResume(file) {
    try {
        window.localStorage.removeItem(resumeKey(file));
    } catch {
        // idem
    }
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
}

async function postJson(url, body, method = 'POST') {
    const response = await fetch(url, {
        method,
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        credentials: 'same-origin',
        body: body === undefined ? undefined : JSON.stringify(body),
    });

    const payload = await response.json().catch(() => ({}));

    if (!response.ok) {
        throw new Error(payload.message ?? `Falha na requisição (${response.status}).`);
    }

    return payload;
}

async function getJson(url) {
    const response = await fetch(url, {
        headers: { Accept: 'application/json' },
        credentials: 'same-origin',
    });

    if (!response.ok) {
        throw new Error(`Falha na requisição (${response.status}).`);
    }

    return response.json();
}

/**
 * Sobe uma parte com XHR (e não fetch) porque só o XHR reporta progresso de
 * upload — é o que alimenta a barra durante um envio de vários GB.
 */
function putPart(url, blob, onProgress) {
    return new Promise((resolve, reject) => {
        const xhr = new XMLHttpRequest();
        xhr.open('PUT', url, true);

        xhr.upload.onprogress = (event) => {
            if (event.lengthComputable) {
                onProgress(event.loaded);
            }
        };

        xhr.onload = () => {
            if (xhr.status >= 200 && xhr.status < 300) {
                const etag = xhr.getResponseHeader('ETag');
                if (!etag) {
                    reject(new Error('O MinIO não devolveu o ETag da parte (CORS).'));
                    return;
                }
                resolve(etag);
                return;
            }
            reject(new Error(`O storage recusou a parte (${xhr.status}).`));
        };

        xhr.onerror = () => reject(new Error('Falha de rede ao enviar a parte.'));
        xhr.onabort = () => reject(new Error('Envio da parte cancelado.'));

        xhr.send(blob);
    });
}

export function createMultipartUploader({ onProgress, onStatus }) {
    return async function upload(file) {
        const stored = readResume(file);
        let session = stored;

        if (!session) {
            session = await postJson('/uploads', {
                file_size: file.size,
                mime_type: file.type,
            });
            session.completed = {};
            writeResume(file, session);
        }

        const partSize = session.part_size;
        const partCount = session.part_count;
        const completed = session.completed ?? {};

        // Retomada: o servidor é a fonte da verdade sobre o que já subiu — o
        // localStorage pode estar adiantado se a aba morreu no meio de um PUT.
        if (stored) {
            try {
                const { parts } = await getJson(`/uploads/${session.video_uuid}/parts`);
                for (const part of parts) {
                    completed[part.part_number] = part.etag;
                }
            } catch {
                // Sessão morta no MinIO: recomeça do zero.
                clearResume(file);
                return upload(file);
            }
        }

        const pending = [];
        for (let partNumber = 1; partNumber <= partCount; partNumber += 1) {
            if (!completed[partNumber]) {
                pending.push(partNumber);
            }
        }

        const partBytes = (partNumber) =>
            Math.min(partNumber * partSize, file.size) - (partNumber - 1) * partSize;

        // Uma entrada por parte, viva do primeiro byte até o ETag: parte concluída
        // vira o tamanho final em vez de sair do mapa, senão a barra anda e volta.
        const uploadedBytes = new Map(
            Object.keys(completed).map((partNumber) => [Number(partNumber), partBytes(Number(partNumber))]),
        );

        const reportProgress = () => {
            let total = 0;
            for (const bytes of uploadedBytes.values()) {
                total += bytes;
            }
            onProgress(Math.min(99, Math.round((total / file.size) * 100)));
        };

        onStatus('uploading');
        reportProgress();

        for (let offset = 0; offset < pending.length; offset += SIGN_WINDOW) {
            const window = pending.slice(offset, offset + SIGN_WINDOW);
            const { urls } = await postJson(`/uploads/${session.video_uuid}/parts`, {
                part_numbers: window,
            });

            let cursor = 0;
            const workers = Array.from({ length: Math.min(CONCURRENCY, window.length) }, async () => {
                while (cursor < window.length) {
                    const partNumber = window[cursor];
                    cursor += 1;

                    const start = (partNumber - 1) * partSize;
                    const blob = file.slice(start, Math.min(start + partSize, file.size));

                    let lastError = null;
                    for (let attempt = 1; attempt <= MAX_PART_ATTEMPTS; attempt += 1) {
                        try {
                            const etag = await putPart(urls[partNumber], blob, (loaded) => {
                                uploadedBytes.set(partNumber, loaded);
                                reportProgress();
                            });

                            completed[partNumber] = etag;
                            uploadedBytes.set(partNumber, blob.size);
                            session.completed = completed;
                            writeResume(file, session);
                            lastError = null;
                            break;
                        } catch (error) {
                            lastError = error;
                            uploadedBytes.delete(partNumber);
                            await new Promise((r) => setTimeout(r, 500 * attempt));
                        }
                    }

                    if (lastError) {
                        throw lastError;
                    }
                }
            });

            await Promise.all(workers);
        }

        onStatus('finishing');
        onProgress(100);

        const parts = Object.entries(completed).map(([partNumber, etag]) => ({
            part_number: Number(partNumber),
            etag,
        }));

        const result = await postJson(`/uploads/${session.video_uuid}/complete`, { parts });

        clearResume(file);
        onStatus('done');

        return result;
    };
}

window.createMultipartUploader = createMultipartUploader;
