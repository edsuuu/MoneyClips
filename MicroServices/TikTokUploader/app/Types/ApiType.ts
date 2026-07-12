import type { Cookie } from './DomainType';

export interface SessionView {
    account: string;
    has_cookies: boolean;
    expired: boolean;
    valid: boolean;
}

export interface LoginView {
    account: string;
    cookies: Cookie[];
    expired: boolean;
    valid: boolean;
}
