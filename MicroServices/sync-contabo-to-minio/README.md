# sync-contabo-to-minio

Utilitário Node (CLI) que sincroniza objetos de um bucket **S3 da Contabo**
(origem) para um **MinIO local** (destino). É **incremental**: compara
tamanho/ETag e só copia o que falta ou divergiu.

## Uso

```bash
npm install
cp .env.example .env   # preencha STORAGE_* (Contabo) e MINIO_*
npm run sync:dry       # mostra o plano, não escreve nada
npm run sync           # sincroniza de verdade
npm run sync:fast      # atalho: --concurrency=16 --scan-concurrency=32
```

## Flags (velocidade)

| Flag | Default | O que faz |
| --- | --- | --- |
| `--concurrency=<n>` | 4 | cópias (download+upload) em paralelo |
| `--scan-concurrency=<n>` | 16 | `HEAD`s do plano em paralelo (acelera o início em buckets grandes) |
| `--queue-size=<n>` | 4 | partes simultâneas por upload multipart |
| `--part-size=<mb>` | 8 | tamanho de cada parte multipart (mín. 5 MB) |
| `--prefix=<p>` | `STORAGE_PATH_PREFIX` | sobrepõe o prefixo da origem |
| `--dry-run` | — | só lista o plano |
| `--force` | — | recopia mesmo se já existe igual |

> **Memória** ≈ `concurrency × queue-size × part-size`. Para baixar mais rápido,
> suba `--concurrency` aos poucos (8 → 16 → 32) e observe banda/CPU/MinIO. Para
> **um** arquivo gigante, subir a concorrência de objetos não ajuda (cada objeto
> baixa por uma conexão).

## Notas

- Antes a fase de "planejar" fazia **um `HEAD` por objeto em série** — agora é
  paralela (`--scan-concurrency`), então a cópia começa muito antes.
- O upload usa `@aws-sdk/lib-storage` (multipart por stream, sem carregar o
  arquivo inteiro em memória).
