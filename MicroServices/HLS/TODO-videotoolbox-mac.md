# Validar o encoder VideoToolbox (GPU do Mac) — não testado em hardware

## O que já existe

O `HLSPackager` escolhe o encoder de hardware conforme o SO:

| SO | Encoder | Status |
| --- | --- | --- |
| macOS (`darwin`) | `h264_videotoolbox` | ⚠️ **nunca rodou num Mac** |
| Linux/Windows + NVIDIA | `h264_nvenc` | escrito, não rodou (box de dev não tem NVIDIA) |
| qualquer um sem HW | `libx264` | ✅ testado, empacota ponta a ponta |

A **lógica de seleção e o fallback estão testados** — na box de dev (Linux sem
NVIDIA) o serviço loga `GPU NVIDIA/NVENC indisponível → Encoder: libx264 (SO
linux)` e empacota normal. O que falta é rodar o caminho `h264_videotoolbox`
num Mac de verdade.

**Risco contido:** se o VideoToolbox falhar, o `package()` cai para `libx264`
sozinho (inclusive se quebrar no meio do job). O pior caso é "lento", não
"quebrado". Então isto é otimização, não bug bloqueante.

## Onde mexer

`MicroServices/HLS/app/Services/Video/HLSPackager.ts`

- `pickEncoder()` — o `if (process.platform === 'darwin')` que escolhe VideoToolbox
- `encoderWorks(codec)` — a detecção: lista do `ffmpeg -encoders` + um encode de
  teste real de 64x64. É esse teste que prova o hardware
- `encodeArgs()` — os flags por encoder

## Flags atuais do VideoToolbox (os suspeitos)

```
-c:v:N h264_videotoolbox
-b:v:N <bitrate>k  -maxrate:v:N <...>k  -bufsize:v:N <...>k
-allow_sw 1
-pix_fmt yuv420p
-sc_threshold 0
-force_key_frames expr:gte(t,n_forced*6)
```

O que precisa de olho, em ordem de risco:

1. **Alinhamento de keyframes entre renditions** — é o mais crítico. Se as
   variantes não tiverem keyframe no mesmo instante, o player trava ao trocar
   de qualidade. O `-force_key_frames` funciona no nível do ffmpeg, mas o
   VideoToolbox pode não honrar o GOP exato. **Verificar com o comando abaixo.**
2. **`-maxrate` / `-bufsize`** — o suporte do VideoToolbox a rate control é
   limitado; pode ignorar silenciosamente e entregar bitrate fora do alvo, o
   que estraga o ABR (o player escolhe pela `BANDWIDTH` do master).
3. **`-allow_sw 1`** — deixa cair pro encoder de software da Apple. Confirmar
   que **não** está silenciosamente encodando em software (o que anularia o
   ganho e daria a falsa impressão de que a GPU funcionou).
4. **`-sc_threshold 0`** — é opção do libx264. No VideoToolbox provavelmente é
   ignorada/warning. Se poluir o log, condicionar ao codec.
5. **`-hwaccel`** — hoje só o NVENC recebe (`-hwaccel cuda`). Avaliar
   `-hwaccel videotoolbox` no macOS para acelerar também o **decode** da fonte.
6. **Sessões simultâneas** — o comando roda 1 ffmpeg com 3 encodes em paralelo
   (via `split`). Confirmar que o VideoToolbox aguenta as 3 sessões; se
   estourar, o fallback pega, mas aí perde-se a GPU.

## Como validar num Mac

```bash
cd MicroServices/HLS
cp .env.example .env          # HLS_ENCODER=gpu é o default
pnpm install && pnpm dev
```

1. Confirmar a escolha no log de boot do primeiro job:
   `Encoder: h264_videotoolbox (SO darwin)` — se aparecer
   `VideoToolbox indisponível`, a detecção falhou (investigar `encoderWorks`).

2. Empacotar um vídeo real (fonte já no storage, em `uploads/<chave>`):
   ```bash
   curl -X POST http://127.0.0.1:8795/package \
     -H 'Content-Type: application/json' \
     -H 'Authorization: Bearer <API_TOKEN>' \
     -d '{"video_key":"uploads/<chave>","output_prefix":"hls/<chave>","webhook_url":"http://127.0.0.1:8000/api/hls/webhook"}'
   ```

3. **Keyframes alinhados** (o teste que importa) — baixar a saída e comparar
   entre as renditions; os `pts_time` têm que bater exatamente:
   ```bash
   for r in 360p 720p 1080p; do
     echo -n "$r: "
     ffprobe -v error -select_streams v -skip_frame nokey \
       -show_entries frame=pts_time -of csv=p=0 "$r/index.m3u8" | paste -sd' '
   done
   ```
   Referência do que passou com libx264:
   `0.066016 6.066016 12.066016 18.066016 ...` — idêntico nas duas renditions.

4. **Confirmar que usou a GPU mesmo**: com o job rodando, abrir o Activity
   Monitor (aba GPU) ou `powermetrics --samplers gpu_power`. Se a GPU estiver
   ociosa e a CPU a 100%, o `-allow_sw 1` caiu para software.

5. Comparar tempo de encode contra `HLS_ENCODER=cpu` no mesmo arquivo — se não
   houver ganho relevante, a aceleração não está valendo.

## Critério de aceite

- [ ] Log mostra `Encoder: h264_videotoolbox (SO darwin)`
- [ ] Empacotamento conclui e o webhook fecha o vídeo em `ready`
- [ ] Keyframes idênticos entre todas as renditions
- [ ] GPU realmente ativa durante o encode (não software)
- [ ] Ganho de tempo mensurável vs `HLS_ENCODER=cpu`
- [ ] Se algum flag precisou mudar: ajustar em `encodeArgs()` **condicionado ao
      codec**, sem quebrar os caminhos NVENC e libx264

## Contexto extra

- `README.md` deste serviço descreve as decisões de encode.
- O `HLS_ENCODER=cpu` continua sendo o escape para liberar a GPU ao AutoCaption
  (WhisperX/CUDA), que disputa o mesmo hardware em máquinas NVIDIA — no Mac esse
  conflito não existe.
