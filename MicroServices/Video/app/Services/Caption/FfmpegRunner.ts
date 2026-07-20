/**
 * Execução de ffmpeg das variantes, com o fallback NVENC → libx264 que as três
 * rotas de render compartilham.
 *
 * IMPORTANTE: roda com `cwd` no diretório do job e passa NOMES de arquivo, não
 * caminhos. O filtro `ass=` do libass trata `:` e `\` como metacaracteres de
 * sintaxe, então caminho absoluto quebra o parse do filtergraph.
 */

import { spawn } from 'node:child_process';

import { Logger } from '@/Config/Logger';
import { EncoderArgs } from '@/Services/Caption/EncoderArgs';

interface RunResult {
    code: number;
    stderr: string;
}

export class FfmpegRunner extends Logger {
    /**
     * Tenta com o encoder preferido e, se ele era GPU e falhou, repete em CPU.
     * `buildArgs` recebe os args de encode a usar.
     */
    public async runWithFallback(
        buildArgs: (encoderArgs: string[]) => string[],
        cwd: string,
        label: string,
    ): Promise<void> {
        const first = await this.run(buildArgs(EncoderArgs.preferred()), cwd);

        if (first.code === 0) {
            return;
        }

        if (!EncoderArgs.prefersGpu()) {
            throw new Error(`ffmpeg falhou em ${label}: ${first.stderr.slice(-2000)}`);
        }

        this.warn(
            `[Caption] NVENC falhou em ${label}, tentando libx264. ${first.stderr.slice(-1000)}`,
        );

        const second = await this.run(buildArgs(EncoderArgs.cpu()), cwd);

        if (second.code !== 0) {
            throw new Error(`ffmpeg falhou em ${label}: ${second.stderr.slice(-2000)}`);
        }
    }

    public run(args: string[], cwd: string): Promise<RunResult> {
        return new Promise((resolve, reject) => {
            const proc = spawn('ffmpeg', args, { cwd, stdio: ['ignore', 'ignore', 'pipe'] });
            let stderr = '';

            proc.stderr.on('data', (chunk: Buffer) => {
                stderr += chunk.toString();
                // ffmpeg é verborrágico: guarda só a cauda, que é onde o erro sai.
                if (stderr.length > 8000) {
                    stderr = stderr.slice(-8000);
                }
            });

            proc.on('close', (code) => {
                resolve({ code: code ?? 1, stderr });
            });
            proc.on('error', reject);
        });
    }
}
