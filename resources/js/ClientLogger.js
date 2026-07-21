export class ClientLogger {
    static ENDPOINT = '/client-logs';

    static install() {
        window.addEventListener('error', (event) => {
            ClientLogger.send('error', event.message, {
                file: event.filename,
                line: event.lineno,
                column: event.colno,
                stack: event.error?.stack?.slice(0, 2000),
            });
        });

        window.addEventListener('unhandledrejection', (event) => {
            const reason = event.reason;

            ClientLogger.send('error', reason?.message ?? String(reason), {
                stack: reason?.stack?.slice(0, 2000),
            });
        });
    }

    static requestId() {
        return crypto.randomUUID();
    }

    static send(level, message, context = {}, requestId = null) {
        const body = JSON.stringify({
            level,
            message: String(message ?? '').slice(0, 2000),
            request_id: requestId,
            url: window.location.href,
            context,
        });

        fetch(ClientLogger.ENDPOINT, {
            method: 'POST',
            keepalive: true,
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
            },
            body,
        }).catch(() => {});
    }
}
