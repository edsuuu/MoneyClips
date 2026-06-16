# download-shorts

Microservico independente para baixar YouTube Shorts, enviar os arquivos para um storage S3-compativel e despachar o resultado para um webhook.

## Rodar

```bash
cd download-shorts
python -m venv .venv
.venv/bin/python -m pip install -r requirements.txt
cp .env.example .env
.venv/bin/alembic upgrade head
.venv/bin/python -m app.main
```

`AUTO_CREATE_TABLES=true` tambem cria as tabelas no startup. Em producao, prefira `AUTO_CREATE_TABLES=false` e rode `alembic upgrade head`.

Configuracao de banco segue o formato separado:

```env
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=download_shorts
DB_USERNAME=root
DB_PASSWORD=root
```

## Criar download

```bash
curl -X POST http://127.0.0.1:8770/shorts/download \
  -H 'Content-Type: application/json' \
  -d '{
    "channel_url": "https://www.youtube.com/@canal",
    "webhook_url": "https://app.com/api/shorts/callback",
    "dispatch_on_complete": true
  }'
```

Resposta:

```json
{"job_id":"uuid","status":"accepted"}
```

## Estrutura

```text
app/
  main.py
  schemas.py
  config/
  database/
  jobs/
  storage/
  youtube/
```

## Consultar status

```bash
curl http://127.0.0.1:8770/shorts/download/<job_id>
```

## Disparar manualmente

Quando `dispatch_on_complete=false`, os itens concluidos ficam com `dispatch_status=pending`.

Enviar em lote de 50:

```bash
curl -X POST http://127.0.0.1:8770/shorts/download/<job_id>/dispatch \
  -H 'Content-Type: application/json' \
  -d '{"mode":"batch","batch_size":50}'
```

Enviar todos os pendentes:

```bash
curl -X POST http://127.0.0.1:8770/shorts/download/<job_id>/dispatch \
  -H 'Content-Type: application/json' \
  -d '{"mode":"all"}'
```

Enviar o proximo lote pendente de todos os jobs para um webhook e remover do
banco do microservico apenas apos sucesso no webhook:

```bash
curl -X POST http://127.0.0.1:8770/shorts/dispatch \
  -H 'Content-Type: application/json' \
  -d '{"mode":"batch","batch_size":1000,"webhook_url":"http://app.test/api/download-youtube/webhook","delete_after_dispatch":true}'
```

## Payload do webhook

```json
{
  "event": "shorts.download.dispatched",
  "job_id": "uuid",
  "channel_url": "https://www.youtube.com/@canal",
  "summary": {
    "total": 500,
    "completed": 492,
    "failed": 8
  },
  "items": [
    {
      "youtube_id": "abc123",
      "download_url": "https://www.youtube.com/shorts/abc123",
      "title": "Titulo",
      "hashtags": ["#shorts"],
      "storage_path": "shorts/abc123.mp4",
      "storage": {
        "path": "shorts/abc123.mp4",
        "size_bytes": 123456,
        "mime_type": "video/mp4"
      }
    }
  ]
}
```
