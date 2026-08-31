# Setup — face tracking editável, legenda por locutor e sugestão de cortes

Contexto de entrega: envs novas, passos de deploy, o erro que apareceu no
caminho e o que **não** foi validado. Leia antes de subir em produção.

---

## 1. O que entrou

| Feature | Estado |
| --- | --- |
| Face tracking editável | **Pronto** — `POST /face-tracking` no `media`, botão no `/editor-de-video/{cut}` |
| Cor de legenda por locutor | **Pronto** — locutor visual do tracking → `.ass` com cor por pessoa |
| Sugestão de cortes por LLM | **Estrutura pronta, falta o provedor** — você implementa a chamada à IA |

O tracking grava os keyframes na **mesma coluna** que o editor já carregava
(`video_cuts_edits.keyframes`), então timeline, arrastar, undo/redo e preview
funcionam sem UI nova. É isso que torna o tracking editável: o operador aperta
um botão, assiste e ajusta o que não gostou.

---

## 2. Envs novas

### Laravel (`.env` da raiz)

| Env | Valor | Pra que serve |
| --- | --- | --- |
| `FACE_TRACKING_URL` | `http://127.0.0.1:8770` | base do `media` (mesma porta da transcrição) |
| `FACE_TRACKING_TIMEOUT` | `120` | só espera o 202; o upload do clip conta aqui |
| `FACE_TRACKING_MAX_KEYFRAMES` | `40` | teto que vai no payload — ver §6 |
| `FACE_TRACKING_WEBHOOK_URL` | `${APP_URL}/api/webhook/face-tracking` | tem default, só setar se o APP_URL não servir |
| `CUT_SUGGESTION_MIN_DURATION` | `60` | trava de duração mínima do corte sugerido |
| `CUT_SUGGESTION_MAX_DURATION` | `80` | duração máxima (trunca, não descarta) |
| `CUT_SUGGESTION_MIN_GAP` | `1.0` | gap mínimo entre dois cortes sugeridos |
| `CUT_SUGGESTION_MAX_CUTS` | `20` | teto de cortes por busca |

Nenhuma delas é obrigatória: todas têm default igual ao valor acima em
`config/services.php`. **Não existe `.env.example` na raiz deste repo** (está no
`.gitignore`), por isso a lista vive aqui.

### `MicroServices/Media/.env`

| Env | Valor |
| --- | --- |
| `FACE_TRACKING_SAMPLE_FPS` | `6` |
| `FACE_TRACKING_MAX_KEYFRAMES` | `40` |
| `FACE_TRACKING_MODELS_DIR` | `./models` |
| `FACE_TRACKING_DELEGATE` | `auto` |

Já estão no `MicroServices/Media/.env.example`. `auto` usa GPU só em
Linux/NVIDIA; **macOS cai sempre pra CPU** — o delegate Metal do MediaPipe
aborta o processo com crash C++ não capturável.

### `MicroServices/Video`

Nenhuma env nova.

---

## 3. Deploy

1. `php artisan migrate` — adiciona `tracking_status` e `tracking_error` em
   `video_cuts_edits`.
2. `php artisan config:cache` (as chaves novas de `config/services.php`).
3. No `media`: `.venv/bin/pip install -r requirements.txt` — entram `mediapipe`
   e `numpy`. **O `.venv` local hoje está com `mediapipe 1.0.0`, que viola o pin
   `<1.0`**; esse passo faz o downgrade pra 0.10.x. Sem ele, o serviço sobe mas
   pode abortar no macOS (§5).
4. Primeiro job baixa o `face_landmarker.task` (~3.7MB) sozinho pra
   `FACE_TRACKING_MODELS_DIR`. A pasta está no `.gitignore` do serviço.
5. `pnpm build` — o editor tem TS novo (o dev serve assets buildados).

Nada a fazer no `video`: o contrato novo é retrocompatível (§4).

---

## 4. Retrocompatibilidade

Corte antigo, sem tracking e sem locutor, renderiza **byte a byte igual** ao que
renderizava antes. Isso está coberto por teste nos dois lados:

- `MicroServices/Video/tests/SubtitleSpeakerTest.ts` — o `.ass` sem `speaker`,
  com `speaker` sem mapa de cor, e com mapa vazio saem idênticos ao legado; o
  `SubtitleGoldenTest` continua batendo com os goldens do AutoCaption.
- `tests/Feature/FaceTracking/FaceTrackingFlowTest.php` — transcript sem
  tracking chega no `/reframe` sem o campo `speaker`.

A chave `speakerColors` **só entra** no `settings` quando há locutor detectado,
então edição sem tracking mantém o JSON gravado exatamente como sempre foi.

---

## 5. Erro encontrado no caminho (o contexto que você pediu)

### `.env.testing` a partir do `.env.testing.example` QUEBRA a suíte

Reproduzido: `cp .env.testing.example .env.testing && php artisan test` →
**82 falhas** de 158, todas com:

```
No application encryption key has been specified.
(View: storage/framework/views/*.blade.php)
```

**Causa**: o Laravel *substitui* o `.env` pelo `.env.testing` quando
`APP_ENV=testing` — não mescla. O `.env.testing.example` deste repo só tem as
seis linhas de banco, sem `APP_KEY`, então a app perde a chave de criptografia.

**Como está agora**: sem `.env.testing`. A suíte roda verde porque o
`phpunit.xml` já define tudo (e, conforme o CLAUDE.md, o `phpunit.xml` vence o
`.env.testing` de qualquer jeito — o PHPUnit seta as vars antes do bootstrap e o
`safeLoad()` do Dotenv não sobrescreve).

**Decisão que é sua**: ou adiciona `APP_KEY=` ao `.env.testing.example` (e roda
`php artisan key:generate --env=testing`; a linha precisa existir, senão o
comando sai sem escrever e **sem erro**), ou apaga o `.env.testing.example`, que
hoje só serve de armadilha. Não mexi em nenhum dos dois — é decisão de setup do
projeto.

---

## 6. Tetos conhecidos (nomeados de propósito)

1. **Densidade de keyframes.** O `/reframe` monta uma cadeia de `if()` aninhada
   no filtro do ffmpeg, **um nível por keyframe, por eixo, por slot**, avaliada
   frame a frame. Por isso o tracking amostra a 6fps mas simplifica a curva por
   RDP até caber em 40 keyframes. Subir muito esse teto encarece o render e
   torna o ajuste manual impraticável — 40 keyframes num corte de 60s já é
   quase um a cada 1,5s.
2. **Rosto pequeno não é detectado.** O detector do FaceLandmarker é
   *short-range* (entrada 128×128). Medido: num frame 1080p ele enxerga rosto a
   partir de ~265px de largura (~14% do quadro) e não enxerga a 230px. Talking
   head passa; plano aberto não, e o clipe sai com crop centralizado (fallback).
3. **Locutor fora de quadro.** A detecção é visual: quem fala sem aparecer não
   ganha cor de legenda. Diarização por áudio (pyannote) resolveria, ao custo de
   `torch` + token do HuggingFace — ficou de fora de propósito.
4. **Pesos do score de locutor não calibrados em conteúdo real.** Ver §7.
5. **`/reframe` não tem timeout.** Filtergraph que trava deixa a fila serial
   pendurada pra sempre. Pré-existente, fora do escopo desta entrega — o teto de
   keyframes reduz a chance, não elimina a causa.

---

## 7. O que NÃO foi validado

Ninguém rodou isto num vídeo real de produção. Especificamente:

- **Pesos do active speaker detection.** O `track()` foi exercitado fim a fim
  em vídeos sintéticos (rosto estático da própria MediaPipe, plano deslizando).
  Como o rosto não mexe a boca, a atividade labial foi sempre 0 e o desempate
  caiu todo no peso de tamanho. Os pesos `SIZE_WEIGHT`/`SPEECH_WEIGHT` em
  `app/facetracking/tracker.py` são heurística — o comentário `ponytail:` ali
  diz qual mexer pra cada sintoma. **Espere calibrar com material real.**
- **Delegate de GPU.** Não há NVIDIA na máquina de dev; o caminho `auto → gpu`
  nunca foi exercitado, só lido.
- **Sugestão de cortes ponta a ponta.** Sem provedor de IA, o fluxo real nunca
  rodou. O que está testado é a trava que roda *depois* da IA
  (`CutSuggestionValidatorService`, 8 testes) e a UI/job.

---

## 8. Como plugar a IA (o que ficou pra você)

1. Crie a classe em `app/Services/CutSuggestion/` implementando
   `CutSuggestionInterface` (um método: `suggest(Video, array $transcript,
   string $prompt): array` devolvendo `CutSuggestionData[]`).
2. Troque o bind no `AppServiceProvider` — hoje aponta pro
   `UnconfiguredCutSuggestionService`, que só lança exceção.

Nada mais no fluxo conhece o provedor. O que sua classe devolver passa
obrigatoriamente pelo `CutSuggestionValidatorService`, que **não confia** na
resposta do modelo: reimpõe duração, gap, ordem temporal e desempate por score
antes de virar `video_cuts`. A cascata Gemini + rate limiter do serviço antigo
ainda está no histórico do git, se quiser reaproveitar:

```bash
git show 5d7c150^:MicroServices/GenerateClips/app/llm/gemini/rate_limit.py
git show 5d7c150^:MicroServices/GenerateClips/app/pipeline/analyzer.py
```

---

## 9. Validação desta entrega

| Alvo | Resultado |
| --- | --- |
| `composer phpstan` (nível max) | 0 erros |
| `php artisan test` | **158/158**, 471 asserts (eram 133 antes) |
| `npx tsc --noEmit` | limpo |
| `composer lint:check` (pint + rector) | limpo |
| `media`: ruff + `mypy --strict` | limpo, 14 arquivos |
| `media`: `python -m app.facetracking.check` | 5/5 |
| `video`: typecheck + lint + test | limpo, 3 suítes |

Testes novos: 8 do validador de sugestões, 9 do webhook de tracking, 8 do fluxo
(claim atômico, idempotência de webhook, merge de locutor no transcript), 3 de
legenda por locutor no `video`, 5 no self-check do `media`.
