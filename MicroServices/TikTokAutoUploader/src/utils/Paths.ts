import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

// <root>/src/utils/paths.ts -> sobe 2 níveis até a raiz do projeto.
const here = dirname(fileURLToPath(import.meta.url));

export const PROJECT_ROOT = join(here, '..', '..');

/** Cookies de sessão do TikTok, um arquivo JSON por conta (cookies/{conta}.json). */
export const COOKIES_DIR = join(PROJECT_ROOT, 'cookies');
