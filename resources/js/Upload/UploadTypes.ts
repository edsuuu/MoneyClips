export type UploadState = 'idle' | 'uploading' | 'finishing' | 'done';

export interface UploadSession {
    video_uuid: string;
    upload_id: string;
    part_size: number;
    part_count: number;
    completed: Record<number, string>;
}

export interface SignedParts {
    urls: Record<number, string>;
}

export interface StoredParts {
    parts: { part_number: number; etag: string }[];
}

export interface CompletedUpload {
    status: string;
    video_uuid: string;
}

export interface UploaderCallbacks {
    onProgress: (percent: number) => void;
    onStatus: (state: UploadState) => void;
}

export interface VideoUploaderConfig {
    maxBytes: number;
    accepted: string;
}
