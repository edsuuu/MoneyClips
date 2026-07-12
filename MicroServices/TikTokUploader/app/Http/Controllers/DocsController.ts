import type { Request, Response } from 'express';
import { readFile } from 'node:fs/promises';
import { join } from 'node:path';

// A view é asset estático: resolvida pela raiz do projeto (cwd), então funciona
// tanto rodando via tsx quanto pelo bundle em dist/.
const DOCS_VIEW = join(process.cwd(), 'app', 'Views', 'docs.html');

export class DocsController {
    private cached: string | null = null;

    public async index(_req: Request, res: Response): Promise<void> {
        this.cached ??= await readFile(DOCS_VIEW, 'utf-8');

        res.set('Cache-Control', 'no-store').type('html').send(this.cached);
    }
}
