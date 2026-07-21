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
                <div class="flex flex-col gap-3 rounded-2xl border border-slate-800 bg-slate-900/60 p-5">
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
                            <x-ui.button variant="subtle" href="{{ route('uploads.show', $video['uuid']) }}" class="flex-1">
                                Assistir
                            </x-ui.button>
                        @endif

                        <x-ui.button
                            variant="ghost"
                            wire:click="delete('{{ $video['uuid'] }}')"
                            wire:confirm="Remover este vídeo e tudo que foi gerado a partir dele?"
                        >
                            <x-ui.icon name="trash" class="size-4" />
                        </x-ui.button>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-8">{{ $videos->links() }}</div>
    @endif
</div>
