export type ClientLogLevel = 'warning' | 'error';

export type ClientLogContext = Record<string, unknown>;

export class ClientLogger {
    public static readonly ENDPOINT = '/client-logs';

    public static install(): void {
        window.addEventListener('error', (event: ErrorEvent) => {
            ClientLogger.send('error', event.message, {
                file: event.filename,
                line: event.lineno,
                column: event.colno,
                stack: event.error?.stack?.slice(0, 2000),
            });
        });

        window.addEventListener('unhandledrejection', (event: PromiseRejectionEvent) => {
            const reason: unknown = event.reason;

            ClientLogger.send('error', ClientLogger.describe(reason), {
                stack: reason instanceof Error ? reason.stack?.slice(0, 2000) : undefined,
            });
        });
    }

    public static requestId(): string {
        return crypto.randomUUID();
    }

    public static csrfToken(): string {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
    }

    public static send(
        level: ClientLogLevel,
        message: string,
        context: ClientLogContext = {},
        requestId: string | null = null,
    ): void {
        void fetch(ClientLogger.ENDPOINT, {
            method: 'POST',
            keepalive: true,
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': ClientLogger.csrfToken(),
            },
            body: JSON.stringify({
                level,
                message: message.slice(0, 2000),
                request_id: requestId,
                url: window.location.href,
                context,
            }),
        }).catch(() => undefined);
    }

    private static describe(reason: unknown): string {
        if (reason instanceof Error) {
            return `${reason.name}: ${reason.message}`;
        }

        if (typeof reason === 'string') {
            return reason;
        }

        try {
            return `${Object.prototype.toString.call(reason)} ${JSON.stringify(reason)}`;
        } catch {
            return String(reason);
        }
    }
}
