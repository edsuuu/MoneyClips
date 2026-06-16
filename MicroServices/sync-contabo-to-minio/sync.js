// Sincroniza objetos de um bucket S3 da Contabo (origem) para um MinIO local (destino).
//
// Lê TODAS as credenciais do .env desta mesma pasta:
//   - origem  (Contabo): STORAGE_*
//   - destino (MinIO):   MINIO_*
// Para cada objeto sob o prefixo, compara tamanho/ETag no destino e só copia o
// que falta ou divergiu (rodar de novo é incremental).
//
// Uso:
//   npm install
//   npm run sync:dry        # mostra o plano, não escreve nada
//   npm run sync            # sincroniza de verdade
//
// Flags:
//   --dry-run               não escreve no destino, apenas lista o plano
//   --prefix=<p>            sobrepõe o STORAGE_PATH_PREFIX da origem
//   --concurrency=<n>       cópias (download+upload) em paralelo (default 4)
//   --scan-concurrency=<n>  HEADs do plano em paralelo (default 16) — acelera o
//                           início em buckets grandes (antes era 1 a 1, em série)
//   --queue-size=<n>        partes simultâneas por upload multipart (default 4)
//   --part-size=<mb>        tamanho de cada parte multipart em MB (default 8, min 5)
//   --force                 recopia mesmo quando o objeto já existe igual
//
// Dica de velocidade: o teto é banda/egress da Contabo e escrita do MinIO. Suba
// --concurrency aos poucos (8 → 16 → 32). Memória ≈ concurrency × queue-size ×
// part-size, então não exagere os três juntos.

import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

import {
  GetObjectCommand,
  HeadBucketCommand,
  HeadObjectCommand,
  ListObjectsV2Command,
  CreateBucketCommand,
  S3Client,
} from "@aws-sdk/client-s3";
import { Upload } from "@aws-sdk/lib-storage";
import dotenv from "dotenv";

const __dirname = dirname(fileURLToPath(import.meta.url));

// .env desta pasta (origem + destino).
dotenv.config({ path: resolve(__dirname, ".env") });

// ── Argumentos de linha de comando ──────────────────────────────────────────
const args = process.argv.slice(2);
const hasFlag = (name) => args.includes(`--${name}`);
const getOpt = (name, fallback) => {
  const hit = args.find((a) => a.startsWith(`--${name}=`));
  return hit ? hit.split("=").slice(1).join("=") : fallback;
};

const DRY_RUN = hasFlag("dry-run");
const FORCE = hasFlag("force");
const CONCURRENCY = Math.max(1, Number.parseInt(getOpt("concurrency", "4"), 10));
const SCAN_CONCURRENCY = Math.max(1, Number.parseInt(getOpt("scan-concurrency", "16"), 10));
const QUEUE_SIZE = Math.max(1, Number.parseInt(getOpt("queue-size", "4"), 10));
// S3 exige partes >= 5 MB (exceto a última).
const PART_SIZE = Math.max(5, Number.parseInt(getOpt("part-size", "8"), 10)) * 1_048_576;

function requireEnv(name) {
  const v = process.env[name];
  if (!v) {
    console.error(`✘ variável de ambiente ${name} não definida (cheque o .env desta pasta)`);
    process.exit(1);
  }
  return v;
}

// ── Config de origem (Contabo) ──────────────────────────────────────────────
const SRC = {
  endpoint: requireEnv("STORAGE_ENDPOINT"),
  region: process.env.STORAGE_REGION || "us-east-1",
  accessKeyId: requireEnv("STORAGE_ACCESS_KEY"),
  secretAccessKey: requireEnv("STORAGE_SECRET_KEY"),
  bucket: requireEnv("STORAGE_BUCKET"),
  prefix: getOpt("prefix", process.env.STORAGE_PATH_PREFIX || ""),
  forcePathStyle: (process.env.STORAGE_USE_PATH_STYLE || "true") === "true",
};

// ── Config de destino (MinIO local) ─────────────────────────────────────────
const DST = {
  endpoint: process.env.MINIO_ENDPOINT || "http://127.0.0.1:9000",
  region: process.env.MINIO_REGION || "us-east-1",
  accessKeyId: process.env.MINIO_ACCESS_KEY || "minioadmin",
  secretAccessKey: process.env.MINIO_SECRET_KEY || "minioadmin",
  bucket: process.env.MINIO_BUCKET || SRC.bucket,
  forcePathStyle: true, // MinIO sempre path-style
};

const srcClient = new S3Client({
  endpoint: SRC.endpoint,
  region: SRC.region,
  forcePathStyle: SRC.forcePathStyle,
  credentials: { accessKeyId: SRC.accessKeyId, secretAccessKey: SRC.secretAccessKey },
});

const dstClient = new S3Client({
  endpoint: DST.endpoint,
  region: DST.region,
  forcePathStyle: DST.forcePathStyle,
  credentials: { accessKeyId: DST.accessKeyId, secretAccessKey: DST.secretAccessKey },
});

// ── Helpers ─────────────────────────────────────────────────────────────────

async function ensureDestBucket() {
  try {
    await dstClient.send(new HeadBucketCommand({ Bucket: DST.bucket }));
  } catch {
    console.log(`• criando bucket de destino "${DST.bucket}" no MinIO`);
    if (!DRY_RUN) {
      await dstClient.send(new CreateBucketCommand({ Bucket: DST.bucket }));
    }
  }
}

async function* listSource() {
  let token;
  do {
    const out = await srcClient.send(
      new ListObjectsV2Command({
        Bucket: SRC.bucket,
        Prefix: SRC.prefix || undefined,
        ContinuationToken: token,
      }),
    );
    for (const obj of out.Contents || []) {
      if (!obj.Key.endsWith("/")) yield obj; // ignora "pastas"
    }
    token = out.IsTruncated ? out.NextContinuationToken : undefined;
  } while (token);
}

// Decide se precisa copiar: compara tamanho (e ETag quando não-multipart).
async function needsCopy(srcObj) {
  if (FORCE) return true;
  try {
    const head = await dstClient.send(
      new HeadObjectCommand({ Bucket: DST.bucket, Key: srcObj.Key }),
    );
    if (head.ContentLength !== srcObj.Size) return true;
    // ETag de objeto simples = md5 entre aspas. Para multipart contém "-",
    // aí caímos só na comparação de tamanho (já feita acima).
    const srcTag = (srcObj.ETag || "").replaceAll('"', "");
    const dstTag = (head.ETag || "").replaceAll('"', "");
    if (srcTag && !srcTag.includes("-") && srcTag !== dstTag) return true;
    return false;
  } catch {
    return true; // não existe no destino
  }
}

async function copyObject(key) {
  const get = await srcClient.send(
    new GetObjectCommand({ Bucket: SRC.bucket, Key: key }),
  );
  const upload = new Upload({
    client: dstClient,
    // Multipart tunável: partes maiores e mais partes simultâneas aceleram
    // arquivos grandes (ao custo de memória ≈ queueSize × partSize por cópia).
    queueSize: QUEUE_SIZE,
    partSize: PART_SIZE,
    leavePartsOnError: false,
    params: {
      Bucket: DST.bucket,
      Key: key,
      Body: get.Body, // stream — não carrega o arquivo todo em memória
      ContentType: get.ContentType,
      ContentLength: get.ContentLength,
    },
  });
  await upload.done();
}

const fmtMB = (bytes) => (bytes / 1_048_576).toFixed(1);

// Pool genérico: roda `task(item)` com no máximo `limit` em paralelo.
async function runPool(items, limit, task) {
  const queue = [...items];
  async function worker() {
    while (queue.length) {
      const item = queue.shift();
      await task(item);
    }
  }
  await Promise.all(Array.from({ length: Math.min(limit, items.length) || 1 }, worker));
}

// ── Execução ────────────────────────────────────────────────────────────────

async function main() {
  console.log("─".repeat(64));
  console.log(`origem  : ${SRC.endpoint}/${SRC.bucket}/${SRC.prefix || ""}`);
  console.log(`destino : ${DST.endpoint}/${DST.bucket}`);
  console.log(`modo    : ${DRY_RUN ? "DRY-RUN (nada será escrito)" : "sync"}${FORCE ? " +force" : ""}`);
  console.log(`paralelo: copy=${CONCURRENCY} scan=${SCAN_CONCURRENCY} | multipart queue=${QUEUE_SIZE} part=${fmtMB(PART_SIZE)}MB`);
  console.log("─".repeat(64));

  await ensureDestBucket();

  // 1) lista a origem (paginado, rápido) — depois decide o plano com HEADs
  //    CONCORRENTES (antes era 1 HEAD por vez, em série: gargalo em bucket grande).
  const sourceObjects = [];
  for await (const obj of listSource()) sourceObjects.push(obj);
  const scanned = sourceObjects.length;

  const plan = [];
  await runPool(sourceObjects, SCAN_CONCURRENCY, async (obj) => {
    if (await needsCopy(obj)) plan.push(obj);
  });

  console.log(`${scanned} objeto(s) na origem, ${plan.length} a sincronizar.\n`);
  if (plan.length === 0) {
    console.log("✔ destino já está em dia.");
    return;
  }

  // 2) copia com concorrência limitada
  let done = 0;
  let copiedBytes = 0;
  let failed = 0;

  await runPool(plan, CONCURRENCY, async (obj) => {
    const tag = `[${++done}/${plan.length}]`;
    if (DRY_RUN) {
      console.log(`${tag} (dry) ${obj.Key} (${fmtMB(obj.Size)} MB)`);
      return;
    }
    try {
      await copyObject(obj.Key);
      copiedBytes += obj.Size;
      console.log(`${tag} ✔ ${obj.Key} (${fmtMB(obj.Size)} MB)`);
    } catch (err) {
      failed++;
      console.error(`${tag} ✘ ${obj.Key} — ${err.message}`);
    }
  });

  console.log("\n" + "─".repeat(64));
  if (DRY_RUN) {
    console.log(`dry-run: ${plan.length} objeto(s) seriam copiados.`);
  } else {
    console.log(`✔ ${plan.length - failed} copiado(s) (${fmtMB(copiedBytes)} MB), ${failed} falha(s).`);
  }
  if (failed > 0) process.exitCode = 1;
}

main().catch((err) => {
  console.error("erro fatal:", err);
  process.exit(1);
});
