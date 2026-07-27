<div class="mx-auto flex min-h-[calc(100vh-8rem)] w-full max-w-2xl flex-col justify-center">
    <x-ui.heading size="xl">Enviar vídeo</x-ui.heading>
    <x-ui.subheading>Arraste um arquivo, clique para escolher ou importe pelo link do YouTube.</x-ui.subheading>

    <div
        class="mt-8"
        x-data="videoUploader({ maxBytes: @js($maxBytes), accepted: @js($accept), videoUrlBase: @js($videoUrlBase) })"
    >
        <template x-if="state === 'done'">
            <div
                class="flex flex-col items-center gap-4 rounded-2xl border border-emerald-500/40 bg-emerald-500/10 px-8 py-12 text-center">
                <x-ui.icon name="check-circle" class="size-10 text-emerald-400"/>
                <div class="text-lg font-semibold text-emerald-300">Upload concluído</div>
                <div class="text-xs text-emerald-400/80">Estamos preparando a reprodução adaptativa.</div>

                <div class="flex items-center gap-3">
                    <x-ui.button variant="subtle" x-on:click="reset()">Enviar outro</x-ui.button>
                    <x-ui.button variant="primary" :href="$libraryUrl" wire:navigate>Ver biblioteca</x-ui.button>
                </div>
            </div>
        </template>

        <template x-if="state !== 'done'">
            <div>
                <label
                    x-on:dragover.prevent="dragging = true"
                    x-on:dragleave.prevent="dragging = false"
                    x-on:drop.prevent="dragging = false; start($event.dataTransfer.files[0])"
                    x-bind:class="dragging ? 'border-sky-400 bg-sky-500/10' : 'border-slate-700 bg-slate-900/60 hover:border-slate-500'"
                    x-bind:aria-disabled="busy"
                    class="flex cursor-pointer flex-col items-center gap-3 rounded-2xl border-2 border-dashed px-8 py-14 text-center transition"
                >
                    <input
                        type="file"
                        x-ref="input"
                        x-bind:disabled="busy"
                        x-on:change="start($event.target.files[0])"
                        accept="{{ $accept }}"
                        class="hidden"
                    />

                    <x-ui.icon name="arrow-up-tray" class="size-9 text-slate-400"/>

                    <div class="text-sm font-semibold text-slate-200">Arraste e solte seu vídeo aqui</div>
                    <div class="text-xs text-slate-500">ou clique para escolher</div>
                    <div class="text-xs text-slate-600">{{ $acceptedLabel }} até {{ $maxLabel }}</div>
                </label>

                <div x-show="busy" x-cloak class="mt-5">
                    <div class="mb-1.5 flex items-center justify-between text-xs text-slate-400">
                        <span x-text="state === 'finishing' ? 'Finalizando…' : 'Enviando…'"></span>
                        <span x-text="progress + '%'"></span>
                    </div>

                    <div class="h-2 w-full overflow-hidden rounded-full bg-slate-800">
                        <div class="h-full rounded-full bg-sky-500 transition-all duration-150"
                             x-bind:style="'width: ' + progress + '%'"></div>
                    </div>

                    <div class="mt-2 text-xs text-slate-600">O envio continua de onde parou se a conexão cair.</div>
                </div>

                <template x-if="error">
                    <div class="mt-4 rounded-lg border border-red-500/40 bg-red-500/10 px-4 py-3 text-sm text-red-300"
                         x-text="error"></div>
                </template>
            </div>
        </template>
    </div>

    <div class="mt-10">
        <div class="flex items-center gap-4">
            <div class="h-px flex-1 bg-slate-800"></div>
            <span class="text-xs font-medium uppercase tracking-wide text-slate-500">ou importe do YouTube</span>
            <div class="h-px flex-1 bg-slate-800"></div>
        </div>

        @if ($youtubePreview === null)
            <form wire:submit="fetchYoutubeMetadata" class="mt-5 flex items-start gap-3">
                <div class="flex-1">
                    <x-ui.input
                        wire:model="youtubeUrl"
                        type="url"
                        placeholder="https://www.youtube.com/watch?v=…"
                    />
                </div>

                <x-ui.button
                    type="submit"
                    wire:loading.attr="disabled"
                    wire:target="fetchYoutubeMetadata"
                >
                    <span wire:loading.remove wire:target="fetchYoutubeMetadata">Buscar</span>
                    <span wire:loading wire:target="fetchYoutubeMetadata">Buscando…</span>
                </x-ui.button>
            </form>
        @else
            <div class="mt-5 flex gap-4 rounded-2xl border border-slate-700 bg-slate-900/60 p-5">
                @if ($youtubePreview['thumbnailUrl'] !== null)
                    <img
                        src="{{ $youtubePreview['thumbnailUrl'] }}"
                        alt=""
                        class="aspect-video w-40 shrink-0 rounded-lg object-cover"
                    />
                @endif

                <div class="min-w-0 flex-1">
                    <div class="truncate text-sm font-semibold text-slate-200">{{ $youtubePreview['title'] }}</div>
                    <div class="mt-1 text-xs text-slate-500">{{ $youtubePreview['durationLabel'] }} · {{ $youtubePreview['resolutionLabel'] }}</div>

                    <div class="mt-4 flex items-center gap-3">
                        <x-ui.button
                            variant="primary"
                            wire:click="confirmYoutubeImport"
                            wire:loading.attr="disabled"
                            wire:target="confirmYoutubeImport"
                        >
                            <span wire:loading.remove wire:target="confirmYoutubeImport">Importar vídeo</span>
                            <span wire:loading wire:target="confirmYoutubeImport">Importando…</span>
                        </x-ui.button>

                        <x-ui.button
                            variant="subtle"
                            wire:click="cancelYoutubeImport"
                            wire:loading.attr="disabled"
                            wire:target="confirmYoutubeImport"
                        >
                            Cancelar
                        </x-ui.button>
                    </div>
                </div>
            </div>
        @endif
    </div>
</div>
