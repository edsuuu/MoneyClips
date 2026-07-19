/**
 * O arquivo não é um vídeo legível — veredito sobre o conteúdo, não falha do
 * serviço. O Laravel usa essa distinção para marcar `rejected` (terminal, sem
 * retry) em vez de `failed`.
 */
export class NotAVideoError extends Error {
    public constructor(message: string) {
        super(message);
        this.name = 'NotAVideoError';
    }
}
