<div class="mx-auto flex min-h-[calc(100vh-8rem)] w-full max-w-2xl flex-col justify-center">
    <x-ui.heading size="xl">Enviar vídeo</x-ui.heading>
    <x-ui.subheading>Arraste um arquivo ou clique para escolher.</x-ui.subheading>

    <div
        class="mt-8"
        x-data="videoUploader({ maxBytes: @js($maxBytes), accepted: @js($accept) })"
    >
        <template x-if="state === 'done'">
            <div class="flex flex-col items-center gap-4 rounded-2xl border border-emerald-500/40 bg-emerald-500/10 px-8 py-12 text-center">
                <x-ui.icon name="check-circle" class="size-10 text-emerald-400" />
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

                    <x-ui.icon name="arrow-up-tray" class="size-9 text-slate-400" />

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
                        <div class="h-full rounded-full bg-sky-500 transition-all duration-150" x-bind:style="'width: ' + progress + '%'"></div>
                    </div>

                    <div class="mt-2 text-xs text-slate-600">O envio continua de onde parou se a conexão cair.</div>
                </div>

                <template x-if="error">
                    <div class="mt-4 rounded-lg border border-red-500/40 bg-red-500/10 px-4 py-3 text-sm text-red-300" x-text="error"></div>
                </template>
            </div>
        </template>
    </div>
</div>
