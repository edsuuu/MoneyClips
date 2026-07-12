export class LoginFailedError extends Error {
    public constructor(accountName: string, reason: string) {
        super(`Login automático falhou para '${accountName}': ${reason}`);
        this.name = 'LoginFailedError';
    }
}
