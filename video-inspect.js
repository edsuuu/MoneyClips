#!/usr/bin/env node
/**
 * video-inspect.js
 *
 * Abre o seletor de arquivo do Windows, exibe os metadados do vídeo via ffprobe
 * e (opcionalmente) recodifica com qualidade de editor profissional.
 *
 * Encoder preferido: h264_nvenc (GPU NVIDIA CUDA) — 10-30× mais rápido.
 * Fallback automático: libx264 (CPU) se a GPU não estiver disponível.
 *
 * Qualidade equivalente a export do CapCut / Premiere Pro.
 *
 * Dependências: Node.js nativo + ffmpeg/ffprobe com suporte a NVENC no PATH.
 * Uso: node video-inspect.js
 */

'use strict';

const { execFile, spawn } = require('node:child_process');
const { promisify }       = require('node:util');
const { statSync, existsSync } = require('node:fs');
const { createInterface } = require('node:readline');
const { dirname, basename, extname, join } = require('node:path');

const execFileAsync = promisify(execFile);

// ─── Cores ANSI ──────────────────────────────────────────────────────────────
const C = {
    reset:  '\x1b[0m',
    bold:   '\x1b[1m',
    cyan:   '\x1b[36m',
    green:  '\x1b[32m',
    yellow: '\x1b[33m',
    red:    '\x1b[31m',
    gray:   '\x1b[90m',
    magenta:'\x1b[35m',
};
const b   = (s) => `${C.bold}${s}${C.reset}`;
const cy  = (s) => `${C.cyan}${s}${C.reset}`;
const gr  = (s) => `${C.green}${s}${C.reset}`;
const yl  = (s) => `${C.yellow}${s}${C.reset}`;
const re  = (s) => `${C.red}${s}${C.reset}`;
const dim = (s) => `${C.gray}${s}${C.reset}`;
const mg  = (s) => `${C.magenta}${s}${C.reset}`;

// ─── Helpers ─────────────────────────────────────────────────────────────────
const fmtBytes = (bytes) => {
    if (!bytes) return 'N/A';
    if (bytes < 1024 ** 2) return `${(bytes / 1024).toFixed(1)} KB`;
    return `${(bytes / 1024 ** 2).toFixed(2)} MB`;
};

const fmtBitrate = (bps) => {
    if (!bps) return 'N/A';
    if (bps < 1_000_000) return `${Math.round(bps / 1000)} kbps`;
    return `${(bps / 1_000_000).toFixed(2)} Mbps`;
};

const fmtDuration = (s) => {
    if (!s) return 'N/A';
    const sec = Math.round(s);
    const m   = Math.floor(sec / 60);
    return m > 0 ? `${m}m ${sec % 60}s` : `${sec}s`;
};

const fmtElapsed = (ms) => ms < 1000 ? `${ms}ms` : `${(ms / 1000).toFixed(1)}s`;

const evalFps = (str) => {
    if (!str || str === '0/0') return null;
    const [n, d] = str.split('/').map(Number);
    return d ? n / d : null;
};

// ─── Detectar suporte a NVENC ─────────────────────────────────────────────────
async function detectNvenc() {
    try {
        const { stdout } = await execFileAsync('ffmpeg', ['-hide_banner', '-encoders']);
        return stdout.includes('h264_nvenc');
    } catch {
        return false;
    }
}

// ─── Seletor de arquivo Windows (PowerShell) ──────────────────────────────────
async function pickFile() {
    const ps = [
        'Add-Type -AssemblyName System.Windows.Forms;',
        '$d = New-Object System.Windows.Forms.OpenFileDialog;',
        '$d.Title  = "Selecionar vídeo para inspecionar";',
        '$d.Filter = "Vídeos (*.mp4;*.mov;*.avi;*.mkv)|*.mp4;*.mov;*.avi;*.mkv|Todos (*.*)|*.*";',
        'if ($d.ShowDialog() -eq "OK") { $d.FileName } else { "" }',
    ].join(' ');

    const { stdout } = await execFileAsync('powershell.exe', ['-NoProfile', '-Command', ps]);
    return stdout.trim();
}

// ─── ffprobe ─────────────────────────────────────────────────────────────────
async function probe(filePath) {
    const { stdout } = await execFileAsync('ffprobe', [
        '-v', 'quiet',
        '-print_format', 'json',
        '-show_streams',
        '-show_format',
        filePath,
    ]);

    const data  = JSON.parse(stdout);
    const video = (data.streams || []).find(s => s.codec_type === 'video') || {};
    const audio = (data.streams || []).find(s => s.codec_type === 'audio') || {};
    const fmt   = data.format || {};

    return {
        width:        video.width            || null,
        height:       video.height           || null,
        codec:        video.codec_name       || 'N/A',
        profile:      video.profile          || 'N/A',
        fps:          evalFps(video.r_frame_rate),
        videoBitrate: parseInt(video.bit_rate || fmt.bit_rate || '0', 10),
        audioBitrate: parseInt(audio.bit_rate || '0', 10),
        audioCodec:   audio.codec_name       || 'N/A',
        duration:     parseFloat(fmt.duration || '0'),
        fileSize:     parseInt(fmt.size       || '0', 10),
        formatName:   fmt.format_long_name   || fmt.format_name || 'N/A',
    };
}

function printMetadata(label, meta, filePath) {
    const size       = existsSync(filePath) ? statSync(filePath).size : meta.fileSize;
    const bitrateCol = meta.videoBitrate > 8_000_000 ? gr
                     : meta.videoBitrate > 4_000_000 ? yl
                     : re;

    console.log();
    console.log(b(`  ┌─ ${label}`));
    console.log(`  │  ${dim('Arquivo')}     : ${cy(filePath)}`);
    console.log(`  │  ${dim('Formato')}     : ${meta.formatName}`);
    console.log(`  │  ${dim('Resolução')}   : ${b(`${meta.width ?? '?'}x${meta.height ?? '?'}`)}`);
    console.log(`  │  ${dim('Duração')}     : ${fmtDuration(meta.duration)}`);
    console.log(`  │  ${dim('FPS')}         : ${meta.fps ? meta.fps.toFixed(3) : 'N/A'}`);
    console.log(`  │  ${dim('Codec vídeo')} : ${meta.codec} (${meta.profile})`);
    console.log(`  │  ${dim('Bitrate vid')} : ${bitrateCol(fmtBitrate(meta.videoBitrate))}`);
    console.log(`  │  ${dim('Codec áudio')} : ${meta.audioCodec}`);
    console.log(`  │  ${dim('Bitrate áud')} : ${fmtBitrate(meta.audioBitrate)}`);
    console.log(`  │  ${dim('Tamanho')}     : ${fmtBytes(size)}`);
    console.log(`  └${'─'.repeat(52)}`);
}

// ─── Construir args ffmpeg dependendo do encoder disponível ───────────────────
//
// NVENC (GPU):
//   -preset p7        → qualidade máxima do encoder (p1=rápido, p7=melhor)
//   -tune hq          → modo high-quality
//   -rc vbr           → bitrate variável controlado por CQ
//   -cq 18            → qualidade constante (equivalente ao CRF do x264)
//   -b:v 0            → sem teto de bitrate — o encoder decide o necessário
//   -maxrate 80M      → trava o pico (evita picos absurdos em cenas complexas)
//   -bufsize 160M
//
// x264 (CPU — fallback):
//   -crf 18           → mesma escala de qualidade
//   -preset slow      → melhor compressão sem sacrificar qualidade
//
// Ambos: yuv420p + faststart + aac 192k = compatibilidade total com TikTok/YT.
//
function buildFfmpegArgs(inputPath, outputPath, useNvenc) {
    const common = [
        '-i', inputPath,
        '-pix_fmt',   'yuv420p',
        '-profile:v', 'high',
        '-level',     '4.1',
        '-c:a',       'aac',
        '-b:a',       '192k',
        '-ar',        '44100',
        '-movflags',  '+faststart',
        '-y',
        outputPath,
    ];

    if (useNvenc) {
        return [
            '-hwaccel', 'cuda',
            '-hwaccel_output_format', 'cuda',
            ...common.slice(0, 2), // -i inputPath
            '-c:v',     'h264_nvenc',
            '-preset',  'p7',
            '-tune',    'hq',
            '-rc',      'vbr',
            '-cq',      '18',
            '-b:v',     '0',
            '-maxrate', '80M',
            '-bufsize', '160M',
            ...common.slice(2),
        ];
    }

    return [
        ...common.slice(0, 2),
        '-c:v',    'libx264',
        '-crf',    '18',
        '-preset', 'slow',
        ...common.slice(2),
    ];
}

// ─── Reencode ─────────────────────────────────────────────────────────────────
async function reencode(inputPath, useNvenc) {
    const ext        = extname(inputPath);
    const base       = basename(inputPath, ext);
    const dir        = dirname(inputPath);
    const outputPath = join(dir, `${base}_HQ${ext}`);
    const encoderTag = useNvenc ? mg('NVENC GPU ⚡') : yl('x264 CPU');

    console.log();
    console.log(`  ⏳ Recodificando com ${b(encoderTag)} ${dim('(CQ/CRF 18, qualidade máxima)...')}`);
    console.log(dim(`     entrada : ${inputPath}`));
    console.log(dim(`     saída   : ${outputPath}`));
    console.log();

    const args = buildFfmpegArgs(inputPath, outputPath, useNvenc);
    const t0   = Date.now();

    await new Promise((resolve, reject) => {
        // stdio: stderr herdado → mostra progresso do ffmpeg em tempo real
        const proc = spawn('ffmpeg', args, { stdio: ['ignore', 'ignore', 'inherit'] });
        proc.on('close', code => code === 0 ? resolve() : reject(new Error(`ffmpeg encerrou com código ${code}`)));
        proc.on('error', reject);
    });

    console.log();
    console.log(gr(`  ✓ Concluído em ${fmtElapsed(Date.now() - t0)}`));

    return outputPath;
}

// ─── Prompt ───────────────────────────────────────────────────────────────────
function ask(q) {
    const rl = createInterface({ input: process.stdin, output: process.stdout });
    return new Promise(r => rl.question(q, a => { rl.close(); r(a.trim()); }));
}

// ─── Main ─────────────────────────────────────────────────────────────────────
async function main() {
    console.log();
    console.log(b(cy('  ╔══════════════════════════════════════╗')));
    console.log(b(cy('  ║    Video Inspect & Reencode  HQ      ║')));
    console.log(b(cy('  ╚══════════════════════════════════════╝')));
    console.log();

    // Detecta GPU antes de abrir o diálogo (rápido, não bloqueia UX)
    const [, useNvenc] = await Promise.all([
        (async () => { console.log(dim('  Detectando encoder de GPU...')); })(),
        detectNvenc(),
    ]);

    if (useNvenc) {
        console.log(gr('  ✓ NVENC detectado — encoding por GPU NVIDIA ativado.'));
    } else {
        console.log(yl('  ⚠ NVENC não disponível — usando libx264 (CPU) como fallback.'));
    }

    console.log();
    console.log(dim('  Abrindo seletor de arquivo...'));

    const inputPath = await pickFile();

    if (!inputPath) {
        console.log(yl('\n  Nenhum arquivo selecionado. Encerrando.\n'));
        return;
    }

    if (!existsSync(inputPath)) {
        console.error(re(`\n  ✗ Arquivo não encontrado: ${inputPath}\n`));
        process.exit(1);
    }

    // Metadados originais
    console.log(dim('\n  Lendo metadados via ffprobe...'));
    const before = await probe(inputPath);
    printMetadata('ORIGINAL', before, inputPath);

    // Confirmar reencode
    const encoder = useNvenc ? 'NVENC GPU (CQ 18)' : 'x264 CPU (CRF 18)';
    const resp    = await ask(`\n  Recodificar com ${b(encoder)}? [S/n] `);
    if (resp.toLowerCase() === 'n') {
        console.log(dim('\n  Cancelado.\n'));
        return;
    }

    // Reencode
    const outputPath = await reencode(inputPath, useNvenc);

    // Metadados do output
    console.log(dim('\n  Lendo metadados do arquivo recodificado...'));
    const after = await probe(outputPath);
    printMetadata('RECODIFICADO (HQ)', after, outputPath);

    // Comparativo
    const sizeOrig  = statSync(inputPath).size;
    const sizeFinal = statSync(outputPath).size;
    const sizeDiff  = ((sizeFinal - sizeOrig) / sizeOrig * 100).toFixed(1);
    const brDiff    = before.videoBitrate > 0
        ? ((after.videoBitrate - before.videoBitrate) / before.videoBitrate * 100).toFixed(1)
        : '?';

    console.log();
    console.log(b('  ┌─ COMPARATIVO'));
    console.log(`  │  Encoder        : ${useNvenc ? mg('h264_nvenc (GPU)') : yl('libx264 (CPU)')}`);
    console.log(`  │  Bitrate vídeo  : ${fmtBitrate(before.videoBitrate)} → ${gr(fmtBitrate(after.videoBitrate))}  (${brDiff > 0 ? '+' : ''}${brDiff}%)`);
    console.log(`  │  Tamanho        : ${fmtBytes(sizeOrig)} → ${fmtBytes(sizeFinal)}  (${sizeDiff > 0 ? '+' : ''}${sizeDiff}%)`);
    console.log(`  │  Output         : ${cy(outputPath)}`);
    console.log(`  └${'─'.repeat(52)}`);
    console.log();
}

main().catch(err => {
    console.error(re(`\n  ✗ Erro: ${err.message}\n`));
    process.exit(1);
});
