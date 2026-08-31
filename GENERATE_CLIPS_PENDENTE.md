# GenerateClips — o que falta portar

O microserviço `MicroServices/GenerateClips` foi a versão monolítica antiga do
pipeline "vídeo longo → cortes curtos". **A pasta foi apagada** — o código
vive no histórico do git (paths abaixo referem-se à última versão antes da
remoção). A maior parte já foi reescrita e distribuída nos serviços ativos:

| Etapa | Onde roda hoje |
| --- | --- |
| Download do YouTube | `media` (:8770) |
| Transcrição | `media` `/transcriptions` |
| Corte frame-exato | `video` `/cut` |
| Crop 9:16 + render | `video` `/reframe` |
| Legenda karaokê queimada | `video` `/videos` |
| HLS + storage | `video` `/package` + Laravel/MinIO |

O que segue abaixo **só existia no `GenerateClips`** e ainda não tem equivalente.

---


Escolher automaticamente os melhores momentos de um vídeo longo em vez de o
operador marcar tudo à mão no `/editor-de-video`.

**Já existe** (`app/Services/CutSuggestion/`): `CutSuggestionInterface` (o único
ponto de contato com a IA), `CutSuggestionData`, `CutSuggestionValidatorService`
(as travas do `_validate_and_clean` portadas: duração 60–80s, gap mínimo de 1s,
ordem temporal, desempate por score, teto de cortes — tudo configurável em
`services.cut_suggestion`), `SuggestCutsJob` e o botão "Buscar" da
`/meus-uploads/{video}` ligado ao input de busca. Os cortes nascem como
`video_cuts` em rascunho com `is_ai_generated = true`.

**Falta**: implementar `CutSuggestionInterface` de verdade e trocar o bind no
`AppServiceProvider` (hoje aponta pro `UnconfiguredCutSuggestionService`, que
lança exceção). A cascata Gemini + rate limiter do serviço antigo continua no
histórico do git, em `git show 5d7c150^:MicroServices/GenerateClips/app/llm/`.

- Fonte: `app/pipeline/analyzer.py` + `app/llm/`.
- LLM primário Gemini com fallback local Ollama (`gemma2:9b`), atrás da
  interface `LLMProvider` (`get_provider("auto")`).
- Cascata de modelos Gemini + rate-limiter próprio por modelo (RPM/TPM/RPD),
  em `app/llm/gemini/`.
- Validação da transcrição cruzando com o áudio via **Gemini multimodal**
  (cascata separada, só modelos que aceitam áudio) — `app/pipeline/validator.py`.
- Saída: N cortes (60–80s) com ordem temporal validada, gap mínimo e score.


Gerar a trajetória do crop 9:16 seguindo o rosto de quem fala, em vez dos
keyframes manuais que o operador marca hoje.

**Implementado** como `POST /face-tracking` no serviço `media`, com botão
"Gerar tracking automático" no `/editor-de-video/{cut}`. Os keyframes caem na
mesma coluna `video_cuts_edits.keyframes` que o editor já carregava, então
timeline, drag, undo/redo e preview funcionam sem UI nova — é isso que torna o
tracking editável. A curva é simplificada por RDP até caber em
`FACE_TRACKING_MAX_KEYFRAMES` (40), porque o `/reframe` monta uma cadeia de
`if()` aninhada no filtro do ffmpeg, um nível por keyframe.

Dois defeitos do código original foram corrigidos no porte, não copiados:
identidade de rosto agora é por IoU (era índice do array, que trocava a boca de
uma pessoa pela da outra) e o score do locutor normaliza os três sinais em vez
de multiplicar por `1e6`. O `librosa` não voltou — era dependência morta.

Referência original abaixo.

- Fonte: `app/pipeline/face_tracker.py`.
- MediaPipe Tasks (detecção de face + landmarks dos lábios) + `librosa`
  (energia do áudio) + `scipy` (suavização Savitzky-Golay).
- Active Speaker Detection **visual**: por frame amostrado, escolhe a face com
  maior `lip_activity × audio_energy + size_bonus` como speaker.
- Detecta *onde na tela* está quem fala — não *quem* é o locutor.
- **Não precisa reescrever o render.** O `/reframe` já faz o crop por
  keyframes; o pendente é só produzir esses keyframes automaticamente e
  alimentar o `/reframe` existente.

---

## Opcional — diarização na transcrição (detectar quem está falando)

**Nota**: a cor de legenda por locutor JÁ funciona sem isto, usando o locutor
visual do face tracking (`speakers.json` ao lado do transcript → campo
`speaker` no segmento → tag de cor no `.ass`). A diarização por áudio só passa
a valer a pena pra quem fala fora de quadro.

Rotular o texto por locutor (Speaker 1 / Speaker 2 / …) durante a transcrição.

- **Não existe em lugar nenhum hoje.** A transcrição do `media`
  (faster-whisper) só devolve texto + timestamps de palavra, sem rótulo de
  quem fala.
- É **diferente** do item 2: aquele é visual (posição do rosto na tela); este é
  por **áudio** (separar vozes). Um não substitui o outro.
- Precisaria de uma lib de diarização (ex.: pyannote.audio / whisperx),
  ausente no stack atual.
- Feature opcional: só faz sentido se houver vídeo com múltiplos participantes
  em que saber *quem* falou agregue valor ao corte ou à legenda.
