<div @if ($isPackaging || $isTranscribing || $hasBusyCuts) wire:poll.5s @endif>
    @if ($isReady)
        <div
            x-data="videoPlayer(@js(['hlsSrc' => $hlsUrl, 'fallbackSrc' => $fallbackUrl ?? '', 'poster' => $posterUrl ?? '', 'storyboard' => $storyboard, 'captionsKey' => $captionsKey]))"
            x-on:captions-refresh.window="reloadCaptions()"
            x-on:trim-seek.window="seekTo($event.detail.time)"
            x-on:trim-scrub.window="scrubTo($event.detail.time)"
            class="grid gap-6 lg:h-[calc(100dvh-109px)] lg:grid-cols-[minmax(0,1fr)_340px] lg:overflow-hidden"
        >
            <div class="flex min-w-0 flex-col lg:min-h-0 lg:overflow-hidden">
                <div
                    wire:ignore
                    x-ref="wrapper"
                    tabindex="0"
                    x-on:keydown="onKey($event)"
                    x-on:pointerdown="$refs.wrapper.focus()"
                    x-on:pointermove="showControls()"
                    x-on:pointerleave="playing && (controlsVisible = false)"
                    class="player-chrome group relative select-none overflow-hidden rounded-2xl border border-slate-800 bg-black outline-none lg:min-h-0 lg:flex-1"
                    x-bind:class="controlsVisible || !playing ? 'cursor-default' : 'cursor-none'"
                >
                    <video
                        x-ref="video"
                        x-on:click="togglePlay()"
                        class="aspect-video w-full object-contain lg:aspect-auto lg:h-full"
                        playsinline
                        preload="metadata"
                        @if ($posterUrl) poster="{{ $posterUrl }}" @endif
                    >
                        @if ($subtitlesUrl)
                            <track x-ref="captions" kind="subtitles" srclang="pt" label="Português" src="{{ $subtitlesUrl }}" />
                        @endif
                    </video>

                    <button
                        type="button"
                        x-show="ready && !playing"
                        x-on:click="togglePlay()"
                        x-transition.opacity
                        class="absolute inset-0 flex cursor-pointer items-center justify-center bg-black/25"
                        aria-label="Reproduzir"
                    >
                        <span class="flex size-16 items-center justify-center rounded-full bg-black/55 ring-1 ring-white/20 backdrop-blur transition group-hover:scale-105">
                            <x-ui.icon name="play" class="size-8 translate-x-0.5 text-white" />
                        </span>
                    </button>

                    <div
                        x-show="ready && (switching || waiting)"
                        x-cloak
                        class="pointer-events-none absolute inset-0 flex items-center justify-center"
                    >
                        <x-ui.icon name="loading" class="size-12 text-white/90" />
                    </div>

                    <div
                        x-show="controlsVisible"
                        x-cloak
                        x-transition.opacity
                        class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/85 via-black/40 to-transparent px-4 pb-3 pt-10"
                    >
                        <div
                            x-ref="track"
                            x-on:pointerdown="startScrub($event)"
                            x-on:pointermove="trackMove($event)"
                            x-on:pointerup="endScrub()"
                            x-on:pointercancel="endScrub()"
                            x-on:pointerleave="hidePreview()"
                            class="group/track relative h-1.5 cursor-pointer rounded-full bg-white/25"
                        >
                            @if ($storyboard)
                                <div
                                    x-show="preview.visible"
                                    x-cloak
                                    class="pointer-events-none absolute bottom-full mb-3 -translate-x-1/2 overflow-hidden rounded-md border border-white/20 shadow-xl"
                                    x-bind:style="`left: ${preview.x}px`"
                                >
                                    <div class="block" x-bind:style="preview.style"></div>
                                    <div class="bg-black/80 py-0.5 text-center text-[11px] font-semibold text-white" x-text="preview.time"></div>
                                </div>
                            @endif

                            <div class="absolute inset-y-0 left-0 rounded-full bg-white/30" x-bind:style="`width: ${bufferedPercent}%`"></div>
                            <div class="absolute inset-y-0 left-0 rounded-full bg-sky-500" x-bind:style="`width: ${progressPercent}%`"></div>
                            <div class="absolute top-1/2 size-3 -translate-x-1/2 -translate-y-1/2 rounded-full bg-white opacity-0 transition group-hover/track:opacity-100" x-bind:style="`left: ${progressPercent}%`"></div>
                        </div>

                        <div class="mt-2.5 flex items-center gap-3 text-white">
                            <button type="button" x-on:click="togglePlay()" class="shrink-0 cursor-pointer" aria-label="Play/Pause">
                                <x-ui.icon name="play" class="size-5" x-show="!playing" />
                                <x-ui.icon name="pause" class="size-5" x-show="playing" x-cloak />
                            </button>

                            <div class="group/vol flex items-center gap-2">
                                <button type="button" x-on:click="toggleMute()" class="shrink-0 cursor-pointer" aria-label="Mudo">
                                    <x-ui.icon name="speaker-wave" class="size-5" x-show="!muted && volume > 0" />
                                    <x-ui.icon name="speaker-x-mark" class="size-5" x-show="muted || volume === 0" x-cloak />
                                </button>
                                <div
                                    x-ref="volume"
                                    x-on:pointerdown="setVolumeFromEvent($event)"
                                    x-on:pointermove="$event.buttons === 1 && setVolumeFromEvent($event)"
                                    class="hidden h-1 w-16 cursor-pointer rounded-full bg-white/25 group-hover/vol:block sm:block"
                                >
                                    <div class="h-full rounded-full bg-white" x-bind:style="`width: ${volumePercent}%`"></div>
                                </div>
                            </div>

                            <span class="text-xs tabular-nums text-slate-200">
                                <span x-text="elapsedLabel"></span> / <span x-text="durationLabel"></span>
                            </span>

                            <div class="ml-auto flex items-center gap-1.5">
                                @if ($subtitlesUrl)
                                    <button
                                        type="button"
                                        x-on:click="toggleCaptions()"
                                        x-bind:class="captions ? 'bg-white/20 text-sky-300' : 'text-slate-100'"
                                        class="cursor-pointer rounded-md px-2 py-1 text-xs font-bold transition hover:bg-white/15"
                                        aria-label="Legendas"
                                    >CC</button>
                                @endif

                                <div class="relative" x-show="levels.length" x-on:click.outside="menuOpen = false">
                                    <button
                                        type="button"
                                        x-on:click="menuOpen = !menuOpen"
                                        class="cursor-pointer rounded-md px-2 py-1 text-xs font-semibold text-slate-100 transition hover:bg-white/15"
                                        x-text="qualityLabel"
                                    ></button>

                                    <div
                                        x-show="menuOpen"
                                        x-cloak
                                        x-transition.origin.bottom
                                        class="absolute bottom-full right-0 mb-2 min-w-28 overflow-hidden rounded-lg border border-white/10 bg-slate-900/95 py-1 shadow-xl backdrop-blur"
                                    >
                                        <button
                                            type="button"
                                            x-on:click="selectLevel(-1)"
                                            class="flex w-full cursor-pointer items-center justify-between px-3 py-1.5 text-xs transition hover:bg-white/10"
                                            x-bind:class="selectedLevel === -1 ? 'text-sky-400 font-semibold' : 'text-slate-200'"
                                        >
                                            <span>Auto</span>
                                            <span class="text-[10px] text-slate-500" x-show="selectedLevel === -1" x-text="activeLabel"></span>
                                        </button>
                                        <template x-for="level in levels" x-bind:key="level.index">
                                            <button
                                                type="button"
                                                x-on:click="selectLevel(level.index)"
                                                class="flex w-full cursor-pointer px-3 py-1.5 text-xs transition hover:bg-white/10"
                                                x-bind:class="selectedLevel === level.index ? 'text-sky-400 font-semibold' : 'text-slate-200'"
                                                x-text="level.label"
                                            ></button>
                                        </template>
                                    </div>
                                </div>

                                <button type="button" x-on:click="toggleFullscreen()" class="shrink-0 cursor-pointer" aria-label="Tela cheia">
                                    <x-ui.icon name="arrows-pointing-out" class="size-5" x-show="!fullscreen" />
                                    <x-ui.icon name="arrows-pointing-in" class="size-5" x-show="fullscreen" x-cloak />
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div x-show="!editing" class="mt-3 flex flex-wrap items-center gap-2">
                    <span class="rounded-full bg-emerald-500/15 px-2.5 py-1 text-xs font-semibold text-emerald-700 dark:text-emerald-300">{{ $statusLabel }}</span>
                    @if ($transcriptionLabel)
                        <span @class(['inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold', $transcriptionBadgeClass => true])>
                            @if ($isTranscribing)
                                <x-ui.icon name="loading" class="size-3" />
                            @endif
                            {{ $transcriptionLabel }}
                        </span>
                    @endif
                    @if ($subtitlesUrl)
                        <button
                            type="button"
                            x-data
                            x-on:click="$dispatch('modal-show', { name: 'editar-legenda' })"
                            class="inline-flex cursor-pointer items-center gap-1.5 rounded-full border border-slate-700 px-2.5 py-1 text-xs font-semibold text-slate-300 transition hover:bg-slate-800/60"
                        >
                            <x-ui.icon name="pencil-square" class="size-3.5" />
                            Editar legenda
                        </button>
                    @endif
                    <span class="text-xs text-slate-500">{{ $dateLabel }}</span>
                </div>

                @if ($storyboard && $durationSeconds > 0)
                    <div x-show="editing" x-cloak class="mt-3 flex min-h-0 flex-col gap-3">
                        <div wire:ignore x-data="trimEditor(@js(['storyboard' => $storyboard, 'duration' => $durationSeconds]))">
                            <div
                                x-ref="strip"
                                x-on:pointermove="onDrag($event)"
                                x-on:pointerup="endDrag()"
                                x-on:pointercancel="endDrag()"
                                x-on:click.self="seekFromClick($event)"
                                class="relative flex cursor-pointer touch-none select-none overflow-hidden rounded-xl border border-slate-800 bg-slate-900 max-lg:h-14"
                            >
                                <template x-for="(tileStyle, index) in tiles" x-bind:key="index">
                                    <div class="pointer-events-none min-w-0 flex-1 max-lg:even:hidden" x-bind:style="tileStyle"></div>
                                </template>

                                <div
                                    x-on:pointerdown.stop.prevent="startDrag('window', $event)"
                                    class="absolute inset-y-0 cursor-grab touch-none border-x-2 border-sky-600 bg-sky-500/20 active:cursor-grabbing dark:border-sky-400"
                                    x-bind:style="`left: ${aPercent}%; width: ${bPercent - aPercent}%`"
                                ></div>

                                <button
                                    type="button"
                                    x-on:pointerdown.stop.prevent="startDrag('a', $event)"
                                    class="absolute inset-y-0 w-4 -translate-x-1/2 cursor-ew-resize max-lg:w-8"
                                    x-bind:style="`left: ${aPercent}%`"
                                    aria-label="Início do corte"
                                >
                                    <span class="mx-auto block h-full w-1.5 rounded-full bg-sky-600 dark:bg-sky-400"></span>
                                </button>
                                <button
                                    type="button"
                                    x-on:pointerdown.stop.prevent="startDrag('b', $event)"
                                    class="absolute inset-y-0 w-4 -translate-x-1/2 cursor-ew-resize max-lg:w-8"
                                    x-bind:style="`left: ${bPercent}%`"
                                    aria-label="Fim do corte"
                                >
                                    <span class="mx-auto block h-full w-1.5 rounded-full bg-sky-600 dark:bg-sky-400"></span>
                                </button>
                            </div>

                            <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-2">
                                <label class="flex items-center gap-2 text-xs font-semibold text-slate-400">
                                    Início
                                    <input
                                        type="text"
                                        inputmode="numeric"
                                        x-bind:value="aInput"
                                        x-on:input="$event.target.value = sanitizeTime($event.target.value)"
                                        x-on:change="applyStart($event.target.value); $event.target.value = aInput"
                                        x-on:keydown.arrow-up.prevent="step('a', 1)"
                                        x-on:keydown.arrow-down.prevent="step('a', -1)"
                                        class="w-20 rounded-lg border border-slate-700 bg-slate-950 px-2 py-1.5 text-center text-sm tabular-nums text-slate-200 focus:border-sky-500 focus:outline-none"
                                    />
                                </label>
                                <label class="flex items-center gap-2 text-xs font-semibold text-slate-400">
                                    Fim
                                    <input
                                        type="text"
                                        inputmode="numeric"
                                        x-bind:value="bInput"
                                        x-on:input="$event.target.value = sanitizeTime($event.target.value)"
                                        x-on:change="applyEnd($event.target.value); $event.target.value = bInput"
                                        x-on:keydown.arrow-up.prevent="step('b', 1)"
                                        x-on:keydown.arrow-down.prevent="step('b', -1)"
                                        class="w-20 rounded-lg border border-slate-700 bg-slate-950 px-2 py-1.5 text-center text-sm tabular-nums text-slate-200 focus:border-sky-500 focus:outline-none"
                                    />
                                </label>
                                <span class="text-xs text-slate-500">Duração: <span class="font-semibold tabular-nums text-slate-300" x-text="rangeLabel"></span></span>
                                <x-ui.checkbox x-model="syncPlayer" label="Vídeo segue o corte" />
                                <button
                                    type="button"
                                    x-on:click="addCut()"
                                    x-bind:disabled="saving"
                                    class="ml-auto inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-sky-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-sky-500 disabled:cursor-not-allowed disabled:opacity-50"
                                >
                                    <x-ui.icon name="scissors" class="size-3.5" />
                                    Adicionar corte
                                </button>
                            </div>
                        </div>
                    </div>
                @endif
            </div>

            <aside class="flex flex-col gap-3 lg:min-h-0 lg:overflow-hidden">
                @if ($subtitlesUrl)
                    <div class="rounded-xl border border-slate-800 bg-slate-900/60 p-3">
                        <div class="flex items-center gap-1.5 text-sm font-semibold text-slate-200">
                            <x-ui.icon name="sparkles" class="size-4 text-violet-500 dark:text-violet-400" />
                            Encontre um momento
                        </div>
                        <p class="mt-0.5 text-xs text-slate-500">Descreva o que você procura e a IA vai achar no vídeo</p>
                        <div class="mt-2 flex gap-2">
                            <input
                                type="text"
                                placeholder='"a parte mais engraçada", "quando ficam emocionados"'
                                class="min-w-0 flex-1 rounded-lg border border-slate-700 bg-slate-950 px-2.5 py-1.5 text-xs text-slate-200 placeholder:text-slate-500 focus:border-sky-500 focus:outline-none"
                            />
                            <button
                                type="button"
                                wire:click="suggestAiCuts"
                                class="shrink-0 cursor-pointer rounded-lg bg-gradient-to-r from-sky-600 to-violet-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:from-sky-500 hover:to-violet-500"
                            >
                                Buscar
                            </button>
                        </div>
                    </div>
                @endif

                <div class="flex items-center gap-2">
                    <h2 class="text-base font-bold text-slate-200">Clips</h2>
                    <span class="text-sm text-slate-500">({{ count($cutItems) }})</span>

                    @if ($storyboard && $durationSeconds > 0)
                        <button
                            type="button"
                            x-on:click="editing = !editing"
                            class="ml-auto inline-flex cursor-pointer items-center gap-1.5 rounded-lg border border-slate-700 px-2.5 py-1 text-xs font-semibold text-slate-300 transition hover:bg-slate-800/60"
                        >
                            <x-ui.icon name="scissors" class="size-3.5" />
                            <span x-show="!editing">Criar corte manual</span>
                            <span x-show="editing" x-cloak>Fechar editor</span>
                        </button>
                    @endif
                </div>

                <div class="min-h-0 space-y-2 overflow-y-auto lg:flex-1">
                    @foreach ($cutItems as $cut)
                        <div
                            wire:key="cut-{{ $cut['id'] }}"
                            x-data="cutRow()"
                            x-on:click.outside="confirmingDelete = false"
                            class="rounded-xl border border-slate-800 bg-slate-900/60 p-3"
                        >
                            <div class="flex items-start gap-2">
                                <span class="flex size-6 shrink-0 items-center justify-center rounded-md bg-slate-800 text-xs font-bold text-slate-300">{{ $cut['number'] }}</span>
                                <span class="pt-0.5 text-sm font-semibold text-slate-200">{{ $cut['title'] }}</span>

                                @if ($cut['isAi'])
                                    <span class="mt-0.5 inline-flex items-center gap-1 rounded-full bg-violet-500/15 px-1.5 py-0.5 text-[10px] font-semibold text-violet-700 dark:text-violet-300">
                                        <x-ui.icon name="sparkles" class="size-2.5" />
                                        IA
                                    </span>
                                @endif

                                <button
                                    type="button"
                                    x-on:click="playRange({{ $cut['start'] }}, {{ $cut['end'] }})"
                                    class="ml-auto shrink-0 cursor-pointer rounded-md p-1 text-slate-400 transition hover:bg-slate-800 hover:text-sky-600 dark:hover:text-sky-300"
                                    aria-label="Tocar corte"
                                >
                                    <x-ui.icon name="play" class="size-4" />
                                </button>
                            </div>

                            <div x-show="!editingRange" class="mt-2 flex items-center gap-2">
                                <button
                                    type="button"
                                    data-start="{{ $cut['startLabel'] }}"
                                    data-end="{{ $cut['endLabel'] }}"
                                    x-on:click="start = $el.dataset.start; end = $el.dataset.end; editingRange = true"
                                    class="inline-flex cursor-pointer items-center gap-2 rounded-lg bg-slate-800/70 px-2.5 py-1 font-mono text-xs text-slate-300 transition hover:bg-slate-800"
                                >
                                    {{ $cut['startLabel'] }}
                                    <span class="text-slate-500">→</span>
                                    {{ $cut['endLabel'] }}
                                    <span class="text-slate-500">({{ $cut['durationShort'] }})</span>
                                </button>

                                @if ($cut['isGenerating'])
                                    <span @class(['inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold', $cut['badgeClass'] => true])>
                                        <x-ui.icon name="loading" class="size-2.5" />
                                        {{ $cut['statusLabel'] }}
                                    </span>
                                @elseif ($cut['isFailed'])
                                    <span @class(['inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-semibold', $cut['badgeClass'] => true])>{{ $cut['statusLabel'] }}</span>
                                @endif
                            </div>

                            <div x-show="editingRange" x-cloak class="mt-2 flex items-center gap-2 rounded-lg border border-sky-500/50 bg-slate-950 px-2.5 py-1.5">
                                <input
                                    type="text"
                                    inputmode="numeric"
                                    x-model="start"
                                    x-on:input="sanitize('start')"
                                    x-on:keydown.arrow-up.prevent="step('start', 1)"
                                    x-on:keydown.arrow-down.prevent="step('start', -1)"
                                    class="w-12 border-b border-sky-500/40 bg-transparent text-center font-mono text-xs text-sky-600 focus:outline-none dark:text-sky-300"
                                />
                                <span class="text-slate-500">→</span>
                                <input
                                    type="text"
                                    inputmode="numeric"
                                    x-model="end"
                                    x-on:input="sanitize('end')"
                                    x-on:keydown.arrow-up.prevent="step('end', 1)"
                                    x-on:keydown.arrow-down.prevent="step('end', -1)"
                                    class="w-12 border-b border-sky-500/40 bg-transparent text-center font-mono text-xs text-sky-600 focus:outline-none dark:text-sky-300"
                                />
                                <span class="font-mono text-[11px] text-slate-500">{{ $cut['durationShort'] }}</span>
                                <button
                                    type="button"
                                    x-on:click="$wire.updateCut({{ $cut['id'] }}, start, end); editingRange = false"
                                    class="ml-auto cursor-pointer rounded-md bg-sky-600 px-2 py-1 text-xs font-bold text-white transition hover:bg-sky-500"
                                >
                                    Salvar
                                </button>
                            </div>

                            <div class="mt-2 flex items-center gap-2">
                                @if ($cut['isReady'])
                                    <button
                                        type="button"
                                        x-on:click="playRange({{ $cut['start'] }}, {{ $cut['end'] }})"
                                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-slate-800 px-2.5 py-1.5 text-xs font-semibold text-slate-200 transition hover:bg-slate-700"
                                    >
                                        Clip Horizontal
                                        <x-ui.icon name="computer-desktop" class="size-3.5" />
                                    </button>
                                @else
                                    <button
                                        type="button"
                                        wire:click="generateCut({{ $cut['id'] }})"
                                        @disabled($cut['isGenerating'])
                                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-slate-800 px-2.5 py-1.5 text-xs font-semibold text-slate-200 transition hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        Clip Horizontal
                                        <x-ui.icon name="computer-desktop" class="size-3.5" />
                                    </button>
                                @endif

                                @if ($cut['editorUrl'] !== null)
                                    <a
                                        href="{{ $cut['editorUrl'] }}"
                                        wire:navigate
                                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-slate-800 px-2.5 py-1.5 text-xs font-semibold text-slate-200 transition hover:bg-slate-700"
                                    >
                                        Clip Vertical
                                        <x-ui.icon name="device-phone-mobile" class="size-3.5" />
                                    </a>
                                @else
                                    <button
                                        type="button"
                                        wire:click="generateCut({{ $cut['id'] }})"
                                        @disabled($cut['isGenerating'])
                                        class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg bg-slate-800 px-2.5 py-1.5 text-xs font-semibold text-slate-200 transition hover:bg-slate-700 disabled:cursor-not-allowed disabled:opacity-50"
                                    >
                                        Clip Vertical
                                        <x-ui.icon name="device-phone-mobile" class="size-3.5" />
                                    </button>
                                @endif

                                <button
                                    type="button"
                                    x-show="!confirmingDelete"
                                    x-on:click="confirmingDelete = true"
                                    class="ml-auto shrink-0 cursor-pointer rounded-md p-1 text-slate-500 transition hover:bg-slate-800 hover:text-red-500 dark:hover:text-red-400"
                                    aria-label="Remover corte"
                                >
                                    <x-ui.icon name="x-mark" class="size-4" />
                                </button>
                                <button
                                    type="button"
                                    x-show="confirmingDelete"
                                    x-cloak
                                    wire:click="removeCut({{ $cut['id'] }})"
                                    class="ml-auto shrink-0 cursor-pointer rounded-md bg-red-500/15 px-2 py-1 text-xs font-semibold text-red-600 transition hover:bg-red-500/25 dark:text-red-400"
                                >
                                    Apagar?
                                </button>
                            </div>
                        </div>
                    @endforeach
                </div>
            </aside>
        </div>

        @if ($subtitlesUrl)
            <x-ui.modal name="editar-legenda" max-width="max-w-3xl">
                <div wire:ignore x-data="subtitleEditor(@js($transcriptSegments))" class="flex max-h-[78vh] flex-col">
                    <div class="shrink-0">
                        <h2 class="text-base font-semibold text-slate-100">Editar legenda</h2>
                        <div class="mt-2 rounded-lg border border-sky-500/25 bg-sky-500/5 px-3 py-2 text-xs leading-relaxed text-sky-700 dark:text-sky-200/90">
                            Marque os segmentos que quiser corrigir e edite o texto. O horário à direita mostra onde o trecho aparece no vídeo. Não é possível apagar segmentos — só corrigir o texto, e ele não pode ficar vazio.
                        </div>
                        <input
                            type="search"
                            x-model="search"
                            placeholder="Buscar no texto…"
                            class="mt-3 w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 text-sm text-slate-200 placeholder:text-slate-500 focus:border-sky-500 focus:outline-none"
                        />
                    </div>

                    <div x-ref="list" class="mt-3 min-h-0 flex-1 space-y-1.5 overflow-y-auto pr-1">
                        <template x-for="seg in segments" :key="seg.i">
                            <div x-show="visible(seg)" class="flex items-start gap-2 rounded-lg border border-slate-800 bg-slate-900/40 p-2">
                                <input
                                    type="checkbox"
                                    x-model="seg.editing"
                                    class="mt-1.5 size-4 shrink-0 cursor-pointer rounded border-slate-600 bg-slate-950 text-sky-500 focus:ring-sky-500"
                                />
                                <input
                                    type="text"
                                    x-model="seg.text"
                                    :disabled="!seg.editing"
                                    :class="seg.editing && seg.text.trim() === '' ? 'border-red-500/60' : 'border-slate-700'"
                                    class="min-w-0 flex-1 rounded-md border bg-slate-950 px-2 py-1.5 text-sm text-slate-200 focus:border-sky-500 focus:outline-none disabled:cursor-not-allowed disabled:text-slate-500"
                                />
                                <span class="mt-1.5 shrink-0 text-xs tabular-nums text-slate-500" x-text="timecode(seg.start)"></span>
                            </div>
                        </template>
                    </div>

                    <div class="mt-3 flex shrink-0 flex-wrap items-center justify-between gap-3 border-t border-slate-800 pt-3">
                        <span class="text-xs text-slate-500"><span x-text="editedCount"></span> marcado(s) para editar</span>
                        <div class="flex flex-wrap items-center gap-2">
                            <div class="flex items-center gap-1 rounded-lg border border-slate-800 p-0.5">
                                <button type="button" x-on:click="$refs.list.scrollTop = 0" class="cursor-pointer rounded-md px-2 py-1 text-[11px] font-semibold text-slate-400 transition hover:bg-slate-800 hover:text-slate-200">Topo</button>
                                <button type="button" x-on:click="$refs.list.scrollTop = $refs.list.scrollHeight / 2" class="cursor-pointer rounded-md px-2 py-1 text-[11px] font-semibold text-slate-400 transition hover:bg-slate-800 hover:text-slate-200">Meio</button>
                                <button type="button" x-on:click="$refs.list.scrollTop = $refs.list.scrollHeight" class="cursor-pointer rounded-md px-2 py-1 text-[11px] font-semibold text-slate-400 transition hover:bg-slate-800 hover:text-slate-200">Fim</button>
                            </div>
                            <button type="button" x-on:click="$dispatch('modal-close', { name: 'editar-legenda' })" class="cursor-pointer rounded-lg px-3 py-1.5 text-xs font-semibold text-slate-300 transition hover:bg-slate-800">Cancelar</button>
                            <button
                                type="button"
                                x-on:click="save()"
                                :disabled="!canSave"
                                class="cursor-pointer rounded-lg bg-sky-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-sky-500 disabled:cursor-not-allowed disabled:opacity-50"
                            >
                                <span x-show="!saving">Salvar legenda</span>
                                <span x-show="saving" x-cloak class="inline-flex items-center gap-1.5"><x-ui.icon name="loading" class="size-3.5" />Salvando…</span>
                            </button>
                        </div>
                    </div>
                </div>
            </x-ui.modal>
        @endif
    @elseif ($isPackaging)
        <div class="rounded-2xl border border-amber-500/30 bg-amber-500/5 px-8 py-16 text-center">
            <x-ui.icon name="cog-6-tooth" class="mx-auto size-9 animate-spin text-amber-500 dark:text-amber-400" />
            <div class="mt-4 text-sm font-semibold text-amber-700 dark:text-amber-200">Preparando reprodução — {{ $progress }}%</div>
            <div class="mt-1 text-xs text-amber-600/90 dark:text-amber-400/70">Vídeos longos podem levar horas. Pode fechar a página.</div>

            <div class="mx-auto mt-5 h-2 w-full max-w-sm overflow-hidden rounded-full bg-slate-800">
                <div class="h-full rounded-full bg-amber-500 transition-all" style="width: {{ $progress }}%"></div>
            </div>
        </div>
    @else
        <div class="rounded-2xl border border-red-500/30 bg-red-500/5 px-8 py-16 text-center">
            <x-ui.icon name="exclamation-triangle" class="mx-auto size-9 text-red-500 dark:text-red-400" />
            <div class="mt-4 text-sm font-semibold text-red-700 dark:text-red-200">{{ $statusLabel }}</div>
            @if ($error)
                <div class="mt-2 text-xs text-red-600/90 dark:text-red-400/80">{{ $error }}</div>
            @endif
        </div>
    @endif
</div>
