<div @if ($hasPending) wire:poll.5s @endif>
    <div class="flex items-center justify-between">
        <div>
            <x-ui.heading size="xl">Biblioteca</x-ui.heading>
            <x-ui.subheading>Vídeos longos enviados, prontos para virar cortes.</x-ui.subheading>
        </div>

        <x-ui.button variant="primary" href="{{ route('upload.index') }}">Enviar vídeo</x-ui.button>
    </div>

    @if ($videos->isEmpty())
        <div class="mt-10 rounded-2xl border border-dashed border-slate-700 px-8 py-16 text-center">
            <x-ui.icon name="film" class="mx-auto size-9 text-slate-600" />
            <div class="mt-3 text-sm font-semibold text-slate-300">Nenhum vídeo por aqui ainda</div>
            <div class="mt-1 text-xs text-slate-500">Envie o primeiro para começar.</div>
        </div>
    @else
        <div class="mt-8 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($videos as $video)
                <div class="relative flex flex-col gap-3 rounded-2xl border border-slate-800 bg-slate-900/60 p-5" x-data="{ confirming: false }" wire:key="video-{{ $video['uuid'] }}">
                    <div
                        x-show="confirming"
                        x-cloak
                        class="absolute inset-0 z-10 flex flex-col items-center justify-center gap-2 rounded-2xl bg-black/75 p-5 text-center backdrop-blur-sm"
                    >
                        <div class="text-sm font-semibold text-white">Remover este vídeo?</div>
                        <div class="text-xs text-white/70">Apaga o vídeo e tudo gerado a partir dele.</div>
                        <div class="mt-1 flex items-center gap-2">
                            <button type="button" x-on:click="confirming = false" class="cursor-pointer rounded-lg border border-white/20 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-white/10">Cancelar</button>
                            <button type="button" wire:click="delete('{{ $video['uuid'] }}')" class="cursor-pointer rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-red-500">Remover</button>
                        </div>
                    </div>

                    <a
                        @if ($video['isReady']) href="{{ route('uploads.show', $video['uuid']) }}" @endif
                        class="group relative flex aspect-video items-center justify-center overflow-hidden rounded-xl bg-slate-950"
                    >
                        @if ($video['posterUrl'])
                            <img src="{{ $video['posterUrl'] }}" alt="" loading="lazy" class="size-full object-cover" />
                        @else
                            <x-ui.icon name="film" class="size-8 text-slate-700" />
                        @endif

                        @if ($video['isReady'])
                            <span class="absolute inset-0 flex items-center justify-center bg-black/40 opacity-0 transition group-hover:opacity-100">
                                <x-ui.icon name="play" class="size-10 text-white" />
                            </span>
                        @endif
                    </a>

                    <div class="flex items-center justify-between gap-2">
                        <span @class(['rounded-full px-2.5 py-1 text-xs font-semibold', $video['badgeClass'] => true])>
                            {{ $video['statusLabel'] }}
                        </span>
                        <span class="text-xs text-slate-600">{{ $video['createdLabel'] }}</span>
                    </div>

                    @if ($video['isPackaging'])
                        <div>
                            <div class="mb-1 flex items-center justify-between text-xs text-slate-500">
                                <span>Preparando reprodução</span>
                                <span>{{ $video['progress'] }}%</span>
                            </div>
                            <div class="h-1.5 w-full overflow-hidden rounded-full bg-slate-800">
                                <div class="h-full rounded-full bg-amber-500 transition-all" style="width: {{ $video['progress'] }}%"></div>
                            </div>
                        </div>
                    @endif

                    @if ($video['error'])
                        <div class="rounded-lg border border-red-500/30 bg-red-500/10 px-3 py-2 text-xs text-red-300">
                            {{ $video['error'] }}
                        </div>
                    @endif

                    <dl class="grid grid-cols-3 gap-2 text-xs">
                        <div>
                            <dt class="text-slate-600">Tamanho</dt>
                            <dd class="mt-0.5 font-semibold text-slate-300">{{ $video['sizeLabel'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-600">Duração</dt>
                            <dd class="mt-0.5 font-semibold text-slate-300">{{ $video['durationLabel'] }}</dd>
                        </div>
                        <div>
                            <dt class="text-slate-600">Qualidade</dt>
                            <dd class="mt-0.5 font-semibold text-slate-300">{{ $video['resolutionLabel'] }}</dd>
                        </div>
                    </dl>

                    <div class="mt-auto flex items-center gap-2">
                        @if ($video['isReady'])
                            <x-ui.button variant="primary" icon="play" href="{{ route('uploads.show', $video['uuid']) }}" class="flex-1">
                                Assistir
                            </x-ui.button>
                        @endif

                        <x-ui.button variant="ghost" x-on:click="confirming = true">
                            <x-ui.icon name="trash" class="size-4" />
                        </x-ui.button>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-8">{{ $videos->links() }}</div>
    @endif
</div>
