import { ClientLogger } from './ClientLogger';

export class JsonClient {
    public static async get<T>(url: string): Promise<T> {
        return JsonClient.send<T>(url, null);
    }

    public static async post<T>(url: string, body: unknown): Promise<T> {
        return JsonClient.send<T>(url, body);
    }

    private static async send<T>(url: string, body: unknown): Promise<T> {
        const requestId = ClientLogger.requestId();
        const isRead = body === null;

        const response = await fetch(url, {
            method: isRead ? 'GET' : 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Request-Id': requestId,
                ...(isRead
                    ? {}
                    : {
                          'Content-Type': 'application/json',
                          'X-CSRF-TOKEN': ClientLogger.csrfToken(),
                      }),
            },
            ...(isRead ? {} : { body: JSON.stringify(body) }),
        });

        const payload = (await response.json().catch(() => ({}))) as T & { message?: string };

        if (!response.ok) {
            const message = payload.message ?? `Falha na requisição (${response.status}).`;

            ClientLogger.send(
                'error',
                `${url} respondeu ${response.status}: ${message}`,
                {
                    status: response.status,
                },
                requestId,
            );

            throw new Error(message);
        }

        return payload;
    }
}
