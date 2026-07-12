# AutoCaption — Benchmarks

- **Data:** 2026-07-12 12:21 -03
- **GPU:** NVIDIA GeForce RTX 4060 Ti
- **Transcrição:** faster-whisper `large-v3` (pt), compute `float16`, word_timestamps + VAD
- **Encoder:** nvenc qualidade máxima (NVENC p7, tune hq, CQ 16) · **Fonte:** Realist Clostan (size 12)

RTF = tempo de processamento ÷ duração do vídeo (< 1.0 = mais rápido que tempo real).

| Processo | temporada-1-ep-2 | temporada-1-ep-03-01 |
|---|---|---|
| **Resolução** | 1920x1080 | 1920x1080 |
| **Duração** | 84.4s | 70.9s |
| **Palavras** | 219 | 181 |
| Extração de áudio | 0.3s (RTF 0.00) | 0.3s (RTF 0.00) |
| Transcrição (faster-whisper) | 18.3s (RTF 0.22) | 6.3s (RTF 0.09) |
| Variante: original | 15.8s (RTF 0.19) | 13.0s (RTF 0.18) |
| Variante: vertical 9:16 (blur) | 41.1s (RTF 0.49) | 34.9s (RTF 0.49) |
| Variante: template branco | 13.7s (RTF 0.16) | 11.8s (RTF 0.17) |
| Variante: template preto | 13.8s (RTF 0.16) | 11.9s (RTF 0.17) |
| **TOTAL (áudio+transcrição+4 variantes)** | 102.9s (RTF 1.22) | 78.2s (RTF 1.10) |

### Tamanho dos arquivos gerados

| Variante | temporada-1-ep-2 | temporada-1-ep-03-01 |
|---|---|---|
| original | 60.9 MB | 46.2 MB |
| vertical 9:16 | 52.5 MB | 36.6 MB |
| template branco | 33.3 MB | 23.0 MB |
| template preto | 32.8 MB | 22.6 MB |

> Transcrição inclui o load do modelo na 1ª execução (amortizado com o serviço no ar). A variante 9:16 é a mais cara (desfoque de fundo em CPU).

