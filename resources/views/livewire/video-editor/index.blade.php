<div>
    <div class="flex flex-wrap items-center justify-between gap-4">
        <div>
            <h1 class="text-lg font-bold text-slate-50">Estúdio de cortes</h1>
            <p class="mt-0.5 text-[13px] text-slate-500">
                Reenquadre o vídeo pro formato 9:16 com keyframes — a prévia roda no navegador; nada é processado no servidor durante a edição.
            </p>
        </div>
        @if ($video !== null)
            <x-ui.button wire:click="clearSource" icon="arrow-path" size="sm">Trocar vídeo</x-ui.button>
        @endif
    </div>

    @if ($video === null)
        <div class="mt-6">
            <div class="text-[13.5px] font-bold text-slate-200">Escolha o vídeo fonte</div>
            <div class="mb-3 mt-0.5 text-xs text-slate-500">Vídeos do estoque com arquivo no MinIO. O upload direto chega em uma fase futura.</div>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6">
                @forelse ($sources as $source)
                    <button type="button" wire:click="selectSource({{ $source['id'] }})" wire:key="reframe-src-{{ $source['id'] }}"
                        class="cursor-pointer rounded-xl border-2 border-transparent bg-slate-900 p-3 text-left transition hover:border-sky-400/60">
                        <div class="flex aspect-video items-center justify-center rounded-lg bg-slate-800">
                            <x-ui.icon name="play" class="size-5 text-slate-500" />
                        </div>
                        <div class="mt-2 line-clamp-2 text-[12px] leading-tight text-slate-300">{{ $source['title'] }}</div>
                    </button>
                @empty
                    <div class="col-span-full rounded-xl border border-dashed border-slate-700 px-6 py-10 text-center text-[13px] text-slate-500">
                        Sem vídeos no estoque — baixe um canal em Meus vídeos primeiro.
                    </div>
                @endforelse
            </div>
        </div>
    @else
        <div class="mt-2 text-[13px] text-slate-400">Editando: <span class="font-semibold text-slate-200">{{ $video['title'] }}</span></div>

        <div wire:key="reframe-editor-{{ $video['id'] }}" wire:ignore
            x-data="reframeEditor(@js($editorPayload))"
            class="mt-4 grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_300px]">

            <div class="flex min-w-0 flex-col gap-4">
                <div class="rounded-2xl border border-slate-800 bg-slate-900 p-4">
                    <div x-ref="stage" class="relative mx-auto w-full max-w-[760px] overflow-hidden rounded-xl bg-black" style="aspect-ratio: 16 / 9">
                        <video x-ref="video" muted playsinline preload="auto" crossorigin="anonymous"
                            class="absolute inset-0 h-full w-full"></video>

                        <div x-ref="overlay" class="absolute inset-0"
                            x-on:pointermove="onPointerMove($event)"
                            x-on:pointerup="onPointerUp($event)"
                            x-on:pointercancel="onPointerUp($event)">
                            <template x-for="(slot, i) in modeSlots()" :key="`${mode}-${i}`">
                                <div data-region-box style="touch-action: none"
                                    class="absolute border-2"
                                    :class="i === activeRegion ? 'cursor-move border-sky-400 bg-sky-400/10' : 'cursor-pointer border-white/40'"
                                    x-on:pointerdown="setActiveRegion(i); onPointerDown($event, 'move')">
                                    <template x-if="i === activeRegion">
                                        <div>
                                            <div data-handle="nw" style="touch-action: none" x-on:pointerdown.stop="onPointerDown($event, 'nw')"
                                                class="absolute -left-1.5 -top-1.5 size-3 cursor-nwse-resize rounded-full border border-slate-950 bg-sky-400"></div>
                                            <div data-handle="ne" style="touch-action: none" x-on:pointerdown.stop="onPointerDown($event, 'ne')"
                                                class="absolute -right-1.5 -top-1.5 size-3 cursor-nesw-resize rounded-full border border-slate-950 bg-sky-400"></div>
                                            <div data-handle="sw" style="touch-action: none" x-on:pointerdown.stop="onPointerDown($event, 'sw')"
                                                class="absolute -bottom-1.5 -left-1.5 size-3 cursor-nesw-resize rounded-full border border-slate-950 bg-sky-400"></div>
                                            <div data-handle="se" style="touch-action: none" x-on:pointerdown.stop="onPointerDown($event, 'se')"
                                                class="absolute -bottom-1.5 -right-1.5 size-3 cursor-nwse-resize rounded-full border border-slate-950 bg-sky-400"></div>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>

                    <div class="mt-3 flex items-center gap-2" x-show="modeSlots().length > 1" x-cloak>
                        <span class="text-[12px] font-semibold text-slate-500">Região:</span>
                        <template x-for="tab in regionTabs()" :key="tab.i">
                            <button type="button" x-on:click="setActiveRegion(tab.i)" x-text="tab.label"
                                class="cursor-pointer rounded-lg border px-2.5 py-1 text-[12px] font-semibold transition"
                                :class="activeRegion === tab.i ? 'border-sky-400 bg-sky-400/10 text-sky-300' : 'border-slate-700 text-slate-400 hover:border-slate-500'"></button>
                        </template>
                    </div>
                </div>

                <div class="rounded-2xl border border-slate-800 bg-slate-900 p-4">
                    <div class="flex flex-wrap items-center gap-3">
                        <button type="button" x-on:click="togglePlay()"
                            class="flex size-9 cursor-pointer items-center justify-center rounded-full bg-slate-100 text-slate-950 transition hover:bg-slate-50">
                            <span x-show="!playing"><x-ui.icon name="play" class="size-4" /></span>
                            <span x-show="playing" x-cloak><x-ui.icon name="pause" class="size-4" /></span>
                        </button>
                        <span class="font-mono text-xs text-slate-400" x-text="timeLabel()"></span>

                        <div class="flex-1"></div>

                        <x-ui.button size="sm" icon="plus" x-on:click="addKeyframeAtCurrentTime()">Keyframe</x-ui.button>
                        <x-ui.button size="sm" icon="x-mark" x-on:click="deleteSelectedKeyframe()"
                            x-bind:disabled="selectedKf === null || keyframes.length <= 1">Excluir</x-ui.button>
                    </div>

                    <div x-ref="ruler" x-on:click="seekFromRuler($event)"
                        class="relative mt-4 h-10 cursor-pointer rounded-lg border border-slate-800 bg-slate-950">
                        <div x-ref="playhead" class="pointer-events-none absolute top-0 h-full w-px bg-sky-400"></div>
                        <template x-for="(kf, i) in keyframes" :key="`${kf.t}-${i}`">
                            <button type="button" x-on:click.stop="selectKeyframe(i)"
                                class="absolute top-1/2 size-3 -translate-x-1/2 -translate-y-1/2 cursor-pointer rounded-full transition"
                                :class="selectedKf === i ? 'bg-sky-400 ring-2 ring-sky-300/60' : 'bg-slate-500 hover:bg-slate-300'"
                                :style="`left: ${duration ? (kf.t / duration) * 100 : 0}%`"></button>
                        </template>
                    </div>
                    <div class="mt-2 text-[11.5px] text-slate-500">
                        Clique na régua para navegar; arraste a caixa sobre o vídeo para criar um keyframe no tempo atual. A prévia interpola entre keyframes.
                    </div>
                </div>
            </div>

            <div class="flex flex-col gap-4 lg:sticky lg:top-24">
                <div class="rounded-2xl border border-slate-800 bg-slate-900 p-4">
                    <div class="text-[13.5px] font-bold text-slate-200">Saída 9:16</div>
                    <canvas x-ref="canvas" width="1080" height="1920" class="mx-auto mt-3 w-[220px] rounded-xl bg-black"></canvas>
                </div>

                <div class="rounded-2xl border border-slate-800 bg-slate-900 p-4">
                    <div class="text-[13.5px] font-bold text-slate-200">Modo de enquadramento</div>
                    <div class="mt-3 grid grid-cols-2 gap-2">
                        <template x-for="option in modeOptions()" :key="option.value">
                            <button type="button" x-on:click="setMode(option.value)" x-text="option.label"
                                class="cursor-pointer rounded-xl border-2 bg-slate-950 px-3 py-2 text-[12.5px] font-semibold transition"
                                :class="mode === option.value ? 'border-sky-400 text-slate-50' : 'border-slate-700 text-slate-400 hover:border-sky-400/50'"></button>
                        </template>
                    </div>
                    <div class="mt-3 flex items-center justify-between gap-2" x-show="isContain()" x-cloak>
                        <span class="text-[12.5px] font-semibold text-slate-300">Cor das barras</span>
                        <input type="color" x-model="settings.background" x-on:input="markDirty()"
                            class="h-8 w-14 cursor-pointer rounded-md border border-slate-700 bg-slate-950" />
                    </div>
                </div>

                <button type="button" x-on:click="save()" x-bind:disabled="saving || !dirty"
                    class="flex cursor-pointer items-center justify-center gap-2 rounded-[10px] bg-sky-400 px-5 py-3 text-sm font-bold text-gray-950 transition hover:bg-sky-300 disabled:pointer-events-none disabled:opacity-50">
                    <x-ui.icon name="check" class="size-3.5" />
                    <span x-text="saving ? 'Salvando…' : 'Salvar edição'"></span>
                </button>
                <p class="text-center text-[11.5px] text-amber-400/80" x-show="dirty" x-cloak>Alterações não salvas</p>
            </div>
        </div>
    @endif
</div>
