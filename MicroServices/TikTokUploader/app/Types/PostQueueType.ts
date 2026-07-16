import type { Cookie, VideoMetadata } from './DomainType';

export interface PostJob {
    jobId: string;
    videoPath: string;
    metadata: VideoMetadata;
    cookies: Cookie[];
    webhookUrl: string;
}

export type PostOutcome = 'completed' | 'dry-run' | 'restricted' | 'failed';

export type SessionStatus = 'valid' | 'invalid' | 'unknown';

/** Payload do webhook de conclusão enviado ao Laravel (snake_case no fio). */
export interface PostWebhookPayload {
    job_id: string;
    status: PostOutcome;
    title: string;
    detail?: string;
    error?: string;
    session_status: SessionStatus;
    refreshed_cookies?: Cookie[];
}
