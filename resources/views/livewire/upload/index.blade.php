<div class="mx-auto flex min-h-[calc(100vh-8rem)] w-full max-w-2xl flex-col justify-center">
    <x-ui.heading size="xl">Enviar vídeo</x-ui.heading>
    <x-ui.subheading>Arraste um arquivo ou clique para escolher.</x-ui.subheading>

    <div
        class="mt-8"
        x-data="{ progress: 0, uploading: false, dragging: false }"
        x-on:livewire-upload-start="uploading = true; progress = 0"
        x-on:livewire-upload-progress="progress = $event.detail.progress"
        x-on:livewire-upload-finish="uploading = false; progress = 100"
        x-on:livewire-upload-error="uploading = false; progress = 0"
    >
        @if ($done)
            <div class="flex flex-col items-center gap-4 rounded-2xl border border-emerald-500/40 bg-emerald-500/10 px-8 py-12 text-center">
                <x-ui.icon name="check-circle" class="size-10 text-emerald-400" />
                <div class="text-lg font-semibold text-emerald-300">Upload concluído</div>

                <x-ui.button variant="subtle" wire:click="uploadAnother">
                    Enviar outro
                </x-ui.button>
            </div>
        @else
            <label
                x-on:dragover.prevent="dragging = true"
                x-on:dragleave.prevent="dragging = false"
                x-on:drop.prevent="dragging = false; $refs.input.files = $event.dataTransfer.files; $refs.input.dispatchEvent(new Event('change'))"
                :class="dragging ? 'border-sky-400 bg-sky-500/10' : 'border-slate-700 bg-slate-900/60 hover:border-slate-500'"
                class="flex cursor-pointer flex-col items-center gap-3 rounded-2xl border-2 border-dashed px-8 py-14 text-center transition"
            >
                <input type="file" wire:model="video" x-ref="input" accept="{{ $accept }}" class="hidden" />

                <x-ui.icon name="arrow-up-tray" class="size-9 text-slate-400" />

                <div class="text-sm font-semibold text-slate-200">Arraste e solte seu vídeo aqui</div>
                <div class="text-xs text-slate-500">ou clique para escolher</div>
                <div class="text-xs text-slate-600">{{ $acceptedLabel }} até {{ $maxLabel }}</div>
            </label>

            <div x-show="uploading" x-cloak class="mt-5">
                <div class="mb-1.5 flex items-center justify-between text-xs text-slate-400">
                    <span>Enviando…</span>
                    <span x-text="progress + '%'"></span>
                </div>

                <div class="h-2 w-full overflow-hidden rounded-full bg-slate-800">
                    <div class="h-full rounded-full bg-sky-500 transition-all duration-150" :style="'width: ' + progress + '%'"></div>
                </div>
            </div>

            @error('video')
                <div class="mt-4 rounded-lg border border-red-500/40 bg-red-500/10 px-4 py-3 text-sm text-red-300">
                    {{ $message }}
                </div>
            @enderror
        @endif
    </div>
</div>
