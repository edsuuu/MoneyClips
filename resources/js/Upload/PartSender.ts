export class PartSender {
    static readonly MAX_ATTEMPTS = 3;

    static put(url: string, blob: Blob, onProgress: (loaded: number) => void): Promise<string> {
        return new Promise<string>((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            xhr.open('PUT', url, true);

            xhr.upload.onprogress = (event: ProgressEvent) => {
                if (event.lengthComputable) {
                    onProgress(event.loaded);
                }
            };

            xhr.onload = () => {
                if (xhr.status < 200 || xhr.status >= 300) {
                    reject(new Error(`O storage recusou a parte (${xhr.status}).`));

                    return;
                }

                const etag = xhr.getResponseHeader('ETag');

                if (etag === null) {
                    reject(new Error('O MinIO não devolveu o ETag da parte (CORS).'));

                    return;
                }

                resolve(etag);
            };

            xhr.onerror = () => reject(new Error('Falha de rede ao enviar a parte.'));
            xhr.onabort = () => reject(new Error('Envio da parte cancelado.'));

            xhr.send(blob);
        });
    }
}
