import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

import {
  CreateBucketCommand,
  GetObjectCommand,
  HeadBucketCommand,
  HeadObjectCommand,
  ListObjectsV2Command,
  S3Client,
} from "@aws-sdk/client-s3";
import { Upload } from "@aws-sdk/lib-storage";
import dotenv from "dotenv";

const __dirname = dirname(fileURLToPath(import.meta.url));
dotenv.config({ path: resolve(__dirname, ".env") });


function parseArgs(argv) {
  const args = argv.slice(2);
  const hasFlag = (name) => args.includes(`--${name}`);
  const getOpt = (name, fallback) => {
    const hit = args.find((a) => a.startsWith(`--${name}=`));
    return hit ? hit.split("=").slice(1).join("=") : fallback;
  };
  const intOpt = (name, fallback) =>
    Math.max(1, Number.parseInt(getOpt(name, String(fallback)), 10));

  return {
    dryRun: hasFlag("dry-run"),
    force: hasFlag("force"),
    concurrency: intOpt("concurrency", 4),
    scanConcurrency: intOpt("scan-concurrency", 16),
    queueSize: intOpt("queue-size", 4),
    partSize: Math.max(5, Number.parseInt(getOpt("part-size", "8"), 10)) * 1_048_576,
    prefixOverride: getOpt("prefix", undefined),
  };
}

function requireEnv(name) {
  const v = process.env[name];
  if (!v) {
    console.error(`✘ variavel ${name} nao definida (cheque o .env desta pasta)`);
    process.exit(1);
  }
  return v;
}

function buildSourceConfig(prefixOverride) {
  return {
    endpoint: requireEnv("STORAGE_ENDPOINT"),
    region: process.env.STORAGE_REGION || "us-east-1",
    accessKeyId: requireEnv("STORAGE_ACCESS_KEY"),
    secretAccessKey: requireEnv("STORAGE_SECRET_KEY"),
    bucket: requireEnv("STORAGE_BUCKET"),
    prefix: prefixOverride ?? process.env.STORAGE_PATH_PREFIX ?? "",
    forcePathStyle: (process.env.STORAGE_USE_PATH_STYLE || "true") === "true",
  };
}

function buildDestinationConfig(srcBucket) {
  return {
    endpoint: process.env.MINIO_ENDPOINT || "http://127.0.0.1:9000",
    region: process.env.MINIO_REGION || "us-east-1",
    accessKeyId: process.env.MINIO_ACCESS_KEY || "minioadmin",
    secretAccessKey: process.env.MINIO_SECRET_KEY || "minioadmin",
    bucket: process.env.MINIO_BUCKET || srcBucket,
    forcePathStyle: true,
  };
}

function buildClient({ endpoint, region, accessKeyId, secretAccessKey, forcePathStyle }) {
  return new S3Client({
    endpoint,
    region,
    forcePathStyle,
    credentials: { accessKeyId, secretAccessKey },
  });
}


async function ensureDestinationBucket(client, bucket, dryRun) {
  try {
    await client.send(new HeadBucketCommand({ Bucket: bucket }));
    return;
  } catch {}

  console.log(`• criando bucket de destino "${bucket}" no MinIO`);
  if (!dryRun) {
    await client.send(new CreateBucketCommand({ Bucket: bucket }));
  }
}

async function* listSource(client, bucket, prefix) {
  let token;
  do {
    const out = await client.send(
      new ListObjectsV2Command({
        Bucket: bucket,
        Prefix: prefix || undefined,
        ContinuationToken: token,
      }),
    );
    for (const obj of out.Contents || []) {
      if (!obj.Key.endsWith("/")) yield obj;
    }
    token = out.IsTruncated ? out.NextContinuationToken : undefined;
  } while (token);
}

async function needsCopy(dstClient, dstBucket, srcObj, force) {
  if (force) return true;
  try {
    const head = await dstClient.send(
      new HeadObjectCommand({ Bucket: dstBucket, Key: srcObj.Key }),
    );
    if (head.ContentLength !== srcObj.Size) return true;
    const srcTag = (srcObj.ETag || "").replaceAll('"', "");
    const dstTag = (head.ETag || "").replaceAll('"', "");
    if (srcTag && !srcTag.includes("-") && srcTag !== dstTag) return true;
    return false;
  } catch {
    return true;
  }
}

async function copyObject(srcClient, dstClient, src, dst, key, queueSize, partSize) {
  const get = await srcClient.send(new GetObjectCommand({ Bucket: src.bucket, Key: key }));
  const upload = new Upload({
    client: dstClient,
    queueSize,
    partSize,
    leavePartsOnError: false,
    params: {
      Bucket: dst.bucket,
      Key: key,
      Body: get.Body,
      ContentType: get.ContentType,
      ContentLength: get.ContentLength,
    },
  });
  await upload.done();
}


async function runPool(items, limit, task) {
  const queue = [...items];
  const worker = async () => {
    while (queue.length) {
      const item = queue.shift();
      await task(item);
    }
  };
  const workers = Math.min(limit, items.length) || 1;
  await Promise.all(Array.from({ length: workers }, worker));
}

const fmtMB = (bytes) => (bytes / 1_048_576).toFixed(1);


function printHeader(src, dst, opts) {
  const sep = "─".repeat(64);
  console.log(sep);
  console.log(`origem  : ${src.endpoint}/${src.bucket}/${src.prefix || ""}`);
  console.log(`destino : ${dst.endpoint}/${dst.bucket}`);
  console.log(`modo    : ${opts.dryRun ? "DRY-RUN" : "sync"}${opts.force ? " +force" : ""}`);
  console.log(
    `paralelo: copy=${opts.concurrency} scan=${opts.scanConcurrency} | ` +
      `multipart queue=${opts.queueSize} part=${fmtMB(opts.partSize)}MB`,
  );
  console.log(sep);
}

function printSummary(planSize, copiedBytes, failed, dryRun) {
  console.log("\n" + "─".repeat(64));
  if (dryRun) {
    console.log(`dry-run: ${planSize} objeto(s) seriam copiados.`);
    return;
  }
  console.log(`✔ ${planSize - failed} copiado(s) (${fmtMB(copiedBytes)} MB), ${failed} falha(s).`);
}


async function main() {
  const opts = parseArgs(process.argv);
  const src = buildSourceConfig(opts.prefixOverride);
  const dst = buildDestinationConfig(src.bucket);
  const srcClient = buildClient(src);
  const dstClient = buildClient(dst);

  printHeader(src, dst, opts);

  await ensureDestinationBucket(dstClient, dst.bucket, opts.dryRun);

  const sourceObjects = [];
  for await (const obj of listSource(srcClient, src.bucket, src.prefix)) {
    sourceObjects.push(obj);
  }

  const plan = [];
  await runPool(sourceObjects, opts.scanConcurrency, async (obj) => {
    if (await needsCopy(dstClient, dst.bucket, obj, opts.force)) plan.push(obj);
  });

  console.log(`${sourceObjects.length} objeto(s) na origem, ${plan.length} a sincronizar.\n`);
  if (plan.length === 0) {
    console.log("✔ destino ja esta em dia.");
    return;
  }

  let done = 0;
  let copiedBytes = 0;
  let failed = 0;

  await runPool(plan, opts.concurrency, async (obj) => {
    const tag = `[${++done}/${plan.length}]`;
    if (opts.dryRun) {
      console.log(`${tag} (dry) ${obj.Key} (${fmtMB(obj.Size)} MB)`);
      return;
    }
    try {
      await copyObject(srcClient, dstClient, src, dst, obj.Key, opts.queueSize, opts.partSize);
      copiedBytes += obj.Size;
      console.log(`${tag} ✔ ${obj.Key} (${fmtMB(obj.Size)} MB)`);
    } catch (err) {
      failed++;
      console.error(`${tag} ✘ ${obj.Key} — ${err.message}`);
    }
  });

  printSummary(plan.length, copiedBytes, failed, opts.dryRun);
  if (failed > 0) process.exitCode = 1;
}


main().catch((err) => {
  console.error("erro fatal:", err);
  process.exit(1);
});
