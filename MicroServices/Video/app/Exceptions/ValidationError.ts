export class ValidationError extends Error {
    public constructor(public readonly details: unknown) {
        super('Payload inválido.');
        this.name = 'ValidationError';
    }
}
