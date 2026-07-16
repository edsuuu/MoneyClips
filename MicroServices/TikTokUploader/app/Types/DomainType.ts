export interface Cookie {
    name: string;
    value: string;
    domain: string;
    path: string;
    expires?: number;
    httpOnly?: boolean;
    secure?: boolean;
    sameSite?: 'Strict' | 'Lax' | 'None';
}

export interface VideoMetadata {
    title: string;
    hashtags: string[];
}

export type UploadResult = 'completed' | 'dry-run';

export interface UploadOutcome {
    status: UploadResult;
    /** Cookies capturados pós-upload — renovam a sessão no banco do Laravel. */
    refreshedCookies: Cookie[];
}

export interface BoundingBox {
    x: number;
    y: number;
    width: number;
    height: number;
}

export interface Point {
    x: number;
    y: number;
}
