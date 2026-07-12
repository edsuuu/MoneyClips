import type { Browser, BrowserContext } from 'playwright';

import type { Cookie, VideoMetadata } from './DomainType';

export interface UploadRequest {
    videoPath: string;
    metadata: VideoMetadata;
    cookies: Cookie[];
}

export interface BrowserSession {
    browser: Browser;
    context: BrowserContext;
}
