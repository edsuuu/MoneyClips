<section class="flex w-full flex-col gap-6">
    <div class="flex flex-wrap items-start justify-between gap-6">
        <div>
            <div class="mb-2 font-mono text-[11px] tracking-[0.1em] text-slate-500">MEUS VÍDEOS</div>
            <h1 class="text-3xl font-extrabold tracking-tight text-slate-50">Estoque e postagens</h1>
        </div>
        <div class="flex flex-wrap justify-end gap-2.5">
            <button type="button" wire:click="openInstant"
                class="flex cursor-pointer items-center gap-2 rounded-[10px] bg-sky-400 px-4 py-2.5 text-[13.5px] font-bold text-gray-950 transition hover:bg-sky-300">
                <x-ui.icon name="bolt" class="size-3.5" />
                Postagem instantânea
            </button>
            <button type="button" wire:click="openUpload"
                class="flex cursor-pointer items-center gap-2 rounded-[10px] border border-slate-700 bg-slate-800 px-4 py-2.5 text-[13.5px] font-semibold text-slate-100 transition hover:bg-slate-700">
                <x-ui.icon name="plus" class="size-3.5" />
                Novo download
            </button>
        </div>
    </div>

    <div class="flex flex-wrap gap-1.5 border-b border-slate-800">
        @foreach ($tabs as $meta)
            <button type="button" wire:click="setTab('{{ $meta['key'] }}')" wire:key="videos-tab-{{ $meta['key'] }}"
                @class([
                    '-mb-px flex cursor-pointer items-center gap-2 border-b-2 px-4 py-3 text-[14.5px] font-semibold transition',
                    'border-sky-400 text-slate-50' => $tab === $meta['key'],
                    'border-transparent text-slate-400 hover:text-slate-200' => $tab !== $meta['key'],
                ])>
                {{ $meta['label'] }}
                <span @class([
                    'rounded-full px-2 py-0.5 font-mono text-[11px] font-bold',
                    'bg-sky-400/15 text-sky-300' => $tab === $meta['key'],
                    'bg-slate-800 text-slate-300' => $tab !== $meta['key'],
                ])>{{ $meta['count'] }}</span>
            </button>
        @endforeach
    </div>

    @if ($tab === 'available')
        <div class="flex flex-col gap-8">
            <div>
                <div class="mb-3.5 flex items-baseline gap-2.5">
                    <span class="text-[15px] font-bold">Baixados</span>
                    <span class="text-[13px] text-slate-500">aguardando hashtags e revisão antes de entrar na fila</span>
                </div>
                @if ($downloaded === [])
                    <div class="rounded-xl border border-dashed border-slate-800 py-8 text-center text-[13px] text-slate-500">Nada aguardando revisão.</div>
                @else
                    <div class="grid gap-3.5 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                        @foreach ($downloaded as $video)
                            @include('livewire.videos.partials.video-card', ['video' => $video, 'section' => 'downloaded'])
                        @endforeach
                    </div>
                @endif
            </div>

            <div>
                <div class="mb-3.5 flex items-baseline gap-2.5">
                    <span class="text-[15px] font-bold">Prontos para postar</span>
                    <span class="text-[13px] text-slate-500">com hashtags definidas, prontos pra entrar na agenda</span>
                </div>
                @if ($ready === [])
                    <div class="rounded-xl border border-dashed border-slate-800 py-8 text-center text-[13px] text-slate-500">Nenhum vídeo pronto — revise os baixados acima.</div>
                @else
                    <div class="grid gap-3.5 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                        @foreach ($ready as $video)
                            @include('livewire.videos.partials.video-card', ['video' => $video, 'section' => 'ready'])
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endif

    @if ($tab === 'templated')
        <div>
            <div class="mb-4 text-[13px] text-slate-500">vídeos já renderizados com template, prontos para postar</div>
            @if ($templated === [])
                <div class="rounded-xl border border-dashed border-slate-800 py-10 text-center text-[13px] text-slate-500">
                    Nenhum vídeo com template.
                </div>
            @else
                <div class="grid gap-3.5 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                    @foreach ($templated as $video)
                        @include('livewire.videos.partials.video-card', ['video' => $video, 'section' => 'templated'])
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    @if ($tab === 'posted')
        <div>
            @if ($posted === [])
                <div class="rounded-xl border border-dashed border-slate-800 py-10 text-center text-[13px] text-slate-500">Nenhum vídeo postado ainda.</div>
            @else
                <div class="grid gap-3.5 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                    @foreach ($posted as $video)
                        @include('livewire.videos.partials.video-card', ['video' => $video, 'section' => 'posted'])
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    @if ($editingVideo)
        <x-ui.server-modal close="closeEdit">
            <div class="flex max-h-[46vh] w-full shrink-0 items-center justify-center bg-slate-950">
                @if ($editingUrl)
                    <video src="{{ $editingUrl }}" controls playsinline class="max-h-[46vh] w-full object-contain"></video>
                @else
                    <div class="flex aspect-video w-full items-center justify-center text-slate-600">
                        <x-ui.icon name="play" class="size-10" />
                    </div>
                @endif
            </div>
            <div class="flex flex-col gap-3.5 overflow-y-auto p-5">
                <div>
                    <div class="mb-1.5 font-mono text-[10.5px] tracking-[0.08em] text-slate-500">TÍTULO</div>
                    <input type="text" wire:model="editTitle"
                        class="w-full rounded-[9px] border border-slate-700 bg-slate-950 px-3 py-2 text-sm font-semibold text-slate-100 outline-none focus:border-sky-500" />
                </div>
                <div>
                    <div class="mb-1.5 font-mono text-[10.5px] tracking-[0.08em] text-slate-500">LEGENDA / HASHTAGS</div>
                    <input type="text" wire:model="editHashtags" placeholder="#shorts #podcast"
                        class="w-full rounded-[9px] border border-slate-700 bg-slate-950 px-3 py-2 font-mono text-[13px] text-sky-300 outline-none focus:border-sky-500" />
                </div>
                <div class="mt-1 flex justify-end gap-2">
                    <x-ui.button variant="outline" wire:click="closeEdit">Fechar</x-ui.button>
                    <x-ui.button wire:click="saveEdit">Salvar</x-ui.button>
                </div>
            </div>
        </x-ui.server-modal>
    @endif

    @if ($showInstant)
        <x-ui.server-modal close="closeInstant" title="Postagem instantânea" max-width="max-w-xl">
            <div class="flex flex-col gap-4 overflow-y-auto p-5">
                <div>
                    <div class="mb-2 font-mono text-[11px] tracking-[0.08em] text-slate-400">PLATAFORMAS</div>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($platforms as $platform)
                            <button type="button" wire:click="toggleInstantPlatform('{{ $platform['platform'] }}')" wire:key="instant-platform-{{ $platform['platform'] }}"
                                @class([
                                    'flex cursor-pointer items-center gap-2 rounded-full border px-3.5 py-1.5 text-xs font-semibold transition',
                                    'border-emerald-500/50 bg-emerald-950/30 text-emerald-300' => $platform['selected'],
                                    'border-slate-700 bg-slate-900 text-slate-400' => ! $platform['selected'],
                                ])>
                                <span @class([
                                    'size-1.5 rounded-full',
                                    'bg-emerald-400' => $platform['selected'],
                                    'bg-slate-600' => ! $platform['selected'],
                                ])></span>
                                {{ $platform['name'] }}
                            </button>
                        @endforeach
                    </div>
                </div>

                <div>
                    <div class="mb-2 font-mono text-[11px] tracking-[0.08em] text-slate-400">VÍDEO</div>
                    <div class="grid max-h-72 grid-cols-1 gap-2 overflow-y-auto pr-1">
                        @foreach ($instantCandidates as $candidate)
                            <button type="button" wire:click="selectInstantVideo({{ $candidate['id'] }})" wire:key="instant-candidate-{{ $candidate['id'] }}"
                                @class([
                                    'flex cursor-pointer items-center gap-3 rounded-[10px] border px-3.5 py-2.5 text-left transition',
                                    'border-emerald-500 bg-emerald-950/20' => $candidate['selected'],
                                    'border-slate-700 bg-slate-950/60 hover:border-sky-400' => ! $candidate['selected'],
                                ])>
                                <x-ui.icon name="play" class="size-3.5 shrink-0 text-slate-500" />
                                <span class="line-clamp-1 text-[13px] font-semibold">{{ $candidate['title'] }}</span>
                                @if ($candidate['selected'])
                                    <x-ui.icon name="check-circle" class="ml-auto size-4 shrink-0 text-emerald-400" />
                                @endif
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>

            <x-slot:footer>
                <x-ui.button variant="outline" wire:click="closeInstant">Cancelar</x-ui.button>
                <x-ui.button wire:click="confirmInstant" wire:confirm="Postar este vídeo AGORA nas plataformas selecionadas?">Postar agora</x-ui.button>
            </x-slot:footer>
        </x-ui.server-modal>
    @endif

    @if ($showUpload)
        <x-ui.server-modal close="closeUpload">
            <div class="flex flex-col gap-4 p-6">
                <div>
                    <div class="text-lg font-extrabold">Novo download</div>
                    <div class="mt-1 text-[13px] text-slate-500">Baixa os Shorts de um canal do YouTube pro estoque (via microserviço media).</div>
                </div>
                <div>
                    <div class="mb-1.5 text-[12.5px] font-bold">URL do canal</div>
                    <input type="url" wire:model="channelUrl" placeholder="https://www.youtube.com/@canal"
                        class="w-full rounded-[10px] border border-slate-700 bg-slate-950 px-3.5 py-2.5 text-sm text-slate-100 outline-none focus:border-sky-500" />
                    @error('channelUrl') <span class="mt-1 block text-xs text-red-400">{{ $message }}</span> @enderror
                </div>
                <div class="flex justify-end gap-2">
                    <x-ui.button variant="outline" wire:click="closeUpload">Cancelar</x-ui.button>
                    <x-ui.button wire:click="startDownload" wire:loading.attr="disabled">Baixar Shorts</x-ui.button>
                </div>
            </div>
        </x-ui.server-modal>
    @endif

</section>
