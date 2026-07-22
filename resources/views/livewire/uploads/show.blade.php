<div @if ($isPackaging) wire:poll.5s @endif x-data="{ showClips: true }">
    <div class="mb-5 flex items-center justify-between gap-3">
        <x-ui.button variant="subtle" size="sm" href="{{ route('uploads.index') }}">
            <x-ui.icon name="chevron-right" class="size-4 rotate-180" />
            Voltar
        </x-ui.button>

        <div class="flex min-w-0 items-center gap-3">
            <span class="truncate text-xs text-slate-500">{{ $title }}</span>
            @if ($isReady)
                <button
                    type="button"
                    x-on:click="showClips = !showClips"
                    class="flex shrink-0 cursor-pointer items-center gap-1.5 rounded-lg border border-slate-800 px-2.5 py-1.5 text-xs font-semibold text-slate-300 transition hover:bg-slate-800/60"
                >
                    <x-ui.icon name="scissors" class="size-3.5" />
                    <span x-text="showClips ? 'Ocultar cortes' : 'Cortes'"></span>
                </button>
            @endif
        </div>
    </div>

    @if ($isReady)
        <div
            x-data="videoPlayer(@js(['hlsSrc' => $hlsUrl, 'fallbackSrc' => $fallbackUrl ?? '', 'poster' => $posterUrl ?? '', 'storyboard' => $storyboard]))"
            class="grid gap-6"
            x-bind:class="showClips ? 'lg:grid-cols-[minmax(0,1fr)_340px]' : 'lg:grid-cols-1'"
        >
            <div class="min-w-0">
                <div
                    x-ref="wrapper"
                    x-on:pointermove="showControls()"
                    x-on:pointerleave="playing && (controlsVisible = false)"
                    class="group relative select-none overflow-hidden rounded-2xl border border-slate-800 bg-black"
                    x-bind:class="controlsVisible || !playing ? 'cursor-default' : 'cursor-none'"
                >
                    <video
                        x-ref="video"
                        x-on:click="togglePlay()"
                        class="aspect-video w-full"
                        playsinline
                        preload="metadata"
                        @if ($posterUrl) poster="{{ $posterUrl }}" @endif
                    ></video>

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

                <div class="mt-3 flex items-center gap-2">
                    <span class="rounded-full bg-emerald-500/15 px-2.5 py-1 text-xs font-semibold text-emerald-700 dark:text-emerald-300">{{ $statusLabel }}</span>
                    <span class="text-xs text-slate-500">{{ $dateLabel }}</span>
                </div>
            </div>

            <aside class="flex flex-col gap-3" x-show="showClips" x-cloak>
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <h2 class="text-sm font-semibold text-slate-200">Cortes sugeridos</h2>
                        <span class="rounded-full bg-slate-800 px-2 py-0.5 text-xs font-semibold text-slate-400">{{ count($clips) }}</span>
                    </div>
                    <button
                        type="button"
                        x-on:click="showClips = false"
                        class="cursor-pointer rounded-md p-1 text-slate-400 transition hover:bg-slate-800 hover:text-slate-200"
                        aria-label="Ocultar cortes"
                    >
                        <x-ui.icon name="x-mark" class="size-4" />
                    </button>
                </div>

                @foreach ($clips as $clip)
                    <button
                        type="button"
                        x-on:click="seekTo({{ $clip['start'] }})"
                        class="group flex cursor-pointer flex-col gap-2 rounded-xl border border-slate-800 bg-slate-900/60 p-4 text-left transition hover:border-sky-500/50 hover:bg-slate-900"
                    >
                        <span class="line-clamp-1 text-sm font-semibold text-slate-200 group-hover:text-sky-300">{{ $clip['title'] }}</span>
                        <span class="flex items-center gap-2 text-xs text-slate-500">
                            <x-ui.icon name="scissors" class="size-3.5" />
                            {{ $clip['rangeLabel'] }}
                            <span class="text-slate-700">·</span>
                            {{ $clip['durationLabel'] }}
                        </span>
                    </button>
                @endforeach
            </aside>
        </div>
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
