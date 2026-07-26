<div>
    <div wire:ignore
        x-data="reframeEditor(@js($editorPayload))"
        class="grid items-start gap-4 lg:grid-cols-[minmax(0,1fr)_380px]">

        <div class="flex min-w-0 flex-col gap-4">
            <div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
                <div class="mb-3 flex flex-wrap items-center gap-3">
                    <a href="{{ $backUrl }}" wire:navigate aria-label="Voltar"
                        class="flex size-9 shrink-0 cursor-pointer items-center justify-center rounded-lg bg-slate-800 text-slate-300 transition hover:bg-slate-700">
                        <x-ui.icon name="chevron-right" class="size-4 rotate-180" />
                    </a>

                    <div class="min-w-0">
                        <div class="text-[15px] font-bold text-slate-100">Posicione o Crop</div>
                        <p class="mt-0.5 text-xs text-slate-500">Clique ou arraste pra posicionar. Arraste as bordas pra redimensionar.</p>
                    </div>

                    <div class="ml-auto flex items-center gap-1 rounded-lg bg-slate-950 p-1">
                        <template x-for="option in modeOptions()" :key="option.value">
                            <button type="button" x-on:click="setMode(option.value)" x-text="option.label"
                                class="cursor-pointer rounded-md px-2.5 py-1 text-[12px] font-semibold transition"
                                :class="mode === option.value ? 'bg-sky-600 text-white' : 'text-slate-400 hover:text-slate-200'"></button>
                        </template>
                    </div>

                    <div class="flex items-center gap-2" x-show="isContain()" x-cloak>
                        <span class="text-[12px] font-semibold text-slate-300">Cor das barras</span>
                        <input type="color" x-model="settings.background" x-on:input="markDirty()"
                            class="h-7 w-12 cursor-pointer rounded-md border border-slate-700 bg-slate-950" />
                    </div>
                </div>

                <div x-ref="stage" class="relative mx-auto w-full max-w-[900px] overflow-hidden rounded-xl bg-black" style="aspect-ratio: 16 / 9">
                    <video x-ref="video" muted playsinline preload="auto" crossorigin="anonymous"
                        class="absolute inset-0 h-full w-full"></video>

                    <div x-ref="overlay" class="absolute inset-0"
                        x-on:click.self="positionActiveRegion($event)"
                        x-on:pointermove="onPointerMove($event)"
                        x-on:pointerup="onPointerUp($event)"
                        x-on:pointercancel="onPointerUp($event)">
                        <template x-for="(slot, i) in modeSlots()" :key="`${mode}-${i}`">
                            <div data-region-box style="touch-action: none"
                                class="absolute border-2"
                                :class="regionBoxClass(i)"
                                x-on:pointerdown="setActiveRegion(i); onPointerDown($event, 'move')">
                                <span class="absolute left-1.5 top-1.5 rounded bg-black/70 px-1.5 py-0.5 text-[11px] font-semibold"
                                    :class="regionTextClass(i)" x-text="regionLabel(i)"></span>
                                <template x-if="i === activeRegion">
                                    <div>
                                        <span class="absolute right-1.5 top-1.5 rounded bg-black/70 px-1.5 py-0.5 font-mono text-[11px] text-white"
                                            x-text="regionScale(i)"></span>

                                        <div data-handle="n" style="touch-action: none" x-on:pointerdown.stop="onPointerDown($event, 'n')"
                                            class="absolute -top-2 left-1/2 flex size-5 -translate-x-1/2 cursor-ns-resize items-center justify-center">
                                            <div class="size-3 rounded-full border border-slate-950 transition-transform hover:scale-125" :class="regionHandleClass(i)"></div>
                                        </div>
                                        <div data-handle="w" style="touch-action: none" x-on:pointerdown.stop="onPointerDown($event, 'w')"
                                            class="absolute -left-2 top-1/2 flex size-5 -translate-y-1/2 cursor-ew-resize items-center justify-center">
                                            <div class="size-3 rounded-full border border-slate-950 transition-transform hover:scale-125" :class="regionHandleClass(i)"></div>
                                        </div>
                                        <div data-handle="e" style="touch-action: none" x-on:pointerdown.stop="onPointerDown($event, 'e')"
                                            class="absolute -right-2 top-1/2 flex size-5 -translate-y-1/2 cursor-ew-resize items-center justify-center">
                                            <div class="size-3 rounded-full border border-slate-950 transition-transform hover:scale-125" :class="regionHandleClass(i)"></div>
                                        </div>

                                        <div style="touch-action: none" x-on:pointerdown.stop="onPointerDown($event, 'adjust')"
                                            class="absolute bottom-3 left-1/2 -translate-x-1/2 cursor-ew-resize">
                                            <div class="flex items-center gap-1.5 rounded-lg border border-cyan-500/60 bg-black/80 px-3.5 py-1.5 text-sm font-bold text-white shadow-lg">
                                                ↔ Ajustar
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>

                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <button type="button" x-on:click="stepFrame(-1)"
                        class="flex size-8 cursor-pointer items-center justify-center rounded-lg border border-slate-700 text-slate-300 transition hover:bg-slate-800">
                        <x-ui.icon name="chevron-right" class="size-4 rotate-180" />
                    </button>
                    <button type="button" x-on:click="togglePlay()"
                        class="flex size-9 cursor-pointer items-center justify-center rounded-lg bg-sky-600 text-white transition hover:bg-sky-500">
                        <span x-show="!playing"><x-ui.icon name="play" class="size-4" /></span>
                        <span x-show="playing" x-cloak><x-ui.icon name="pause" class="size-4" /></span>
                    </button>
                    <button type="button" x-on:click="stepFrame(1)"
                        class="flex size-8 cursor-pointer items-center justify-center rounded-lg border border-slate-700 text-slate-300 transition hover:bg-slate-800">
                        <x-ui.icon name="chevron-right" class="size-4" />
                    </button>

                    <div class="mx-1 h-4 w-px bg-slate-700"></div>

                    <button type="button" x-on:click="undo()" x-bind:disabled="!canUndo()" aria-label="Desfazer"
                        class="flex size-8 cursor-pointer items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-800 disabled:pointer-events-none disabled:opacity-40">
                        <x-ui.icon name="arrow-path" class="size-4 -scale-x-100" />
                    </button>
                    <button type="button" x-on:click="redo()" x-bind:disabled="!canRedo()" aria-label="Refazer"
                        class="flex size-8 cursor-pointer items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-800 disabled:pointer-events-none disabled:opacity-40">
                        <x-ui.icon name="arrow-path" class="size-4" />
                    </button>

                    <button type="button" x-on:click="cycleSpeed()"
                        class="cursor-pointer rounded-lg border border-slate-700 px-2 py-1 font-mono text-xs font-semibold text-slate-300 transition hover:bg-slate-800"
                        x-text="`${speed}x`"></button>

                    <div class="flex-1"></div>

                    <div class="flex items-center gap-2" x-show="modeSlots().length > 1" x-cloak>
                        <span class="text-[12px] font-semibold text-slate-500">Região:</span>
                        <template x-for="tab in regionTabs()" :key="tab.i">
                            <button type="button" x-on:click="setActiveRegion(tab.i)" x-text="tab.label"
                                class="cursor-pointer rounded-lg border px-2.5 py-1 text-[12px] font-semibold transition"
                                :class="activeRegion === tab.i ? 'border-sky-400 bg-sky-400/10 text-sky-700 dark:text-sky-300' : 'border-slate-700 text-slate-400 hover:border-slate-500'"></button>
                        </template>
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
                <div class="space-y-1.5">
                    <div class="flex items-center justify-between px-0.5">
                        <div class="flex items-center gap-0.5">
                            <button type="button" x-on:click="zoomOut()" x-bind:disabled="timelineZoom <= 1"
                                class="flex size-7 cursor-pointer items-center justify-center rounded text-xs text-slate-300 transition hover:bg-slate-800 disabled:pointer-events-none disabled:opacity-40">−</button>
                            <span class="w-8 text-center font-mono text-[11px] text-slate-400" x-text="`${timelineZoom}x`"></span>
                            <button type="button" x-on:click="zoomIn()" x-bind:disabled="timelineZoom >= 4"
                                class="flex size-7 cursor-pointer items-center justify-center rounded text-xs text-slate-300 transition hover:bg-slate-800 disabled:pointer-events-none disabled:opacity-40">+</button>

                            <div class="mx-1.5 h-4 w-px bg-slate-700"></div>

                            <button type="button" x-on:click="addKeyframeAtCurrentTime()"
                                class="flex h-7 cursor-pointer items-center gap-1.5 rounded px-2 text-xs font-medium text-slate-400 transition hover:bg-slate-800 hover:text-slate-300">
                                <x-ui.icon name="scissors" class="size-3.5" />
                                Cortar
                            </button>
                        </div>
                        <span class="font-mono text-[11px] tabular-nums text-slate-400" x-text="timeLabel()"></span>
                    </div>

                    <div class="overflow-x-auto overflow-y-hidden rounded-lg border border-slate-800 bg-slate-950">
                        <div x-ref="timeline" class="relative mx-3 h-[106px] select-none"
                            style="touch-action: none; cursor: crosshair"
                            :style="`width: calc(${timelineZoom * 100}% - 24px)`"
                            x-on:pointerdown="onTimelinePointerDown($event)"
                            x-on:pointermove="onTimelinePointerMove($event)"
                            x-on:pointerup="onTimelinePointerUp($event)"
                            x-on:pointercancel="onTimelinePointerUp($event)">

                            <div class="absolute inset-x-0 top-0 h-8 border-b border-slate-800/40 bg-slate-900/40">
                                <template x-for="(tick, tickIndex) in timelineTicks()" :key="`tick-${tickIndex}`">
                                    <div class="absolute bottom-0" :style="`left: ${tick.pct}%`">
                                        <div class="w-px" :class="tick.label !== null ? 'h-4 bg-slate-500/60' : 'h-2 bg-slate-600/30'"></div>
                                        <template x-if="tick.label !== null">
                                            <span class="absolute bottom-[14px] -translate-x-1/2 whitespace-nowrap font-mono text-[9px] text-slate-500" x-text="tick.label"></span>
                                        </template>
                                    </div>
                                </template>
                            </div>

                            <div class="absolute inset-x-0" style="top: 32px; height: 10px">
                                <template x-for="segment in cropSegments()" :key="`strip-${segment.i}`">
                                    <div class="absolute h-full border-b" :class="segment.color.strip"
                                        :style="`left: ${segment.leftPct}%; width: ${segment.widthPct}%`"></div>
                                </template>
                            </div>

                            <div class="absolute inset-x-0 overflow-hidden bg-slate-900/30" style="top: 42px; height: 64px">
                                <template x-for="(thumb, thumbIndex) in thumbs" :key="`thumb-${thumbIndex}`">
                                    <img draggable="false" decoding="async" :src="thumb"
                                        class="pointer-events-none absolute top-0 h-16 w-9 rounded-[2px] object-cover"
                                        :style="`left: ${(thumbIndex / thumbs.length) * 100}%`" />
                                </template>
                            </div>

                            <div class="pointer-events-none absolute inset-x-0" style="top: 42px; height: 64px">
                                <template x-for="segment in cropSegments()" :key="`tint-${segment.i}`">
                                    <div class="absolute h-full border-y" :class="segment.color.tint"
                                        :style="`left: ${segment.leftPct}%; width: ${segment.widthPct}%`"></div>
                                </template>
                            </div>

                            <template x-for="segment in cropSegments()" :key="`kf-${segment.i}`">
                                <div class="group absolute z-10 -translate-x-1/2" style="top: 36px" :style="`left: ${segment.leftPct}%`">
                                    <div class="flex size-6 cursor-grab touch-none items-center justify-center"
                                        x-on:pointerdown.stop="startKeyframeDrag(segment.i, $event)">
                                        <div class="size-3 rotate-45 border-2 border-slate-950 shadow-sm transition-transform group-hover:scale-125"
                                            :class="[segment.color.dot, selectedKf === segment.i ? 'scale-125 ' + segment.color.ring : '']"></div>
                                    </div>
                                    <div class="pointer-events-none absolute -top-4 left-1/2 -translate-x-1/2 whitespace-nowrap rounded bg-black/80 px-1 py-px font-mono text-[9px] transition-opacity"
                                        :class="[segment.color.text, selectedKf === segment.i ? 'opacity-100' : 'opacity-0 group-hover:opacity-100']"
                                        x-text="segment.startLabel"></div>
                                </div>
                            </template>

                            <div class="pointer-events-none absolute inset-y-0 z-20" :style="`left: ${duration ? (currentTime / duration) * 100 : 0}%`">
                                <svg width="10" height="8" viewBox="0 0 10 8" class="-translate-x-[5px] fill-cyan-500 drop-shadow"><path d="M0 0L10 0L6 8H4Z"></path></svg>
                                <div class="absolute left-0 top-0 h-full w-0.5 -translate-x-px bg-cyan-500"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
                <div class="flex items-center justify-between">
                    <div class="text-[13.5px] font-bold text-slate-200">Posições do Crop</div>
                    <span class="rounded-md bg-slate-800 px-1.5 py-0.5 text-xs font-semibold text-slate-400" x-text="keyframes.length"></span>
                </div>

                <div class="mt-2 max-h-56 space-y-0.5 overflow-y-auto">
                    <template x-for="segment in cropSegments()" :key="`crop-${segment.i}`">
                        <div class="group relative flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 pb-2.5 transition hover:bg-slate-800/60"
                            :class="selectedKf === segment.i ? 'bg-sky-500/10 ring-1 ring-inset ring-sky-500/60' : ''"
                            x-on:click="selectKeyframe(segment.i)">
                            <span class="size-2 shrink-0 rounded-full" :class="segment.color.dot"></span>
                            <span class="font-mono text-xs font-bold text-slate-100" x-text="segment.startLabel"></span>
                            <span class="font-mono text-xs text-slate-500" x-text="`— ${segment.endLabel}`"></span>
                            <span class="text-[11px] text-slate-500" x-text="segment.modeLabel"></span>
                            <button type="button" x-on:click.stop="deleteKeyframeAt(segment.i)" aria-label="Remover posição"
                                class="ml-auto cursor-pointer rounded-md p-1 text-slate-500 opacity-0 transition hover:text-red-500 group-hover:opacity-100 dark:hover:text-red-400">
                                <x-ui.icon name="trash" class="size-3.5" />
                            </button>
                            <div class="pointer-events-none absolute inset-x-2 bottom-1 h-0.5 overflow-hidden rounded-full bg-slate-800">
                                <div class="h-full rounded-full bg-sky-500"
                                    :style="`width: ${Math.min(100, Math.max(0, ((currentTime - segment.startSec) / Math.max(0.001, segment.endSec - segment.startSec)) * 100))}%`"></div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

        </div>

        <div class="flex flex-col gap-4 lg:sticky lg:top-4">
            <div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
                <div class="text-[13.5px] font-bold text-slate-200">Prévia (9:16)</div>
                <canvas x-ref="canvas" width="1080" height="1920" class="mx-auto mt-3 h-auto w-full max-w-[320px] max-h-[480px] rounded-xl bg-black"></canvas>
            </div>

            <div class="rounded-xl border border-slate-800 bg-slate-900 p-4">
                <button type="button" x-on:click="settings.captions = !settings.captions; markDirty()"
                    class="flex w-full cursor-pointer items-center justify-between">
                    <span class="text-sm font-medium transition-colors" :class="settings.captions ? 'text-slate-100' : 'text-slate-400'">Legendas automáticas</span>
                    <span class="h-5 w-9 shrink-0 rounded-full p-0.5 transition-colors" :class="settings.captions ? 'bg-cyan-600' : 'bg-slate-700'">
                        <span class="block size-4 rounded-full bg-white shadow-sm transition-transform" :class="settings.captions ? 'translate-x-4' : ''"></span>
                    </span>
                </button>

                <div x-show="settings.captions" x-cloak>
                    <div class="pt-4">
                        <div class="rounded-lg border border-slate-800/80 bg-black/85 px-4 py-5">
                            <p class="text-center text-base font-bold leading-relaxed tracking-wide" :style="`color: ${settings.captionColor}`">
                                <span x-text="captionCaseText('suas legendas')"></span>
                                <br />
                                <span x-text="captionCaseText('ficam assim')"></span>
                            </p>
                        </div>
                    </div>

                    <div class="space-y-4 pt-4">
                        <div class="space-y-1.5">
                            <span class="block text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-500">Cor</span>
                            <div class="flex flex-wrap items-center gap-2">
                                <template x-for="swatch in ['#ffffff', '#facc15', '#22d3ee', '#4ade80', '#f472b6', '#a78bfa']" :key="swatch">
                                    <button type="button" x-on:click="settings.captionColor = swatch; markDirty()"
                                        class="size-6 cursor-pointer rounded-full border transition-all"
                                        :class="settings.captionColor === swatch ? 'border-cyan-400 ring-2 ring-cyan-400/30' : 'border-slate-700 hover:border-slate-500'"
                                        :style="`background-color: ${swatch}`"></button>
                                </template>
                                <input type="color" x-model="settings.captionColor" x-on:input="markDirty()"
                                    class="h-6 w-9 cursor-pointer rounded-md border border-slate-700 bg-slate-950" />
                            </div>
                        </div>

                        <div class="space-y-1.5">
                            <span class="block text-[10px] font-semibold uppercase tracking-[0.08em] text-slate-500">Capitalização</span>
                            <div class="flex items-center gap-2">
                                <template x-for="option in [{ v: 'sentence', label: 'Aa' }, { v: 'upper', label: 'AA' }, { v: 'lower', label: 'aa' }]" :key="option.v">
                                    <button type="button" x-on:click="settings.captionCase = option.v; markDirty()" x-text="option.label"
                                        class="cursor-pointer rounded-lg border px-3 py-1 text-xs font-semibold transition"
                                        :class="settings.captionCase === option.v ? 'border-cyan-400 bg-cyan-400/10 text-cyan-700 dark:text-cyan-300' : 'border-slate-700 text-slate-400 hover:border-slate-500'"></button>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="flex gap-2">
                <button type="button" x-on:click="save()" x-bind:disabled="saving || !dirty"
                    class="flex flex-1 cursor-pointer items-center justify-center gap-2 rounded-[10px] bg-sky-400 px-4 py-3 text-sm font-bold text-gray-950 transition hover:bg-sky-300 disabled:pointer-events-none disabled:opacity-50">
                    <x-ui.icon name="check" class="size-3.5" />
                    <span x-text="saving ? 'Salvando…' : 'Salvar edição'"></span>
                </button>
                <button type="button" x-on:click="generate()"
                    x-bind:disabled="generating || renderStatus === 'generating' || (editId === null && !dirty)"
                    class="flex flex-1 cursor-pointer items-center justify-center gap-2 rounded-[10px] bg-emerald-400 px-4 py-3 text-sm font-bold text-gray-950 transition hover:bg-emerald-300 disabled:pointer-events-none disabled:opacity-50">
                    <x-ui.icon name="scissors" class="size-3.5" />
                    <span x-text="generating || renderStatus === 'generating' ? 'Gerando…' : (renderStatus === 'ready' ? 'Gerar novamente' : 'Gerar corte editado')"></span>
                </button>
            </div>
            <p class="text-center text-[11.5px] text-amber-600 dark:text-amber-400/80" x-show="dirty" x-cloak>Alterações não salvas</p>
        </div>
    </div>
</div>
